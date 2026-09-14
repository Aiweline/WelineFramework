<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentCheckoutSession;
use Weline\Payment\Model\PaymentTransaction;

/**
 * Persists and reads browser landing context on PaymentCheckoutSession.
 */
final class PaymentCheckoutSessionPersistenceService
{
    public const CONTEXT_BROWSER_LANDING_URL = 'browser_landing_url';
    public const CONTEXT_BROWSER_LANDING_PARAMS = 'browser_landing_params';
    public const CONTEXT_BROWSER_LANDING_READY = 'browser_landing_ready';
    public const CONTEXT_TRANSACTION_NO = 'transaction_no';
    public const CONTEXT_SHIPPING_METHOD_CODE = 'shipping_method_code';
    public const CONTEXT_SHIPPING_METHOD_LABEL = 'shipping_method_label';
    public const CONTEXT_DISCOUNT_LINES = 'discount_lines';

    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @param array<string, mixed> $orderData
     */
    public function persistFromPaymentContext(
        array $orderData,
        string $transactionNo,
        string $methodCode,
        array $scope,
    ): ?PaymentCheckoutSession {
        $landingUrl = trim((string) ($orderData['browser_landing_url'] ?? ''));
        if ($landingUrl === '') {
            return null;
        }

        $checkoutSessionCode = trim((string) ($orderData['checkout_session_code'] ?? ''));
        if ($checkoutSessionCode === '') {
            $checkoutSessionCode = 'pcs-' . sha1($transactionNo . ':' . $methodCode);
        }

        /** @var PaymentCheckoutSession $session */
        $session = $this->objectManager->getInstance(PaymentCheckoutSession::class);
        $session->load(PaymentCheckoutSession::schema_fields_CHECKOUT_SESSION_CODE, $checkoutSessionCode);
        if (!$session->getId()) {
            $session->clearData();
            $session->setData(PaymentCheckoutSession::schema_fields_CHECKOUT_SESSION_CODE, $checkoutSessionCode);
        }

        $payableType = strtolower(trim((string) ($orderData['payable_type'] ?? 'order')));
        $payableId = trim((string) ($orderData['payable_id'] ?? $orderData['order_id'] ?? ''));
        $currency = strtoupper((string) ($orderData['currency'] ?? $orderData['currency_code'] ?? 'CNY'));
        $amountMinor = (int) ($orderData['amount_minor'] ?? round(((float) ($orderData['amount'] ?? 0)) * 100));

        $shippingSnapshot = \is_array($orderData['shipping_snapshot'] ?? null)
            ? $orderData['shipping_snapshot']
            : (\is_array($orderData['shipping'] ?? null) ? $orderData['shipping'] : []);
        $shippingMethodCode = trim((string) (
            $orderData['shipping_method_code']
            ?? $shippingSnapshot['method']
            ?? $shippingSnapshot['service_code']
            ?? ''
        ));
        $shippingMethodLabel = trim((string) ($orderData['shipping_method_label'] ?? ''));
        $discountLines = $this->normalizeDiscountLines($orderData);
        $amountSnapshot = $this->buildAmountSnapshot($orderData, $currency, $amountMinor, $discountLines);

        $snapshot = array_replace($session->getContextSnapshot(), [
            self::CONTEXT_BROWSER_LANDING_URL => $landingUrl,
            self::CONTEXT_BROWSER_LANDING_PARAMS => \is_array($orderData['browser_landing_params'] ?? null)
                ? $orderData['browser_landing_params']
                : [],
            self::CONTEXT_BROWSER_LANDING_READY => false,
            self::CONTEXT_TRANSACTION_NO => $transactionNo,
            self::CONTEXT_SHIPPING_METHOD_CODE => $shippingMethodCode,
            self::CONTEXT_SHIPPING_METHOD_LABEL => $shippingMethodLabel,
            self::CONTEXT_DISCOUNT_LINES => $discountLines,
        ]);

        $session
            ->setData(PaymentCheckoutSession::schema_fields_ENVIRONMENT, (string) ($scope['environment'] ?? 'sandbox'))
            ->setData(PaymentCheckoutSession::schema_fields_PAYABLE_TYPE, $payableType !== '' ? $payableType : 'order')
            ->setData(PaymentCheckoutSession::schema_fields_PAYABLE_ID, $payableId)
            ->setData(PaymentCheckoutSession::schema_fields_SCOPE, (string) ($scope['scope'] ?? PaymentScopeConfigService::DEFAULT_SCOPE))
            ->setData(PaymentCheckoutSession::schema_fields_CURRENCY_CODE, $currency)
            ->setData(PaymentCheckoutSession::schema_fields_AMOUNT_MINOR, $amountMinor)
            ->setData(PaymentCheckoutSession::schema_fields_SELECTED_METHOD_CODE, strtolower(trim($methodCode)))
            ->setData(PaymentCheckoutSession::schema_fields_ACTIVE_INTENT_CODE, $transactionNo)
            ->setData(PaymentCheckoutSession::schema_fields_STATUS, PaymentCheckoutSession::STATUS_ACTIVE)
            ->setContextSnapshot($snapshot)
            ->setAmountSnapshot($amountSnapshot)
            ->setData(
                PaymentCheckoutSession::schema_fields_EXPIRES_AT,
                date('Y-m-d H:i:s', time() + 3600),
            )
            ->save();

        return $session;
    }

