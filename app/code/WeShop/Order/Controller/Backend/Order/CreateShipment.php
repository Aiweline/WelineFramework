<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Backend\Order;

use WeShop\Order\Service\OrderService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Order::order_management_fulfillment', 'Fulfillment actions', 'mdi mdi-truck-delivery-outline', 'Manage order fulfillment', 'WeShop_Order::order_management')]
class CreateShipment extends BaseController
{
    private const DEFAULT_BACK_ROUTE = '*/backend/order';

    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    #[Acl('WeShop_Order::order_management_create_shipment_post', 'Create shipment', 'mdi mdi-truck-fast', 'Create order shipment')]
    public function post(): string
    {
        $orderId = (int) $this->request->getParam('id', 0);
        $carrier = trim((string) $this->request->getParam('carrier', ''));
        $trackingNumber = trim((string) $this->request->getParam('tracking_number', ''));
        $backUrl = $this->resolveBackUrl(
            (string) $this->request->getParam('back_url', ''),
            $this->getUrl(self::DEFAULT_BACK_ROUTE)
        );

        if ($orderId <= 0) {
            $this->getMessageManager()->addError((string) __('缺少订单 ID。'));
            $this->redirect($backUrl);
            return '';
        }

        try {
            $this->orderService->createShipment($orderId, $carrier, $trackingNumber);
            $this->getMessageManager()->addSuccess((string) __('发货单已创建，订单已进入已发货状态。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: (string) __('创建发货单失败。'));
        }

        $this->redirect($backUrl);
        return '';
    }

    #[Acl('WeShop_Order::order_management_create_shipment_index', 'Open create shipment route', 'mdi mdi-truck-check', 'Open shipment creation route')]
    public function index(): string
    {
        return $this->post();
    }

    private function resolveBackUrl(string $backUrl, string $fallback): string
    {
        $backUrl = trim($backUrl);
        if ($backUrl === '' || str_contains($backUrl, '://') || str_starts_with($backUrl, '//')) {
            return $fallback;
        }

        return $backUrl;
    }
}
