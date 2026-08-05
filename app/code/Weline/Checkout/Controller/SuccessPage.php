<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Checkout\Service\OrderService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Order\Api\OrderFacadeInterface;

class SuccessPage extends FrontendController
{
    private const CART_PATH = '/cart';
    private const ORDER_LIST_PATH = '/weline_checkout/frontend/order/list';

    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderFacadeInterface $orders,
    ) {
    }

    public function index(): string
    {
        $orderUuid = trim((string)$this->request->getParam('order_uuid'));
        if ($orderUuid !== '') {
            return $this->renderV2($orderUuid);
        }

        $orderId = (int)$this->request->getParam('order_id');
        if ($orderId <= 0) {
            return $this->redirect(self::CART_PATH);
        }

        $order = $this->orderService->getOrder($orderId);
        if (!$order) {
            return $this->redirect(self::ORDER_LIST_PATH);
        }

        if ($this->isLoggedIn() && $order->getCustomerId() != $this->getLoginUserId()) {
            return $this->redirect(self::ORDER_LIST_PATH);
        }

        $this->assign('page_title', __('结账成功'));
        $this->assign('order', $order);
        $this->layoutType = 'checkout';

        return $this->fetch('Weline_Checkout::frontend/checkout/success.phtml');
    }

    private function renderV2(string $orderUuid): string
    {
        try {
            $order = $this->orders->get($orderUuid);
        } catch (\Throwable) {
            return $this->redirect(self::ORDER_LIST_PATH);
        }

        $customerId = $order->customerId !== null ? (int)$order->customerId : null;
        if ($this->isLoggedIn() && $customerId !== (int)$this->getLoginUserId()) {
            return $this->redirect(self::ORDER_LIST_PATH);
        }

        $this->assign('page_title', __('结账成功'));
        $this->assign('order', null);
        $this->assign('order_v2', $order->toArray());
        $this->assign('order_v2_display_number', $order->displayNumber ?: $order->orderUuid);
        $this->assign('order_v2_status', $order->status);
        $this->assign(
            'order_v2_total_label',
            sprintf(
                '%s %s',
                $order->currency,
                number_format(((int)($order->money['grand_total_minor'] ?? 0)) / 100, 2, '.', ''),
            ),
        );
        $this->layoutType = 'checkout';

        return $this->fetch('Weline_Checkout::frontend/checkout/success.phtml');
    }
}
