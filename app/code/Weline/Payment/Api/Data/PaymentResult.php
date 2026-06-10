<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class PaymentResult extends AbstractPaymentData
{
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_REQUIRES_ACTION = 'requires_action';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';

    public const FIELD_STATUS = 'status';
    public const FIELD_ACTION_TYPE = 'action_type';
    public const FIELD_INTENT_CODE = 'intent_code';
    public const FIELD_ATTEMPT_CODE = 'attempt_code';
    public const FIELD_PROVIDER_REFERENCE = 'provider_reference';
    public const FIELD_RETRYABLE = 'retryable';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_PAYLOAD = 'payload';

    public function getStatus(): string
    {
        return $this->getString(self::FIELD_STATUS, self::STATUS_PENDING);
    }

    public function isSuccessful(): bool
    {
        return \in_array($this->getStatus(), [self::STATUS_AUTHORIZED, self::STATUS_CAPTURED, self::STATUS_PAID], true);
    }

    public function isRetryable(): bool
    {
        return $this->getBool(self::FIELD_RETRYABLE);
    }

    public function getActionType(): ?string
    {
        return $this->getNullableString(self::FIELD_ACTION_TYPE);
    }

    public function getIntentCode(): ?string
    {
        return $this->getNullableString(self::FIELD_INTENT_CODE);
    }

    public function getAttemptCode(): ?string
    {
        return $this->getNullableString(self::FIELD_ATTEMPT_CODE);
    }

    public function getProviderReference(): ?string
    {
        return $this->getNullableString(self::FIELD_PROVIDER_REFERENCE);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->getArray(self::FIELD_PAYLOAD);
    }
}
