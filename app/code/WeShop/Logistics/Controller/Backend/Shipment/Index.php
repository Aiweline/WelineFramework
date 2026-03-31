<?php

declare(strict_types=1);

namespace WeShop\Logistics\Controller\Backend\Shipment;

use WeShop\Logistics\Service\TrackingService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly TrackingService $trackingService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $filters = [
            'order_id' => $this->request->getParam('order_id', ''),
            'tracking_number' => $this->request->getParam('tracking_number', ''),
            'carrier' => $this->request->getParam('carrier', ''),
            'status' => $this->request->getParam('status', ''),
        ];

        $result = $this->trackingService->getTrackingList($page, $pageSize, $filters);

        $this->assign([
            'title' => (string) __('Shipment Management'),
            'shipments' => $result['items'] ?? [],
            'filters' => $filters,
            'pagination' => $result['pagination'] ?? [],
            'carrierOptions' => $this->trackingService->getCarrierOptions(),
            'statusOptions' => $this->trackingService->getStatusOptions(),
            'summary' => $this->getShipmentSummary($result['items'] ?? []),
        ]);

        return $this->fetchBase('WeShop_Logistics::Backend/Shipment/Index/index.phtml');
    }

    private function getShipmentSummary(array $items): array
    {
        $summary = [
            'total' => count($items),
            'pending' => 0,
            'shipped' => 0,
            'delivered' => 0,
        ];

        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? 'pending');
            if ($status === 'pending' || $status === 'picked_up') {
                $summary['pending']++;
            } elseif ($status === 'in_transit' || $status === 'out_for_delivery') {
                $summary['shipped']++;
            } elseif ($status === 'delivered') {
                $summary['delivered']++;
            }
        }

        return $summary;
    }
}
