<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data\Tracking;

final class TrackingQueryRequest extends AbstractTrackingData
{
    public const FIELD_ORDER_ID = 'order_id';
    public const FIELD_ORDER_NUMBER = 'order_number';
    public const FIELD_ORDER_UUID = 'order_uuid';
    public const FIELD_SHIPMENT_ID = 'shipment_id';
    public const FIELD_TRACKING_NUMBER = 'tracking_number';
    public const FIELD_CARRIER = 'carrier';
    public const FIELD_ORDER_STATUS = 'order_status';
    public const FIELD_FULFILLMENT_STATUS = 'fulfillment_status';
    public const FIELD_SHIPPED_AT = 'shipped_at';
    public const FIELD_DESTINATION_SUMMARY = 'destination_summary';
    public const FIELD_FORCE_REFRESH = 'force_refresh';
    public const FIELD_CONTEXT = 'context';

    public function getOrderId(): int
    {
        return $this->getInt(self::FIELD_ORDER_ID);
    }

    public function getOrderNumber(): string
    {
        return $this->getString(self::FIELD_ORDER_NUMBER);
    }

    public function getOrderUuid(): string
    {
        return $this->getString(self::FIELD_ORDER_UUID);
    }

    public function getShipmentId(): int
    {
        return $this->getInt(self::FIELD_SHIPMENT_ID);
    }

    public function getTrackingNumber(): string
    {
        return $this->getString(self::FIELD_TRACKING_NUMBER);
    }

    public function getCarrier(): string
    {
        return $this->getString(self::FIELD_CARRIER);
    }

    public function getOrderStatus(): string
    {
        return $this->getString(self::FIELD_ORDER_STATUS);
    }

    public function getFulfillmentStatus(): string
    {
        return $this->getString(self::FIELD_FULFILLMENT_STATUS);
    }

    public function getShippedAt(): ?string
    {
        return $this->getNullableString(self::FIELD_SHIPPED_AT);
    }

    public function getDestinationSummary(): string
    {
        return $this->getString(self::FIELD_DESTINATION_SUMMARY);
    }

    public function isForceRefresh(): bool
    {
        return $this->getBool(self::FIELD_FORCE_REFRESH);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->getArray(self::FIELD_CONTEXT);
    }
}
