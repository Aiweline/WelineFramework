<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Checkout\Service\OrderService;
use Weline\Checkout\Service\CheckoutSessionAccessService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Order\Api\OrderFacadeInterface;

/**
 * Storefront checkout success page at /checkout/success.
 */
class Success extends FrontendController
{
    private const CART_PATH = '/cart';
    private const ORDER_LIST_PATH = '/customer/account/index#orders';

    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderFacadeInterface $orders,
        private readonly CheckoutSessionAccessService $checkoutAccess,
    ) {
    }

    public function index(): string
    {
        $this->request->getResponse()
            ->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate')
            ->setHeader('Pragma', 'no-cache');

        $params = $this->request->getParams();
        if (!\is_array($params)) {
            $params = [];
        }
        if (!isset($params['outcome']) || trim((string) $params['outcome']) === '') {
            $fromGet = trim((string) $this->request->getParam('outcome', ''));
            if ($fromGet === '' && isset($_GET['outcome'])) {
                $fromGet = trim((string) $_GET['outcome']);
            }
            if ($fromGet !== '') {
                $params['outcome'] = $fromGet;
            }
        }
        $isCancel = \Weline\Payment\Service\PaymentBrowserCallbackRoutes::isCancelOutcome($params);
        $cancelAlready = $isCancel
            && \Weline\Payment\Service\PaymentBrowserCallbackRoutes::isCancelAlready($params);
        $this->assign('checkout_payment_cancelled', $isCancel);
        $this->assign('checkout_cancel_already', $cancelAlready);

        $orderUuid = trim((string)$this->request->getParam('order_uuid'));
        if ($orderUuid !== '') {
            return $this->renderV2($orderUuid, $isCancel, $cancelAlready);
        }

        $orderId = (int)$this->request->getParam('order_id');
        if ($orderId <= 0) {
            if ($isCancel) {
                $title = $cancelAlready ? (string)__('已取消') : (string)__('已取消成功');
                $this->request->setGet('theme_page_title', $title);
                $this->assign('page_title', $title);
                $this->assign('title', $title);
                $this->assign('order', null);
                $this->layoutType = 'checkout';

                return $this->fetch('Weline_Checkout::frontend/checkout/success.phtml');
            }

            return $this->redirect(self::CART_PATH);
        }

        $order = $this->orderService->getOrder($orderId);
        if (!$order) {
            return $this->redirect(self::ORDER_LIST_PATH);
        }

        if (!$this->isLoggedIn() || $order->getCustomerId() != $this->getLoginUserId()) {
            return $this->redirect(self::ORDER_LIST_PATH);
        }

        if ($isCancel && !$cancelAlready) {
            $status = strtolower(trim((string)$order->getStatus()));
            if ($status === 'cancelled' || $status === 'canceled') {
                $cancelAlready = true;
                $this->assign('checkout_cancel_already', true);
            }
        }
        $title = $isCancel
            ? ($cancelAlready ? (string)__('已取消') : (string)__('已取消成功'))
            : (string)__('结账成功');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('order', $order);
        $this->layoutType = 'checkout';

        return $this->fetch('Weline_Checkout::frontend/checkout/success.phtml');
    }

    private function renderV2(string $orderUuid, bool $isCancel = false, bool $cancelAlready = false): string
    {
        $checkoutToken = trim((string)$this->request->getParam('checkout_token', ''));
        $currentCustomerId = $this->isLoggedIn() ? (int)$this->getLoginUserId() : null;
        $capabilityAllowed = $checkoutToken !== ''
            && $this->checkoutAccess->canAccess($checkoutToken, $orderUuid, $currentCustomerId);

        try {
            $order = $this->orders->get($orderUuid);
        } catch (\Throwable) {
            return $this->redirect(self::ORDER_LIST_PATH);
        }

        $customerId = $order->customerId !== null && (int)$order->customerId > 0
            ? (int)$order->customerId
            : null;
        if (!$this->checkoutAccess->canReadOrder(
            $customerId,
            $currentCustomerId,
            $capabilityAllowed,
        )) {
            // Guests without a live success capability must not be pushed into the
            // account login funnel (/customer/account → /login).
            return $this->redirect($this->isLoggedIn() ? self::ORDER_LIST_PATH : self::CART_PATH);
        }

        if ($isCancel && !$cancelAlready) {
            $status = strtolower(trim((string)$order->status));
            if ($status === 'cancelled' || $status === 'canceled') {
                $cancelAlready = true;
                $this->assign('checkout_cancel_already', true);
            }
        }
        $title = $isCancel
            ? ($cancelAlready ? (string)__('已取消') : (string)__('已取消成功'))
            : (string)__('结账成功');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('order', null);
        $this->assign('order_v2', $order->toArray());
        $this->assign('order_v2_display_number', $order->displayNumber ?: $order->orderUuid);
        $this->assign('order_v2_status', $order->status);
        $this->assign(
            'order_v2_total_label',
            sprintf(
                '%s %s',
                $order->currency,
                number_format(((int)($order->money['grand_total_minor'] ?? 0)) / 100, 2, '.', ','),
            ),
        );
        $this->layoutType = 'checkout';

        return $this->fetch('Weline_Checkout::frontend/checkout/success.phtml');
    }
}
