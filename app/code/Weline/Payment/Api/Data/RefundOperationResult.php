<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

/**
 * Sanitized Payment refund state returned to the owning business module.
 */
final class RefundOperationResult extends AbstractPaymentData
{
    public const FIELD_REFUND_CODE = 'refund_code';
    public const FIELD_REFUND_CASE_UUID = 'refund_case_uuid';
    public const FIELD_STATUS = 'status';
    public const FIELD_CHANNEL_STATUS = 'channel_status';
    public const FIELD_AMOUNT_MINOR = 'amount_minor';
    public const FIELD_CURRENCY_CODE = 'currency_code';
    public const FIELD_PROVIDER_REFUND_ID = 'provider_refund_id';
    public const FIELD_ERROR_CODE = 'error_code';
    public const FIELD_REPLAYED = 'replayed';
    public const FIELD_LATE_SUCCESS_REVIEW = 'late_success_review';
    public const FIELD_PROVIDER_RESPONSE = 'provider_response';

    /**
     * @param array<string, mixed> $providerResponse
     */
    public static function create(
        ?string $refundCode,
        ?string $refundCaseUuid,
        string $status,
        string $channelStatus,
        int $amountMinor = 0,
        string $currencyCode = '',
        ?string $providerRefundId = null,
        ?string $errorCode = null,
        bool $replayed = false,
        bool $lateSuccessReview = false,
        array $providerResponse = [],
    ): self {
        return self::fromArray([
            self::FIELD_REFUND_CODE => $refundCode,
            self::FIELD_REFUND_CASE_UUID => $refundCaseUuid,
            self::FIELD_STATUS => $status,
            self::FIELD_CHANNEL_STATUS => $channelStatus,
            self::FIELD_AMOUNT_MINOR => $amountMinor,
            self::FIELD_CURRENCY_CODE => $currencyCode,
            self::FIELD_PROVIDER_REFUND_ID => $providerRefundId,
            self::FIELD_ERROR_CODE => $errorCode,
            self::FIELD_REPLAYED => $replayed,
            self::FIELD_LATE_SUCCESS_REVIEW => $lateSuccessReview,
            self::FIELD_PROVIDER_RESPONSE => $providerResponse,
        ]);
    }

    public function isOk(): bool
    {
        return $this->getErrorCode() === null;
    }

    public function getRefundCode(): ?string
    {
        return $this->getNullableString(self::FIELD_REFUND_CODE);
    }

    public function getRefundCaseUuid(): ?string
    {
        return $this->getNullableString(self::FIELD_REFUND_CASE_UUID);
    }

    public function getStatus(): string
    {
        return $this->getString(self::FIELD_STATUS);
    }

    public function getChannelStatus(): string
    {
        return $this->getString(self::FIELD_CHANNEL_STATUS);
    }

    public function getAmountMinor(): int
    {
        return $this->getInt(self::FIELD_AMOUNT_MINOR);
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->getString(self::FIELD_CURRENCY_CODE));
    }

    public function getProviderRefundId(): ?string
    {
        return $this->getNullableString(self::FIELD_PROVIDER_REFUND_ID);
    }

    public function getErrorCode(): ?string
    {
        return $this->getNullableString(self::FIELD_ERROR_CODE);
    }

    public function isReplayed(): bool
    {
        return $this->getBool(self::FIELD_REPLAYED);
    }

    public function isLateSuccessReview(): bool
    {
        return $this->getBool(self::FIELD_LATE_SUCCESS_REVIEW);
    }

    /**
     * @return array<string, mixed>
     */
    public function getProviderResponse(): array
    {
        return $this->getArray(self::FIELD_PROVIDER_RESPONSE);
    }
}
