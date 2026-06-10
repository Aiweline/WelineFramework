<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentLedger;
use Weline\Payment\Model\PaymentRefund;
use Weline\Payment\Model\PaymentTransaction;

class PaymentLedgerService
{
    public function __construct(
        private readonly ?ObjectManager $objectManager = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createEntry(array $data): PaymentLedger
    {
        $direction = $this->normalizeDirection((string) ($data['direction'] ?? ''));
        $precision = $this->normalizePrecision($data['precision'] ?? 2);
        $currency = $this->normalizeCurrency((string) ($data['currency'] ?? $data['currency_code'] ?? ''));
        $amountMinor = $this->normalizeAmountMinor($data['amount_minor'] ?? null);
        $debitMinor = $this->normalizeAmountMinor($data['debit_minor'] ?? null);
        $creditMinor = $this->normalizeAmountMinor($data['credit_minor'] ?? null);

        if ($amountMinor > 0) {
            if ($direction === PaymentLedger::DIRECTION_DEBIT) {
                $debitMinor = $amountMinor;
                $creditMinor = 0;
            } else {
                $creditMinor = $amountMinor;
                $debitMinor = 0;
            }
        }

        if ($debitMinor <= 0 && $creditMinor <= 0) {
            throw new \InvalidArgumentException('payment_ledger_amount_required');
        }
        if ($direction === PaymentLedger::DIRECTION_DEBIT && $debitMinor <= 0) {
            throw new \InvalidArgumentException('payment_ledger_debit_amount_required');
        }
        if ($direction === PaymentLedger::DIRECTION_CREDIT && $creditMinor <= 0) {
            throw new \InvalidArgumentException('payment_ledger_credit_amount_required');
        }

        $now = date('Y-m-d H:i:s');
        $ledger = $this->newLedger();
        $ledger->setData(PaymentLedger::schema_fields_LEDGER_CODE, (string) ($data['ledger_code'] ?? $this->generateLedgerCode()))
            ->setData(PaymentLedger::schema_fields_LEDGER_TYPE, (string) ($data['ledger_type'] ?? PaymentLedger::TYPE_PAYMENT))
            ->setData(PaymentLedger::schema_fields_DIRECTION, $direction)
            ->setData(PaymentLedger::schema_fields_DEBIT, $this->minorToDecimal($debitMinor, $precision))
            ->setData(PaymentLedger::schema_fields_CREDIT, $this->minorToDecimal($creditMinor, $precision))
            ->setData(PaymentLedger::schema_fields_DEBIT_MINOR, $debitMinor)
            ->setData(PaymentLedger::schema_fields_CREDIT_MINOR, $creditMinor)
            ->setData(PaymentLedger::schema_fields_CURRENCY, $currency)
            ->setData(PaymentLedger::schema_fields_PRECISION, $precision)
            ->setData(PaymentLedger::schema_fields_TRANSACTION_CODE, (string) ($data['transaction_code'] ?? ''))
            ->setData(PaymentLedger::schema_fields_LINKED_TRANSACTION_ID, $this->nullableInt($data['linked_transaction_id'] ?? null))
            ->setData(PaymentLedger::schema_fields_INTENT_CODE, (string) ($data['intent_code'] ?? ''))
            ->setData(PaymentLedger::schema_fields_ATTEMPT_CODE, (string) ($data['attempt_code'] ?? ''))
            ->setData(PaymentLedger::schema_fields_LINKED_ATTEMPT_ID, $this->nullableInt($data['linked_attempt_id'] ?? null))
            ->setData(PaymentLedger::schema_fields_REFUND_CODE, (string) ($data['refund_code'] ?? ''))
            ->setData(PaymentLedger::schema_fields_METHOD_CODE, (string) ($data['method_code'] ?? ''))
            ->setData(PaymentLedger::schema_fields_PROVIDER_CODE, (string) ($data['provider_code'] ?? ''))
            ->setData(PaymentLedger::schema_fields_MERCHANT_ACCOUNT, (string) ($data['merchant_account'] ?? ''))
            ->setData(PaymentLedger::schema_fields_PAYABLE_TYPE, (string) ($data['payable_type'] ?? ''))
            ->setData(PaymentLedger::schema_fields_PAYABLE_ID, (string) ($data['payable_id'] ?? ''))
            ->setData(PaymentLedger::schema_fields_CREATED_AT, $now)
            ->setMetadata(\is_array($data['metadata'] ?? null) ? $data['metadata'] : [])
            ->save();

        return $ledger;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordRefund(PaymentRefund $refund, PaymentTransaction $transaction, array $metadata = []): PaymentLedger
    {
        return $this->createEntry([
            'ledger_type' => PaymentLedger::TYPE_REFUND,
            'direction' => PaymentLedger::DIRECTION_CREDIT,
            'amount_minor' => (int) $refund->getData(PaymentRefund::schema_fields_APPROVED_AMOUNT_MINOR),
            'currency' => (string) $refund->getData(PaymentRefund::schema_fields_CURRENCY),
            'precision' => (int) $refund->getData(PaymentRefund::schema_fields_PRECISION),
            'transaction_code' => (string) $refund->getData(PaymentRefund::schema_fields_TRANSACTION_CODE),
            'linked_transaction_id' => (int) $transaction->getData(PaymentTransaction::schema_fields_ID),
            'intent_code' => (string) $refund->getData(PaymentRefund::schema_fields_INTENT_CODE),
            'attempt_code' => (string) $refund->getData(PaymentRefund::schema_fields_ATTEMPT_CODE),
            'linked_attempt_id' => $refund->getData(PaymentRefund::schema_fields_LINKED_ATTEMPT_ID),
            'refund_code' => (string) $refund->getData(PaymentRefund::schema_fields_REFUND_CODE),
            'method_code' => (string) $refund->getData(PaymentRefund::schema_fields_METHOD_CODE),
            'provider_code' => (string) $refund->getData(PaymentRefund::schema_fields_PROVIDER_CODE),
            'merchant_account' => (string) $refund->getData(PaymentRefund::schema_fields_MERCHANT_ACCOUNT),
            'payable_type' => (string) $refund->getData(PaymentRefund::schema_fields_PAYABLE_TYPE),
            'payable_id' => (string) $refund->getData(PaymentRefund::schema_fields_PAYABLE_ID),
            'metadata' => array_replace([
                'provider_refund_id' => (string) $refund->getData(PaymentRefund::schema_fields_PROVIDER_REFUND_ID),
            ], $metadata),
        ]);
    }

    /**
     * @return PaymentLedger[]
     */
    public function getEntriesByCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return [];
        }

        $collection = $this->newLedger()
            ->where(PaymentLedger::schema_fields_LEDGER_CODE, $code)
            ->orWhere(PaymentLedger::schema_fields_TRANSACTION_CODE, $code)
            ->orWhere(PaymentLedger::schema_fields_REFUND_CODE, $code)
            ->orWhere(PaymentLedger::schema_fields_INTENT_CODE, $code)
            ->orWhere(PaymentLedger::schema_fields_ATTEMPT_CODE, $code)
            ->order(PaymentLedger::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch();

        return $this->collectionItems($collection, PaymentLedger::class);
    }

    /**
     * @return array{debit_minor: int, credit_minor: int, balance_minor: int}
     */
    public function getTotalsByTransactionCode(string $transactionCode): array
    {
        $debitMinor = 0;
        $creditMinor = 0;
        foreach ($this->getEntriesByCode($transactionCode) as $entry) {
            $debitMinor += (int) $entry->getData(PaymentLedger::schema_fields_DEBIT_MINOR);
            $creditMinor += (int) $entry->getData(PaymentLedger::schema_fields_CREDIT_MINOR);
        }

        return [
            'debit_minor' => $debitMinor,
            'credit_minor' => $creditMinor,
            'balance_minor' => $debitMinor - $creditMinor,
        ];
    }

    public function minorToDecimal(int $amountMinor, int $precision): string
    {
        $precision = $this->normalizePrecision($precision);
        $sign = $amountMinor < 0 ? '-' : '';
        $amountMinor = abs($amountMinor);
        $factor = 10 ** $precision;
        if ($precision === 0) {
            return $sign . (string) $amountMinor;
        }

        return sprintf('%s%d.%0' . $precision . 'd', $sign, intdiv($amountMinor, $factor), $amountMinor % $factor);
    }

    private function newLedger(): PaymentLedger
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(PaymentLedger::class);
    }