    public function loadByCheckoutSessionCode(string $checkoutSessionCode): ?PaymentCheckoutSession
    {
        $checkoutSessionCode = trim($checkoutSessionCode);
        if ($checkoutSessionCode === '') {
            return null;
        }

        /** @var PaymentCheckoutSession $session */
        $session = $this->objectManager->getInstance(PaymentCheckoutSession::class);
        $session->load(PaymentCheckoutSession::schema_fields_CHECKOUT_SESSION_CODE, $checkoutSessionCode);

        return $session->getId() ? $session : null;
    }

    public function loadByTransaction(PaymentTransaction $transaction): ?PaymentCheckoutSession
    {
        $transactionNo = trim((string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO));
        if ($transactionNo === '') {
            return null;
        }

        /** @var PaymentCheckoutSession $session */
        $session = $this->objectManager->getInstance(PaymentCheckoutSession::class);
        $session->clear()
            ->where(PaymentCheckoutSession::schema_fields_ACTIVE_INTENT_CODE, $transactionNo)
            ->order(PaymentCheckoutSession::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        return $session->getId() ? $session : null;
    }

    /**
     * Update express/browser landing after payment intent exists (must include transaction_no).
     *
     * @param array<string, mixed> $landingParams
     */
    public function updateBrowserLanding(
        string $transactionNo,
        string $landingUrl,
        array $landingParams = [],
    ): ?PaymentCheckoutSession {
        $transactionNo = trim($transactionNo);
        $landingUrl = trim($landingUrl);
        if ($transactionNo === '' || $landingUrl === '') {
            return null;
        }

        $session = $this->loadByTransactionNo($transactionNo);
        if ($session === null) {
            /** @var PaymentTransaction $txn */
            $txn = $this->objectManager->getInstance(PaymentTransaction::class);
            $txn->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo);
            if (!$txn->getId()) {
                return null;
            }
            $request = $txn->getRequestData();
            if (!is_array($request)) {
                $request = [];
            }
            $scope = [
                'scope' => (string) ($txn->getData('scope') ?? PaymentScopeConfigService::DEFAULT_SCOPE),
                'environment' => (string) ($request['environment'] ?? 'sandbox'),
            ];
            $session = $this->persistFromPaymentContext(
                array_replace($request, [
                    'browser_landing_url' => $landingUrl,
                    'browser_landing_params' => $landingParams,
                    'order_id' => (string) $txn->getData(PaymentTransaction::schema_fields_ORDER_ID),
                ]),
                $transactionNo,
                (string) $txn->getData(PaymentTransaction::schema_fields_METHOD_CODE),
                $scope,
            );
            if ($session === null) {
                return null;
            }
        }

        $params = $landingParams;
        $params[self::CONTEXT_TRANSACTION_NO] = $transactionNo;
        $snapshot = array_replace($session->getContextSnapshot(), [
            self::CONTEXT_BROWSER_LANDING_URL => $landingUrl,
            self::CONTEXT_BROWSER_LANDING_PARAMS => $params,
            self::CONTEXT_TRANSACTION_NO => $transactionNo,
        ]);
        $session
            ->setData(PaymentCheckoutSession::schema_fields_ACTIVE_INTENT_CODE, $transactionNo)
            ->setContextSnapshot($snapshot)
            ->save();

        // Keep unpaid express request_data landing in sync for callback consumers.
        try {
            /** @var PaymentTransaction $txn */
            $txn = $this->objectManager->getInstance(PaymentTransaction::class);
            $txn->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo);
            if ($txn->getId()) {
                $request = $txn->getRequestData();
                if (!is_array($request)) {
                    $request = [];
                }
                $request['browser_landing_url'] = $landingUrl;
                $request['browser_landing_params'] = $params;
                $txn->setRequestData($request)->save();
            }
        } catch (\Throwable) {
        }

