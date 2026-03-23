<?php

declare(strict_types=1);

namespace WeShop\Logistics\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Logistics\Model\Tracking;

class TrackingService
{
    public function getCarrierOptions(): array
    {
        return [
            'DHL' => 'DHL',
            'FedEx' => 'FedEx',
            'UPS' => 'UPS',
            'EMS' => 'EMS',
            'Local Pickup' => 'Local Pickup',
            'Other' => 'Other',
        ];
    }

    public function getStatusOptions(): array
    {
        return [
            'pending' => (string) __('Pending'),
            'picked_up' => (string) __('Picked Up'),
            'in_transit' => (string) __('In Transit'),
            'out_for_delivery' => (string) __('Out for Delivery'),
            'delivered' => (string) __('Delivered'),
            'exception' => (string) __('Exception'),
        ];
    }

    public function isValidStatus(string $status): bool
    {
        return isset($this->getStatusOptions()[$status]);
    }

    public function addTracking(int $orderId, string $trackingNumber, string $carrier, array $trackingData = []): Tracking
    {
        return $this->saveTracking([
            'order_id' => $orderId,
            'tracking_number' => $trackingNumber,
            'carrier' => $carrier,
            'status' => $trackingData['status'] ?? 'pending',
            'location' => $trackingData['location'] ?? '',
            'description' => $trackingData['description'] ?? '',
            'tracked_at' => $trackingData['tracked_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function saveTracking(array $data): Tracking
    {
        $orderId = (int) ($data['order_id'] ?? 0);
        $trackingNumber = trim((string) ($data['tracking_number'] ?? ''));
        $carrier = trim((string) ($data['carrier'] ?? ''));
        $status = trim((string) ($data['status'] ?? 'pending'));

        if (!$orderId) {
            throw new \InvalidArgumentException((string) __('Order ID is required.'));
        }

        if ($trackingNumber === '') {
            throw new \InvalidArgumentException((string) __('Tracking number is required.'));
        }

        if ($carrier === '') {
            throw new \InvalidArgumentException((string) __('Carrier is required.'));
        }

        if (!$this->isValidStatus($status)) {
            throw new \InvalidArgumentException((string) __('Unsupported tracking status.'));
        }

        /** @var Tracking $tracking */
        $tracking = ObjectManager::getInstance(Tracking::class);

        $trackingId = (int) ($data['tracking_id'] ?? 0);
        if ($trackingId) {
            $tracking->load($trackingId);
        }

        $tracking->setData([
            Tracking::schema_fields_order_id => $orderId,
            Tracking::schema_fields_tracking_number => $trackingNumber,
            Tracking::schema_fields_carrier => $carrier,
            Tracking::schema_fields_status => $status,
            Tracking::schema_fields_location => trim((string) ($data['location'] ?? '')),
            Tracking::schema_fields_description => trim((string) ($data['description'] ?? '')),
            Tracking::schema_fields_tracked_at => trim((string) ($data['tracked_at'] ?? '')) ?: date('Y-m-d H:i:s'),
            Tracking::schema_fields_updated_at => date('Y-m-d H:i:s'),
        ]);

        if (!$tracking->getId()) {
            $tracking->setData(Tracking::schema_fields_created_at, date('Y-m-d H:i:s'));
        }

        $tracking->save();

        return $tracking;
    }

    public function getOrderTracking(int $orderId): array
    {
        /** @var Tracking $tracking */
        $tracking = ObjectManager::getInstance(Tracking::class);

        return $tracking->clear()
            ->where(Tracking::schema_fields_order_id, $orderId)
            ->order(Tracking::schema_fields_tracked_at, 'DESC')
            ->select()
            ->fetchArray();
    }

    public function getTrackingRecord(int $trackingId): ?Tracking
    {
        /** @var Tracking $tracking */
        $tracking = ObjectManager::getInstance(Tracking::class);
        $tracking->load($trackingId);

        return $tracking->getId() ? $tracking : null;
    }

    public function getTrackingList(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        /** @var Tracking $tracking */
        $tracking = ObjectManager::getInstance(Tracking::class);

        $tracking->clear();

        if (!empty($filters['order_id'])) {
            $tracking->where(Tracking::schema_fields_order_id, (int) $filters['order_id']);
        }

        if (!empty($filters['tracking_number'])) {
            $tracking->where(Tracking::schema_fields_tracking_number, '%' . $filters['tracking_number'] . '%', 'LIKE');
        }

        if (!empty($filters['carrier'])) {
            $tracking->where(Tracking::schema_fields_carrier, (string) $filters['carrier']);
        }

        if (!empty($filters['status']) && $this->isValidStatus((string) $filters['status'])) {
            $tracking->where(Tracking::schema_fields_status, (string) $filters['status']);
        }

        $tracking->order(Tracking::schema_fields_tracked_at, 'DESC')
            ->pagination($page, $pageSize);

        return [
            'items' => $tracking->select()->fetchArray(),
            'total' => $tracking->getTotalCount(),
            'pagination' => $tracking->getPagination(),
        ];
    }

    public function updateTrackingStatus(int $trackingId, string $status, string $location = '', string $description = ''): Tracking
    {
        /** @var Tracking $tracking */
        $tracking = ObjectManager::getInstance(Tracking::class);
        $tracking->load($trackingId);

        if ($tracking->getId()) {
            if (!$this->isValidStatus($status)) {
                throw new \InvalidArgumentException((string) __('Unsupported tracking status.'));
            }

            $tracking->setData(Tracking::schema_fields_status, $status)
                ->setData(Tracking::schema_fields_location, $location)
                ->setData(Tracking::schema_fields_description, $description)
                ->setData(Tracking::schema_fields_tracked_at, date('Y-m-d H:i:s'))
                ->setData(Tracking::schema_fields_updated_at, date('Y-m-d H:i:s'))
                ->save();
        }

        return $tracking;
    }
}
