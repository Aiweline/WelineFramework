<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Checkout\Service\OrderService;
use Weline\Framework\App\Controller\FrontendController;

class SuccessPage extends FrontendController
{
    private const CART_PATH = '/cart';
    private const ORDER_LIST_PATH = '/weline_checkout/frontend/order/list';

    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    public function index(): string
    {
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
}
