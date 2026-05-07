<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Backend\Order;

use WeShop\Order\Service\OrderService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Order::order_management_actions', 'Order actions', 'mdi mdi-receipt-text-edit-outline', 'Update order state', 'WeShop_Order::order_management')]
class UpdateStatus extends BaseController
{
    private const DEFAULT_BACK_ROUTE = '*/backend/order';

    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    #[Acl('WeShop_Order::order_management_update_status_post', 'Update order status', 'mdi mdi-swap-horizontal', 'Update order status data')]
    public function post(): string
    {
        $orderId = (int) $this->request->getParam('id', 0);
        $status = (string) $this->request->getParam('status', '');
        $backUrl = $this->resolveBackUrl(
            (string) $this->request->getParam('back_url', ''),
            $this->getUrl(self::DEFAULT_BACK_ROUTE)
        );

        if (!$orderId) {
            $this->getMessageManager()->addError((string) __('Order ID is required.'));
            $this->redirect($backUrl);
            return '';
        }

        if ($status === '' || !$this->orderService->isValidStatus($status)) {
            $this->getMessageManager()->addError((string) __('Invalid order status.'));
            $this->redirect($backUrl);
            return '';
        }

        try {
            $this->orderService->updateOrderStatus($orderId, $status);
            $this->getMessageManager()->addSuccess((string) __('Order status updated.'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: (string) __('Order status update failed.'));
        }

        $this->redirect($backUrl);
        return '';
    }

    #[Acl('WeShop_Order::order_management_update_status_index', 'Open order status route', 'mdi mdi-swap-horizontal-circle', 'Open order status update route')]
    public function index(): string
    {
        return $this->post();
    }

    private function resolveBackUrl(string $backUrl, string $fallback): string
    {
        $backUrl = trim($backUrl);
        if ($backUrl === '') {
            return $fallback;
        }

        // Avoid redirecting to external origins via injected absolute URLs.
        if (str_contains($backUrl, '://') || str_starts_with($backUrl, '//')) {
            return $fallback;
        }

        return $backUrl;
    }
}