    private function generateLedgerCode(): string
    {
        return 'ledger_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    }

    private function normalizeDirection(string $direction): string
    {
        $direction = strtolower(trim($direction));
        if (!\in_array($direction, [PaymentLedger::DIRECTION_DEBIT, PaymentLedger::DIRECTION_CREDIT], true)) {
            throw new \InvalidArgumentException('payment_ledger_direction_invalid:' . $direction);
        }

        return $direction;
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('payment_ledger_currency_invalid');
        }

        return $currency;
    }

    private function normalizePrecision(mixed $precision): int
    {
        $precision = (int) $precision;
        if ($precision < 0 || $precision > 8) {
            throw new \InvalidArgumentException('payment_money_precision_invalid');
        }

        return $precision;
    }

    private function normalizeAmountMinor(mixed $amountMinor): int
    {
        if ($amountMinor === null || $amountMinor === '') {
            return 0;
        }
        if (\is_float($amountMinor)) {
            throw new \InvalidArgumentException('payment_amount_minor_must_be_integer');
        }
        if (\is_int($amountMinor)) {
            return $amountMinor;
        }
        if (\is_string($amountMinor) && preg_match('/^-?\d+$/', $amountMinor)) {
            return (int) $amountMinor;
        }

        throw new \InvalidArgumentException('payment_amount_minor_must_be_integer');
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T[]
     */
    private function collectionItems(mixed $collection, string $className): array
    {
        if (\is_object($collection) && method_exists($collection, 'getItems')) {
            $collection = $collection->getItems();
        }
        if (!\is_array($collection)) {
            return [];
        }

        return array_values(array_filter($collection, static fn (mixed $item): bool => $item instanceof $className));
    }
}
