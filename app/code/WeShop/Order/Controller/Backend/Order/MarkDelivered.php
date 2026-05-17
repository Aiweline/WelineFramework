<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Backend\Order;

use WeShop\Order\Service\OrderService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Order::order_management_delivery', 'Delivery actions', 'mdi mdi-package-check', 'Manage order delivery', 'WeShop_Order::order_management')]
class MarkDelivered extends BaseController
{
    private const DEFAULT_BACK_ROUTE = '*/backend/order';

    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    #[Acl('WeShop_Order::order_management_mark_delivered_post', 'Mark delivered', 'mdi mdi-package-variant-closed-check', 'Mark shipment delivered')]
    public function post(): string
    {
        $orderId = (int) $this->request->getParam('id', 0);
        $backUrl = $this->resolveBackUrl(
            (string) $this->request->getParam('back_url', ''),
            $this->getUrl(self::DEFAULT_BACK_ROUTE)
        );

        if ($orderId <= 0) {
            $this->getMessageManager()->addError((string) __('Order ID is required.'));
            $this->redirect($backUrl);
            return '';
        }

        try {
            $this->orderService->markDelivered($orderId);
            $this->getMessageManager()->addSuccess((string) __('Order marked as delivered.'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: (string) __('Delivery update failed.'));
        }

        $this->redirect($backUrl);
        return '';
    }

    #[Acl('WeShop_Order::order_management_mark_delivered_index', 'Open delivery route', 'mdi mdi-package-check', 'Open delivery update route')]
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
