<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

use Throwable;

final class ProviderError extends AbstractPaymentData
{
    public const FIELD_CODE = 'code';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_RETRYABLE = 'retryable';
    public const FIELD_USER_VISIBLE = 'user_visible';
    public const FIELD_PROVIDER_ERROR_CODE = 'provider_error_code';
    public const FIELD_DETAILS = 'details';

    public static function fromThrowable(Throwable $error): self
    {
        return new self([
            self::FIELD_CODE => 'provider_exception',
            self::FIELD_MESSAGE => $error->getMessage(),
            self::FIELD_RETRYABLE => false,
            self::FIELD_USER_VISIBLE => false,
            self::FIELD_PROVIDER_ERROR_CODE => (string) $error->getCode(),
        ]);
    }

    public function getCode(): string
    {
        return $this->getString(self::FIELD_CODE);
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
        return $this->getBool(self::FIELD_USER_VISIBLE);
    }

    public function getProviderErrorCode(): ?string
    {
        return $this->getNullableString(self::FIELD_PROVIDER_ERROR_CODE);
    }
}
