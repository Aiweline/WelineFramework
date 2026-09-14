<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Model\PaymentTransaction;

/**
 * Express/壳支付以 PaymentTransaction 为事实源；退款与后台记录读 Intent/Attempt。
 * 捕获成功后补写只读 reader，不调用 Provider、不写 business outbox。
 */
final class PaymentCaptureReaderEnsureService
{
    public const CANONICAL_PAYABLE_TYPE = 'order';

    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    public function ensureFromTransaction(PaymentTransaction $transaction): ?PaymentIntent
    {
        $mapped = self::mapFromTransactionArray([
            PaymentTransaction::schema_fields_TRANSACTION_NO =>
                (string)$transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO),
            PaymentTransaction::schema_fields_ORDER_ID =>
                (string)$transaction->getData(PaymentTransaction::schema_fields_ORDER_ID),
            PaymentTransaction::schema_fields_METHOD_CODE =>
                (string)$transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE),
            PaymentTransaction::schema_fields_AMOUNT =>
                (string)$transaction->getData(PaymentTransaction::schema_fields_AMOUNT),
            PaymentTransaction::schema_fields_CURRENCY =>
                (string)$transaction->getData(PaymentTransaction::schema_fields_CURRENCY),
            PaymentTransaction::schema_fields_STATUS =>
                (string)$transaction->getData(PaymentTransaction::schema_fields_STATUS),
            PaymentTransaction::schema_fields_SCOPE =>
                (string)$transaction->getData(PaymentTransaction::schema_fields_SCOPE),
            PaymentTransaction::schema_fields_PAID_AT =>
                (string)$transaction->getData(PaymentTransaction::schema_fields_PAID_AT),
            'request_data' => $transaction->getRequestData(),
            'response_data' => $transaction->getResponseData(),
        ]);
        if ($mapped === null) {
            return null;
        }

        $existing = $this->loadIntentByCode((string)$mapped['intent'][PaymentIntent::schema_fields_INTENT_CODE]);
        if ($existing instanceof PaymentIntent) {
            $this->ensureAttempt($mapped);

            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        try {
            $intent = $this->newIntent();
            $intent->setData(array_replace($mapped['intent'], [
                PaymentIntent::schema_fields_CREATED_AT => $now,
                PaymentIntent::schema_fields_UPDATED_AT => $now,
            ]))->save();
        } catch (\Throwable) {
            $intent = $this->loadIntentByCode((string)$mapped['intent'][PaymentIntent::schema_fields_INTENT_CODE]);
            if (!$intent instanceof PaymentIntent) {
                return null;
            }
        }
        $this->ensureAttempt($mapped);

        $reloaded = $this->loadIntentByCode((string)$mapped['intent'][PaymentIntent::schema_fields_INTENT_CODE]);

        return $reloaded instanceof PaymentIntent ? $reloaded : $intent;
    }

    public function ensureFromPayable(string $payableType, string $payableId): ?PaymentIntent
    {
        $payableId = trim($payableId);
        if ($payableId === '') {
            return null;
        }
        $transaction = $this->objectManager->getInstance(PaymentTransaction::class, [], false);
        $transaction->where(PaymentTransaction::schema_fields_ORDER_ID, $payableId)
            ->where(PaymentTransaction::schema_fields_STATUS, [
                PaymentTransaction::STATUS_SUCCESS,
                PaymentTransaction::STATUS_REFUNDED,
            ], 'IN')
            ->order(PaymentTransaction::schema_fields_ID, 'DESC')
            ->limit(1);
        $row = $transaction->find()->fetch();
        if (!$row instanceof PaymentTransaction || !$row->getId()) {
            return null;
        }

        return $this->ensureFromTransaction($row);
    }

    /**
     * @param array<string, mixed> $tx
     * @return array{intent:array<string,mixed>,attempt:array<string,mixed>}|null
     */
    public static function mapFromTransactionArray(array $tx): ?array
    {
        $status = strtolower(trim((string)($tx[PaymentTransaction::schema_fields_STATUS] ?? $tx['status'] ?? '')));
        if (!\in_array($status, [
            PaymentTransaction::STATUS_SUCCESS,
            PaymentTransaction::STATUS_REFUNDED,
        ], true)) {
            return null;
        }
        $transactionNo = trim((string)($tx[PaymentTransaction::schema_fields_TRANSACTION_NO]
            ?? $tx['transaction_no'] ?? ''));
        if ($transactionNo === '') {
            return null;
        }
        $request = self::asArray($tx['request_data'] ?? null);
        $response = self::asArray($tx['response_data'] ?? null);
        $payableId = trim((string)(
            $request['payable_id']
            ?? $request['order_id']
            ?? $tx[PaymentTransaction::schema_fields_ORDER_ID]
            ?? $tx['order_id']
            ?? ''
        ));
        if ($payableId === '') {
            return null;
        }
        $amountMinor = self::amountMinor($tx, $request);
        if ($amountMinor <= 0) {
            return null;
        }
        $currency = strtoupper(trim((string)(
            $request['currency_code']
            ?? $request['currency']
            ?? $tx[PaymentTransaction::schema_fields_CURRENCY]
            ?? $tx['currency']
            ?? ''
        )));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return null;
        }
        $intentCode = trim((string)($response['intent_code'] ?? $transactionNo));
        $attemptCode = trim((string)($response['attempt_code'] ?? ($transactionNo . '-1')));
        $methodCode = strtolower(trim((string)(
            $request['method_code']
            ?? $tx[PaymentTransaction::schema_fields_METHOD_CODE]
            ?? $tx['method_code']
            ?? ''
        )));
        if ($methodCode === '') {
            $methodCode = 'paypal';
        }
        $providerCode = strtolower(trim((string)(
            $response['provider_code']
            ?? $request['provider_code']
            ?? $methodCode
        )));
        $environment = strtolower(trim((string)(
            $request['environment'] ?? $tx['environment'] ?? 'sandbox'
        ))) ?: 'sandbox';
        $scope = trim((string)(
            $tx[PaymentTransaction::schema_fields_SCOPE]
            ?? $tx['scope']
            ?? $request['scope']
            ?? 'default.default.default'
        ));
        $captureId = self::captureId($response);
        $providerReference = $captureId !== ''
            ? $captureId
            : trim((string)($response['provider_reference'] ?? ''));
        $intentStatus = $status === PaymentTransaction::STATUS_REFUNDED
            ? PaymentIntent::STATUS_REFUNDED
            : PaymentIntent::STATUS_PAID;

        $intent = [
            PaymentIntent::schema_fields_INTENT_CODE => $intentCode,
            PaymentIntent::schema_fields_ENVIRONMENT => $environment,
            PaymentIntent::schema_fields_PAYABLE_TYPE => self::CANONICAL_PAYABLE_TYPE,
            PaymentIntent::schema_fields_PAYABLE_ID => $payableId,
            PaymentIntent::schema_fields_METHOD_CODE => $methodCode,
            PaymentIntent::schema_fields_PROVIDER_CODE => $providerCode,
            PaymentIntent::schema_fields_MERCHANT_ACCOUNT => null,
            PaymentIntent::schema_fields_SCOPE => $scope !== '' ? $scope : 'default.default.default',
            PaymentIntent::schema_fields_AMOUNT_MINOR => $amountMinor,
            PaymentIntent::schema_fields_CURRENCY_CODE => $currency,
            PaymentIntent::schema_fields_PRECISION => 2,
            PaymentIntent::schema_fields_STATUS => $intentStatus,
            PaymentIntent::schema_fields_ACTIVE_FLAG => 0,
            PaymentIntent::schema_fields_ACTIVE_GUARD => null,
            PaymentIntent::schema_fields_IDEMPOTENCY_KEY => 'capture_reader:' . $transactionNo,
        ];
        $guard = $providerReference !== ''
            ? hash('sha256', implode('|', [
                $environment,
                $providerCode,
                '',
                $providerReference,
            ]))
            : null;
        $attempt = [
            PaymentAttempt::schema_fields_ATTEMPT_CODE => $attemptCode,
            PaymentAttempt::schema_fields_INTENT_CODE => $intentCode,
            PaymentAttempt::schema_fields_ENVIRONMENT => $environment,
            PaymentAttempt::schema_fields_PAYABLE_TYPE => self::CANONICAL_PAYABLE_TYPE,
            PaymentAttempt::schema_fields_PAYABLE_ID => $payableId,
            PaymentAttempt::schema_fields_METHOD_CODE => $methodCode,
            PaymentAttempt::schema_fields_PROVIDER_CODE => $providerCode,
            PaymentAttempt::schema_fields_MERCHANT_ACCOUNT => null,
            PaymentAttempt::schema_fields_SCOPE => $scope !== '' ? $scope : 'default.default.default',
            PaymentAttempt::schema_fields_PAYMENT_CURRENCY_CODE => $currency,
            PaymentAttempt::schema_fields_AMOUNT_MINOR => $amountMinor,
            PaymentAttempt::schema_fields_PRECISION => 2,
            PaymentAttempt::schema_fields_STATUS => PaymentAttempt::STATUS_SUCCEEDED,
            PaymentAttempt::schema_fields_NONTERMINAL_GUARD => null,
            PaymentAttempt::schema_fields_VERSION => 0,
            PaymentAttempt::schema_fields_CAS_TOKEN => '',
            PaymentAttempt::schema_fields_IDEMPOTENCY_KEY => 'capture_reader:' . $attemptCode,
            PaymentAttempt::schema_fields_PROVIDER_REFERENCE => $providerReference !== '' ? $providerReference : null,
            PaymentAttempt::schema_fields_PROVIDER_REFERENCE_GUARD => $guard,
            PaymentAttempt::schema_fields_RESPONSE_SNAPSHOT => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];

        return ['intent' => $intent, 'attempt' => $attempt];
    }

    /**
     * @return list<string>
     */
    public static function payableTypeAliases(string $payableType): array
    {
        $type = strtolower(trim($payableType));
        if ($type === '') {
            return [self::CANONICAL_PAYABLE_TYPE, 'weline_order'];
        }
        $aliases = [$type];
        if ($type === self::CANONICAL_PAYABLE_TYPE) {
            $aliases[] = 'weline_order';
        }
        if ($type === 'weline_order') {
            $aliases[] = self::CANONICAL_PAYABLE_TYPE;
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @param array<string, mixed> $mapped
     */
    private function ensureAttempt(array $mapped): void
    {
        $attemptCode = (string)$mapped['attempt'][PaymentAttempt::schema_fields_ATTEMPT_CODE];
        $existing = $this->loadAttemptByCode($attemptCode);
        if ($existing instanceof PaymentAttempt) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        try {
            $this->newAttempt()->setData(array_replace($mapped['attempt'], [
                PaymentAttempt::schema_fields_CREATED_AT => $now,
                PaymentAttempt::schema_fields_STARTED_AT => $now,
            ]))->save();
        } catch (\Throwable) {
            // Unique race: reader already exists.
        }
    }

    private function loadIntentByCode(string $intentCode): ?PaymentIntent
    {
        $intentCode = trim($intentCode);
        if ($intentCode === '') {
            return null;
        }
        $model = $this->newIntent()
            ->where(PaymentIntent::schema_fields_INTENT_CODE, $intentCode)
            ->find()
            ->fetch();

        return $model instanceof PaymentIntent && $model->getId() ? $model : null;
    }

    private function loadAttemptByCode(string $attemptCode): ?PaymentAttempt
    {
        $attemptCode = trim($attemptCode);
        if ($attemptCode === '') {
            return null;
        }
        $model = $this->newAttempt()
            ->where(PaymentAttempt::schema_fields_ATTEMPT_CODE, $attemptCode)
            ->find()
            ->fetch();

        return $model instanceof PaymentAttempt && $model->getId() ? $model : null;
    }

    private function newIntent(): PaymentIntent
    {
        return $this->objectManager->getInstance(PaymentIntent::class, [], false);
    }

    private function newAttempt(): PaymentAttempt
    {
        return $this->objectManager->getInstance(PaymentAttempt::class, [], false);
    }

    /**
     * @param array<string, mixed> $tx
     * @param array<string, mixed> $request
     */
    private static function amountMinor(array $tx, array $request): int
    {
        if (isset($request['amount_minor']) && is_numeric($request['amount_minor'])) {
            return (int)$request['amount_minor'];
        }
        $amount = trim((string)($tx[PaymentTransaction::schema_fields_AMOUNT] ?? $tx['amount'] ?? ''));
        if ($amount === '' || !preg_match('/^\d+(?:\.\d+)?$/', $amount)) {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ((int)$whole * 100) + (int)$fraction;
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function captureId(array $response): string
    {
        $payload = self::asArray($response['payload'] ?? null);
        $direct = trim((string)($payload['capture_id'] ?? $response['provider_reference'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }
        $capture = self::asArray($payload['capture'] ?? $response['capture'] ?? null);
        foreach ((array)($capture['purchase_units'] ?? []) as $unit) {
            if (!\is_array($unit)) {
                continue;
            }
            foreach ((array)($unit['payments']['captures'] ?? []) as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $id = trim((string)($row['id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (!\is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
