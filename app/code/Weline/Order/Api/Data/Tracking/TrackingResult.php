<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data\Tracking;

final class TrackingResult extends AbstractTrackingData
{
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXCEPTION = 'exception';

    public const FIELD_STATUS = 'status';
    public const FIELD_PROVIDER_CODE = 'provider_code';
    public const FIELD_METHOD_CODE = 'method_code';
    public const FIELD_CURRENT_STAGE_CODE = 'current_stage_code';
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_TRACKING_NUMBER = 'tracking_number';
    public const FIELD_EXTERNAL_URL = 'external_url';
    public const FIELD_STAGES = 'stages';
    public const FIELD_NODES = 'nodes';
    public const FIELD_DISPLAY = 'display';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_RETRYABLE = 'retryable';
    public const FIELD_PAYLOAD = 'payload';

    public function getStatus(): string
    {
        return $this->getString(self::FIELD_STATUS, self::STATUS_PENDING);
    }

    public function getProviderCode(): string
    {
        return $this->getString(self::FIELD_PROVIDER_CODE);
    }

    public function getMethodCode(): string
    {
        return $this->getString(self::FIELD_METHOD_CODE);
    }

    public function getCurrentStageCode(): string
    {
        return $this->getString(self::FIELD_CURRENT_STAGE_CODE);
    }

    public function getSummary(): string
    {
        return $this->getString(self::FIELD_SUMMARY);
    }

    public function getTrackingNumber(): string
    {
        return $this->getString(self::FIELD_TRACKING_NUMBER);
    }

    public function getExternalUrl(): ?string
    {
        return $this->getNullableString(self::FIELD_EXTERNAL_URL);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getStages(): array
    {
        $stages = $this->getArray(self::FIELD_STAGES);

        return array_values($stages);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getNodes(): array
    {
        $nodes = $this->getArray(self::FIELD_NODES);

        return array_values($nodes);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDisplay(): array
    {
        return $this->getArray(self::FIELD_DISPLAY);
    }

    public function getMessage(): string
    {
        return $this->getString(self::FIELD_MESSAGE);
    }

    public function isRetryable(): bool
    {
        return $this->getBool(self::FIELD_RETRYABLE);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->getArray(self::FIELD_PAYLOAD);
    }
}
