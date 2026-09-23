<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Checkout\Service\OrderService;
use Weline\Checkout\Service\CheckoutSessionAccessService;
use Weline\Checkout\Service\CheckoutSuccessPresentationService;
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
        private readonly CheckoutSuccessPresentationService $successPresentation,
        private readonly ?\Weline\Checkout\Service\ContinuePaymentRecoveryUrlBuilder $continuePayUrlBuilder = null,
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
        $this->assign('continue_pay_url', '');
        $this->assign('continue_pay_available', false);

        $orderUuid = trim((string)$this->request->getParam('order_uuid'));
        if ($orderUuid !== '') {
            return $this->renderV2($orderUuid, $isCancel, $cancelAlready);
        }

        $orderId = (int)$this->request->getParam('order_id');
        if ($orderId <= 0) {
            if ($isCancel) {
                $title = (string)__('支付已取消');
                $this->request->setGet('theme_page_title', $title);
                $this->assign('page_title', $title);
                $this->assign('title', $title);
                $this->assign('order', null);

                return $this->fetch('Weline_Checkout::frontend/checkout/success.phtml');
            }

            if ($this->isThemeEditorCanvasRequest()) {
                return $this->renderEditorPreviewShell();
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
            ? (string)__('支付已取消')
            : (string)__('结账成功');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('order', $order);

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
            $status = strtolower(trim((string) ($order->status ?? '')));
            $isPaid = in_array($status, ['paid', 'fulfilled', 'completed'], true);
            // Paid express/success landings without a restored capability must NOT dump to /cart.
            if ($isPaid && !$isCancel) {
                return $this->renderPaidAcknowledgement($order);
            }

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
            ? (string)__('支付已取消')
            : (string)__('结账成功');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('order', null);
        $orderArr = $order->toArray();
        $presentation = $this->successPresentation->present(
            items: is_array($order->items) ? $order->items : [],
            shippingMethodCode: (string)($order->shipping['method'] ?? ''),
            websiteId: (int)$order->websiteId,
            storeId: (int)$order->storeId,
        );
        $shippingLabel = (string)($presentation['shipping_method_label'] ?? '');
        if ($shippingLabel !== '' && is_array($orderArr['shipping'] ?? null)) {
            $orderArr['shipping']['method_label'] = $shippingLabel;
        }
        $this->assign('order_v2', $orderArr);
        $this->assign('order_v2_items_display', $presentation['items_display'] ?? []);
        $this->assign('shipping_method_label', $shippingLabel);
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
        $this->assignContinuePayForCancel($orderUuid, $checkoutToken, (string)($order->status ?? ''), $isCancel, $cancelAlready);

        return $this->fetch('Weline_Checkout::frontend/checkout/success.phtml');
    }

    private function assignContinuePayForCancel(
        string $orderUuid,
        string $checkoutToken,
        string $orderStatus,
        bool $isCancel,
        bool $cancelAlready,
    ): void {
        $this->assign('continue_pay_url', '');
        $this->assign('continue_pay_available', false);
        if (!$isCancel || $cancelAlready || $orderUuid === '') {
            return;
        }
        $status = strtolower(trim($orderStatus));
        if (in_array($status, ['paid', 'fulfilled', 'completed', 'cancelled', 'canceled', 'refunded'], true)) {
            return;
        }
        $token = $checkoutToken;
        if ($token === '') {
            try {
                $sessions = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Checkout\Api\CheckoutSessionStoreInterface::class
                );
                if ($sessions instanceof \Weline\Checkout\Api\CheckoutSessionStoreInterface) {
                    $token = trim((string)($sessions->findSubmittedTokenByOrderUuid($orderUuid) ?? ''));
                }
            } catch (\Throwable) {
                $token = '';
            }
        }
        if ($token === '') {
            return;
        }
        $builder = $this->continuePayUrlBuilder
            ?? \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Checkout\Service\ContinuePaymentRecoveryUrlBuilder::class
            );
        if (!is_object($builder) || !method_exists($builder, 'build')) {
            return;
        }
        $built = $builder->build($token, $orderUuid, null);
        $url = trim((string)($built['continue_pay_url'] ?? ''));
        if ($url === '' || empty($built['reachable'])) {
            return;
        }
        $this->assign('continue_pay_url', $url);
        $this->assign('continue_pay_available', true);
    }

    /**
     * Soft success for paid orders when quote-token capability is missing (express/popup boundary).
     * Shows paid confirmation without dumping the shopper to /cart.
     * Must still carry line items for checkout_success pixel (align payment_success).
     */
    private function renderPaidAcknowledgement(object $order): string
    {
        $arr = method_exists($order, 'toArray') ? $order->toArray() : [];
        if (!is_array($arr)) {
            $arr = [];
        }
        $display = trim((string) ($order->displayNumber ?? $arr['display_number'] ?? $order->orderUuid ?? $arr['order_uuid'] ?? ''));
        $currency = trim((string) ($order->currency ?? $arr['currency'] ?? 'CNY'));
        $money = is_array($order->money ?? null) ? $order->money : (is_array($arr['money'] ?? null) ? $arr['money'] : []);
        $totalLabel = $currency . ' ' . number_format(((int) ($money['grand_total_minor'] ?? 0)) / 100, 2, '.', ',');
        $items = is_array($order->items ?? null) ? $order->items : (is_array($arr['items'] ?? null) ? $arr['items'] : []);
        $presentation = $this->successPresentation->present(
            items: $items,
            shippingMethodCode: (string)($order->shipping['method'] ?? $arr['shipping']['method'] ?? ''),
            websiteId: (int)($order->websiteId ?? $arr['website_id'] ?? 0),
            storeId: (int)($order->storeId ?? $arr['store_id'] ?? 0),
        );
        $shippingLabel = (string)($presentation['shipping_method_label'] ?? '');
        $shipping = is_array($arr['shipping'] ?? null) ? $arr['shipping'] : [];
        if ($shippingLabel !== '' && $shipping !== []) {
            $shipping['method_label'] = $shippingLabel;
        }

        $title = (string) __('订单已支付');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('checkout_payment_cancelled', false);
        $this->assign('checkout_cancel_already', false);
        $this->assign('checkout_paid_ack', true);
        $this->assign('order', null);
        $this->assign('order_v2', [
            'order_uuid' => (string) ($order->orderUuid ?? $arr['order_uuid'] ?? ''),
            'display_number' => $display,
            'status' => 'paid',
            'currency' => $currency,
            'money' => $money,
            'items' => $items,
            'shipping' => $shipping,
            'billing_address' => is_array($arr['billing_address'] ?? null) ? $arr['billing_address'] : [],
        ]);
        $this->assign('order_v2_items_display', $presentation['items_display'] ?? []);
        $this->assign('shipping_method_label', $shippingLabel);
        $this->assign('order_v2_display_number', $display !== '' ? $display : (string) ($order->orderUuid ?? ''));
        $this->assign('order_v2_status', 'paid');
        $this->assign('order_v2_total_label', $totalLabel);

        return $this->fetch('Weline_Checkout::frontend/checkout/success.phtml');
    }

    /**
     * Theme editor canvas: no real order — still render success template shell (layout inferred from path).
     */
    private function renderEditorPreviewShell(): string
    {
        $title = (string) __('结账成功');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('checkout_payment_cancelled', false);
        $this->assign('checkout_cancel_already', false);
        $this->assign('checkout_paid_ack', false);
        $this->assign('order', null);
        $this->assign('order_v2', [
            'order_uuid' => 'preview-order',
            'display_number' => 'PREVIEW-0001',
            'status' => 'paid',
            'currency' => 'CNY',
            'money' => ['grand_total_minor' => 0],
            'items' => [],
            'shipping' => [],
            'billing_address' => [],
        ]);
        $this->assign('order_v2_display_number', 'PREVIEW-0001');
        $this->assign('order_v2_status', 'paid');
        $this->assign('order_v2_total_label', 'CNY 0.00');
        $this->assign('order_v2_items_display', []);
        $this->assign('shipping_method_label', '');

        return $this->fetch('Weline_Checkout::frontend/checkout/success.phtml');
    }

    private function isThemeEditorCanvasRequest(): bool
    {
        $editorMode = strtolower(trim((string)$this->request->getParam('editor_mode', '')));
        if ($editorMode === '1' || $editorMode === 'true' || $editorMode === 'yes') {
            return true;
        }

        return strtolower(trim((string)$this->request->getParam('shell', ''))) === 'theme-editor';
    }
}
