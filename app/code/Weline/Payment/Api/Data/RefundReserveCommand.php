<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

/**
 * Internal commerce refund reservation command.
 *
 * The owning business module must recompute amount/currency from frozen
 * snapshots before constructing this command. Payment independently checks
 * the captured amount while holding the Payment row lock.
 */
final class RefundReserveCommand extends AbstractPaymentData
{
    public const FIELD_REFUND_CASE_UUID = 'refund_case_uuid';
    public const FIELD_PAYABLE_TYPE = 'payable_type';
    public const FIELD_PAYABLE_ID = 'payable_id';
    public const FIELD_IDEMPOTENCY_KEY = 'idempotency_key';
    public const FIELD_REQUEST_HASH = 'request_hash';
    public const FIELD_AMOUNT_MINOR = 'amount_minor';
    public const FIELD_CURRENCY_CODE = 'currency_code';
    public const FIELD_REASON = 'reason';
    public const FIELD_CONTEXT = 'context';

    /**
     * @param array<string, mixed> $context
     */
    public static function create(
        string $refundCaseUuid,
        string $payableType,
        string $payableId,
        string $idempotencyKey,
        string $requestHash,
        int $amountMinor,
        string $currencyCode,
        string $reason = '',
        array $context = [],
    ): self {
        return self::fromArray([
            self::FIELD_REFUND_CASE_UUID => $refundCaseUuid,
            self::FIELD_PAYABLE_TYPE => $payableType,
            self::FIELD_PAYABLE_ID => $payableId,
            self::FIELD_IDEMPOTENCY_KEY => $idempotencyKey,
            self::FIELD_REQUEST_HASH => $requestHash,
            self::FIELD_AMOUNT_MINOR => $amountMinor,
            self::FIELD_CURRENCY_CODE => $currencyCode,
            self::FIELD_REASON => $reason,
            self::FIELD_CONTEXT => $context,
        ]);
    }

    public function getRefundCaseUuid(): string
    {
        return trim($this->getString(self::FIELD_REFUND_CASE_UUID));
    }

    public function getPayableType(): string
    {
        return strtolower(trim($this->getString(self::FIELD_PAYABLE_TYPE)));
    }

    public function getPayableId(): string
    {
        return trim($this->getString(self::FIELD_PAYABLE_ID));
    }

    public function getIdempotencyKey(): string
    {
        return trim($this->getString(self::FIELD_IDEMPOTENCY_KEY));
    }

    public function getRequestHash(): string
    {
        return strtolower(trim($this->getString(self::FIELD_REQUEST_HASH)));
    }

    public function getAmountMinor(): int
    {
        return $this->getInt(self::FIELD_AMOUNT_MINOR);
    }

    public function getCurrencyCode(): string
    {
        return strtoupper(trim($this->getString(self::FIELD_CURRENCY_CODE)));
    }

    public function getReason(): string
    {
        return trim($this->getString(self::FIELD_REASON));
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->getArray(self::FIELD_CONTEXT);
    }
}
