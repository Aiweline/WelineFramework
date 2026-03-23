<?php

declare(strict_types=1);

namespace WeShop\Logistics\Controller\Backend\Tracking;

use WeShop\Logistics\Service\TrackingService;
use Weline\Admin\Controller\BaseController;

class Save extends BaseController
{
    public function __construct(
        private readonly TrackingService $trackingService
    ) {
    }

    public function post(): string
    {
        $backUrl = (string) $this->request->getParam('back_url', $this->getBackendUrl('*/backend/tracking'));

        try {
            $tracking = $this->trackingService->saveTracking([
                'tracking_id' => $this->request->getParam('tracking_id', 0),
                'order_id' => $this->request->getParam('order_id', 0),
                'tracking_number' => $this->request->getParam('tracking_number', ''),
                'carrier' => $this->request->getParam('carrier', ''),
                'status' => $this->request->getParam('status', ''),
                'location' => $this->request->getParam('location', ''),
                'description' => $this->request->getParam('description', ''),
                'tracked_at' => $this->request->getParam('tracked_at', ''),
            ]);

            $this->getMessageManager()->addSuccess(__('Tracking saved.'));
            $this->redirect($this->getBackendUrl('*/backend/tracking', ['id' => $tracking->getId()]));
            return '';
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Tracking save failed.'));
            $this->redirect($backUrl);
            return '';
        }
    }

    public function index(): string
    {
        return $this->post();
    }
}
