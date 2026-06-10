<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class RefundResult extends AbstractPaymentData
{
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_FAILED = 'failed';

    public const FIELD_STATUS = 'status';
    public const FIELD_REFUND_CODE = 'refund_code';
    public const FIELD_TRANSACTION_CODE = 'transaction_code';
    public const FIELD_PROVIDER_REFERENCE = 'provider_reference';
    public const FIELD_RETRYABLE = 'retryable';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_PAYLOAD = 'payload';

    public function getStatus(): string
    {
        return $this->getString(self::FIELD_STATUS, self::STATUS_PENDING);
    }

    public function getRefundCode(): ?string
    {
        return $this->getNullableString(self::FIELD_REFUND_CODE);
    }

    public function getTransactionCode(): ?string
    {
        return $this->getNullableString(self::FIELD_TRANSACTION_CODE);
    }

    public function getProviderReference(): ?string
    {
        return $this->getNullableString(self::FIELD_PROVIDER_REFERENCE);
    }

    public function isRetryable(): bool
    {
        return $this->getBool(self::FIELD_RETRYABLE);
    }
}
