<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Backend\Order;

use WeShop\Order\Service\OrderService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Order::order_management_actions', 'Order actions', 'mdi mdi-credit-card-check-outline', 'Update order payment state', 'WeShop_Order::order_management')]
class UpdatePaymentStatus extends BaseController
{
    private const DEFAULT_BACK_ROUTE = '*/backend/order';

    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    #[Acl('WeShop_Order::order_management_update_payment_status_post', 'Update payment status', 'mdi mdi-credit-card-check', 'Update order payment status data')]
    public function post(): string
    {
        $orderId = (int) $this->request->getParam('id', 0);
        $paymentStatus = (string) $this->request->getParam('payment_status', '');
        $backUrl = $this->resolveBackUrl(
            (string) $this->request->getParam('back_url', ''),
            $this->getUrl(self::DEFAULT_BACK_ROUTE)
        );

        if (!$orderId) {
            $this->getMessageManager()->addError((string) __('缺少订单 ID。'));
            $this->redirect($backUrl);
            return '';
        }

        if ($paymentStatus === '' || !$this->orderService->isValidPaymentStatus($paymentStatus)) {
            $this->getMessageManager()->addError((string) __('无效的支付状态。'));
            $this->redirect($backUrl);
            return '';
        }

        try {
            $this->orderService->updatePaymentStatus($orderId, $paymentStatus);
            $this->getMessageManager()->addSuccess((string) __('订单支付状态已更新。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: (string) __('订单支付状态更新失败。'));
        }

        $this->redirect($backUrl);
        return '';
    }

    #[Acl('WeShop_Order::order_management_update_payment_status_index', 'Open payment status route', 'mdi mdi-credit-card-sync-outline', 'Open payment status update route')]
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

        if (str_contains($backUrl, '://') || str_starts_with($backUrl, '//')) {
            return $fallback;
        }

        return $backUrl;
    }
}
