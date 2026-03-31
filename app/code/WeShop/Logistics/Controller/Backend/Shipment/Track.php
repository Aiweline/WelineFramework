<?php

declare(strict_types=1);

namespace WeShop\Logistics\Controller\Backend\Shipment;

use WeShop\Logistics\Service\TrackingService;
use Weline\Admin\Controller\BaseController;

class Track extends BaseController
{
    public function __construct(
        private readonly TrackingService $trackingService
    ) {
    }

    public function index(): string
    {
        $shipmentId = (int) $this->request->getParam('id', 0);
        if ($shipmentId <= 0) {
            $this->getMessageManager()->addError(__('Shipment ID is required.'));
            $this->redirect('*/backend/shipment');
            return '';
        }

        try {
            $tracking = $this->trackingService->getTrackingRecord($shipmentId);
            if ($tracking === null) {
                throw new \InvalidArgumentException((string) __('Shipment not found.'));
            }

            $shipmentData = $this->normalizeShipmentData($tracking);
            $trackingRecords = $this->trackingService->getOrderTracking($shipmentData['order_id']);
            $timeline = $this->buildTimeline($trackingRecords);

            $this->assign([
                'title' => (string) __('Shipment Tracking'),
                'shipment' => $shipmentData,
                'trackingRecords' => $trackingRecords,
                'timeline' => $timeline,
                'statusOptions' => $this->trackingService->getStatusOptions(),
            ]);
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Failed to load shipment tracking.'));
            $this->redirect('*/backend/shipment');
            return '';
        }

        return $this->fetchBase('WeShop_Logistics::Backend/Shipment/Track/index.phtml');
    }

    private function normalizeShipmentData($tracking): array
    {
        return [
            'shipment_id' => (int) $tracking->getId(),
            'order_id' => (int) $tracking->getData('order_id'),
            'tracking_number' => (string) $tracking->getData('tracking_number'),
            'carrier' => (string) $tracking->getData('carrier'),
            'status' => (string) $tracking->getData('status'),
            'location' => (string) $tracking->getData('location'),
            'description' => (string) $tracking->getData('description'),
            'shipped_at' => (string) $tracking->getData('created_at'),
            'tracked_at' => (string) $tracking->getData('tracked_at'),
            'estimated_delivery' => $this->calculateEstimatedDelivery($tracking->getData('created_at')),
            'delivered_at' => $this->calculateDeliveredAt($tracking),
        ];
    }

    private function calculateEstimatedDelivery(string $shippedAt): string
    {
        if (empty($shippedAt)) {
            return '-';
        }
        try {
            $shipped = new \DateTime($shippedAt);
            $estimated = $shipped->modify('+5 days');
            return $estimated->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return '-';
        }
    }

    private function calculateDeliveredAt($tracking): string
    {
        $status = (string) $tracking->getData('status');
        if ($status === 'delivered') {
            return (string) $tracking->getData('tracked_at');
        }
        return '-';
    }

    private function buildTimeline(array $trackingRecords): array
    {
        $timeline = [];
        $statusLabels = $this->trackingService->getStatusOptions();

        foreach ($trackingRecords as $record) {
            $status = (string) ($record['status'] ?? 'pending');
            $timeline[] = [
                'status' => $status,
                'title' => $statusLabels[$status] ?? ucfirst($status),
                'description' => (string) ($record['description'] ?? ''),
                'location' => (string) ($record['location'] ?? ''),
                'timestamp' => (string) ($record['tracked_at'] ?? $record['created_at'] ?? ''),
            ];
        }

        return $timeline;
    }
}