        return $session;
    }

    public function loadByTransactionNo(string $transactionNo): ?PaymentCheckoutSession
    {
        $transactionNo = trim($transactionNo);
        if ($transactionNo === '') {
            return null;
        }

        /** @var PaymentCheckoutSession $session */
        $session = $this->objectManager->getInstance(PaymentCheckoutSession::class);
        $session->clear()
            ->where(PaymentCheckoutSession::schema_fields_ACTIVE_INTENT_CODE, $transactionNo)
            ->order(PaymentCheckoutSession::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        return $session->getId() ? $session : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function landingSnapshot(PaymentCheckoutSession $session): array
    {
        $snapshot = $session->getContextSnapshot();

        return [
            'browser_landing_url' => trim((string) ($snapshot[self::CONTEXT_BROWSER_LANDING_URL] ?? '')),
            'browser_landing_params' => \is_array($snapshot[self::CONTEXT_BROWSER_LANDING_PARAMS] ?? null)
                ? $snapshot[self::CONTEXT_BROWSER_LANDING_PARAMS]
                : [],
            'browser_landing_ready' => (bool) ($snapshot[self::CONTEXT_BROWSER_LANDING_READY] ?? false),
            'checkout_session_code' => (string) $session->getData(PaymentCheckoutSession::schema_fields_CHECKOUT_SESSION_CODE),
        ];
    }

    /**
     * @param array<string, mixed> $orderData
     * @param list<array{key?:string,label?:string,amount_minor?:int}> $discountLines
     * @return array<string, mixed>
     */
    private function buildAmountSnapshot(
        array $orderData,
        string $currency,
        int $fallbackGrandTotalMinor,
        array $discountLines,
    ): array {
        $totals = \is_array($orderData['totals'] ?? null) ? $orderData['totals'] : [];
        $snapshot = [
            'currency' => strtoupper((string) ($totals['currency'] ?? $currency)),
            'subtotal_minor' => (int) ($totals['subtotal_minor'] ?? 0),
            'shipping_amount_minor' => (int) ($totals['shipping_amount_minor'] ?? 0),
            'tax_amount_minor' => (int) ($totals['tax_amount_minor'] ?? 0),
            'discount_amount_minor' => (int) ($totals['discount_amount_minor'] ?? 0),
            'grand_total_minor' => (int) ($totals['grand_total_minor'] ?? $fallbackGrandTotalMinor),
            'discount_lines' => $discountLines,
        ];

        if ($snapshot['subtotal_minor'] <= 0 && $snapshot['grand_total_minor'] > 0) {
            unset($snapshot['subtotal_minor']);
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $orderData
     * @return list<array{key:string,label:string,amount_minor:int}>
     */
    private function normalizeDiscountLines(array $orderData): array
    {
        $raw = $orderData['discount_lines'] ?? null;
        if (!\is_array($raw) || $raw === []) {
            $totals = \is_array($orderData['totals'] ?? null) ? $orderData['totals'] : [];
            $discountMinor = (int) ($totals['discount_amount_minor'] ?? 0);
            if ($discountMinor <= 0) {
                return [];
            }
            $couponCode = strtoupper(trim((string) ($orderData['coupon_code'] ?? '')));

            return [[
                'key' => 'discount',
                'label' => $couponCode !== ''
                    ? (string) __('优惠券 (%1)', [$couponCode])
                    : (string) __('优惠'),
                'amount_minor' => -1 * $discountMinor,
            ]];
        }

        $lines = [];
        foreach ($raw as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $amountMinor = (int) ($line['amount_minor'] ?? 0);
            if ($amountMinor === 0) {
                continue;
            }
            $lines[] = [
                'key' => (string) ($line['key'] ?? 'discount'),
                'label' => (string) ($line['label'] ?? __('优惠')),
                'amount_minor' => $amountMinor,
            ];
        }

        return $lines;
    }
}
