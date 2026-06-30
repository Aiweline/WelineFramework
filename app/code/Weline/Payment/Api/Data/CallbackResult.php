<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class CallbackResult extends AbstractPaymentData
{
    public const FIELD_VERIFIED = 'verified';
    public const FIELD_EVENT_TYPE = 'event_type';
    public const FIELD_PROVIDER_EVENT_ID = 'provider_event_id';
    public const FIELD_INTENT_CODE = 'intent_code';
    public const FIELD_TRANSACTION_CODE = 'transaction_code';
    public const FIELD_REFUND_CODE = 'refund_code';
    public const FIELD_STATUS_TRANSITION = 'status_transition';
    public const FIELD_REPLAY_DETECTED = 'replay_detected';
    public const FIELD_MESSAGE = 'message';

    public function isVerified(): bool
    {
        return $this->getBool(self::FIELD_VERIFIED);
    }

    public function getEventType(): string
    {
        return $this->getString(self::FIELD_EVENT_TYPE);
    }

    public function getProviderEventId(): ?string
    {
        return $this->getNullableString(self::FIELD_PROVIDER_EVENT_ID);
    }

    public function getIntentCode(): ?string
    {
        return $this->getNullableString(self::FIELD_INTENT_CODE);
    }

    public function isReplayDetected(): bool
    {
        return $this->getBool(self::FIELD_REPLAY_DETECTED);
    }
}
