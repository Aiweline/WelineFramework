<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data\Tracking;

final class TrackingFeedbackResult extends AbstractTrackingData
{
    public const FIELD_VALID = 'valid';
    public const FIELD_EVENT_ID = 'event_id';
    public const FIELD_EVENT_TYPE = 'event_type';
    public const FIELD_ORDER_NUMBER = 'order_number';
    public const FIELD_TRACKING_NUMBER = 'tracking_number';
    public const FIELD_SUGGESTED_STATUS = 'suggested_status';
    public const FIELD_STAGE_CODE = 'stage_code';
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_NODES = 'nodes';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_PAYLOAD = 'payload';

    public function isValid(): bool
    {
        return $this->getBool(self::FIELD_VALID);
    }

    public function getEventId(): string
    {
        return $this->getString(self::FIELD_EVENT_ID);
    }

    public function getEventType(): string
    {
        return $this->getString(self::FIELD_EVENT_TYPE);
    }

    public function getOrderNumber(): string
    {
        return $this->getString(self::FIELD_ORDER_NUMBER);
    }

    public function getTrackingNumber(): string
    {
        return $this->getString(self::FIELD_TRACKING_NUMBER);
    }

    public function getSuggestedStatus(): string
    {
        return $this->getString(self::FIELD_SUGGESTED_STATUS);
    }

    public function getStageCode(): string
    {
        return $this->getString(self::FIELD_STAGE_CODE);
    }

    public function getSummary(): string
    {
        return $this->getString(self::FIELD_SUMMARY);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getNodes(): array
    {
        return array_values($this->getArray(self::FIELD_NODES));
    }

    public function getMessage(): string
    {
        return $this->getString(self::FIELD_MESSAGE);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->getArray(self::FIELD_PAYLOAD);
    }
}
