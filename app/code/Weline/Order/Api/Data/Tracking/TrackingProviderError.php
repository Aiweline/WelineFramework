<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data\Tracking;

final class TrackingProviderError extends AbstractTrackingData
{
    public const FIELD_CODE = 'code';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_RETRYABLE = 'retryable';
    public const FIELD_USER_VISIBLE = 'user_visible';
    public const FIELD_PROVIDER_ERROR_CODE = 'provider_error_code';

    public function getCode(): string
    {
        return $this->getString(self::FIELD_CODE, 'tracking_provider_error');
    }

    public function getMessage(): string
    {
        return $this->getString(self::FIELD_MESSAGE);
    }

    public function isRetryable(): bool
    {
        return $this->getBool(self::FIELD_RETRYABLE);
    }

    public function isUserVisible(): bool
    {
        return $this->getBool(self::FIELD_USER_VISIBLE, true);
    }

    public function getProviderErrorCode(): ?string
    {
        return $this->getNullableString(self::FIELD_PROVIDER_ERROR_CODE);
    }
}
