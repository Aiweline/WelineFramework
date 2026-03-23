<?php

declare(strict_types=1);

namespace WeShop\Logistics\Service;

use WeShop\Logistics\Model\Tracking;

class TrackingAdminPageDataService
{
    public function __construct(
        private readonly TrackingService $trackingService
    ) {
    }

    public function getPageData(int $page = 1, int $pageSize = 20, array $filters = [], int $editingId = 0): array
    {
        $sanitizedFilters = $this->sanitizeFilters($filters);
        $result = $this->trackingService->getTrackingList($page, $pageSize, $sanitizedFilters);
        $editingRecord = $editingId ? $this->trackingService->getTrackingRecord($editingId) : null;

        return [
            'trackingRecords' => array_map(fn (array $record): array => $this->normalizeRecord($record), $result['items'] ?? []),
            'filters' => $sanitizedFilters,
            'pagination' => $result['pagination'] ?? [],
            'carrierOptions' => $this->trackingService->getCarrierOptions(),
            'statusOptions' => $this->trackingService->getStatusOptions(),
            'editingRecord' => $editingRecord ? $this->normalizeModel($editingRecord) : $this->getEmptyRecord(),
        ];
    }

    private function sanitizeFilters(array $filters): array
    {
        $sanitized = [];

        if (!empty($filters['order_id'])) {
            $sanitized['order_id'] = (int) $filters['order_id'];
        }

        if (!empty($filters['tracking_number'])) {
            $sanitized['tracking_number'] = trim((string) $filters['tracking_number']);
        }

        if (!empty($filters['carrier'])) {
            $sanitized['carrier'] = trim((string) $filters['carrier']);
        }

        if (!empty($filters['status']) && $this->trackingService->isValidStatus((string) $filters['status'])) {
            $sanitized['status'] = (string) $filters['status'];
        }

        return $sanitized;
    }

    private function normalizeModel(Tracking $tracking): array
    {
        return [
            'tracking_id' => (int) $tracking->getId(),
            'order_id' => (int) $tracking->getData(Tracking::schema_fields_order_id),
            'tracking_number' => (string) $tracking->getData(Tracking::schema_fields_tracking_number),
            'carrier' => (string) $tracking->getData(Tracking::schema_fields_carrier),
            'status' => (string) $tracking->getData(Tracking::schema_fields_status),
            'location' => (string) $tracking->getData(Tracking::schema_fields_location),
            'description' => (string) $tracking->getData(Tracking::schema_fields_description),
            'tracked_at' => (string) $tracking->getData(Tracking::schema_fields_tracked_at),
            'status_label' => $this->trackingService->getStatusOptions()[(string) $tracking->getData(Tracking::schema_fields_status)] ?? (string) $tracking->getData(Tracking::schema_fields_status),
        ];
    }

    private function normalizeRecord(array $record): array
    {
        $status = (string) ($record[Tracking::schema_fields_status] ?? $record['status'] ?? 'pending');

        return [
            'tracking_id' => (int) ($record[Tracking::schema_fields_ID] ?? $record['tracking_id'] ?? 0),
            'order_id' => (int) ($record[Tracking::schema_fields_order_id] ?? $record['order_id'] ?? 0),
            'tracking_number' => (string) ($record[Tracking::schema_fields_tracking_number] ?? $record['tracking_number'] ?? ''),
            'carrier' => (string) ($record[Tracking::schema_fields_carrier] ?? $record['carrier'] ?? ''),
            'status' => $status,
            'status_label' => $this->trackingService->getStatusOptions()[$status] ?? $status,
            'location' => (string) ($record[Tracking::schema_fields_location] ?? $record['location'] ?? ''),
            'description' => (string) ($record[Tracking::schema_fields_description] ?? $record['description'] ?? ''),
            'tracked_at' => (string) ($record[Tracking::schema_fields_tracked_at] ?? $record['tracked_at'] ?? ''),
        ];
    }

    private function getEmptyRecord(): array
    {
        return [
            'tracking_id' => 0,
            'order_id' => 0,
            'tracking_number' => '',
            'carrier' => 'DHL',
            'status' => 'pending',
            'location' => '',
            'description' => '',
            'tracked_at' => '',
            'status_label' => $this->trackingService->getStatusOptions()['pending'] ?? 'Pending',
        ];
    }
}
