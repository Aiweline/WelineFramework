<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Controller\Frontend;

use Weline\Checkout\Service\CheckoutIdentityService;
use Weline\Checkout\Service\CheckoutService;
use Weline\Checkout\Service\PaymentService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 前端结账控制器
 */
class Checkout extends FrontendController
{
    private const CART_PATH = '/cart';
    private const ORDER_LIST_PATH = '/weline_checkout/frontend/order/list';

    private CheckoutService $checkoutService;
    private PaymentService $paymentService;
    private CheckoutIdentityService $checkoutIdentityService;

    public function __construct(
        CheckoutService $checkoutService,
        PaymentService $paymentService,
        CheckoutIdentityService $checkoutIdentityService
    ) {
        $this->checkoutService = $checkoutService;
        $this->paymentService = $paymentService;
        $this->checkoutIdentityService = $checkoutIdentityService;
    }

    /**
     * 结账页面
     * 
     * @return string
     */
    public function index(): string
    {
        // 默认允许匿名结账：未登录也直接渲染结账页。
        $this->assign('page_title', __('结账'));
        $this->layoutType = 'checkout';
        
        return $this->fetch('Weline_Checkout::frontend/checkout/index.phtml');
    }

    /**
     * 创建订单（AJAX）
     * 
     * @return string
     */
    public function createOrder(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        try {
            $shippingAddress = $this->request->getPost('shipping_address', []);
            if (!\is_array($shippingAddress)) {
                $shippingAddress = [];
            }
            $authenticatedCustomerId = $this->isLoggedIn() ? (int)$this->getLoginUserId() : 0;
            $identity = $this->checkoutIdentityService->resolve([
                'authenticated_customer_id' => $authenticatedCustomerId,
                'customer_id' => $authenticatedCustomerId,
                'guest_allowed' => true,
                'customer_allowed' => $authenticatedCustomerId > 0,
                'checkout_mode' => $this->request->getPost('checkout_mode', $authenticatedCustomerId > 0 ? 'customer' : 'guest'),
                'guest_email' => $this->request->getPost(
                    'guest_email',
                    $this->request->getPost('email', $shippingAddress['email'] ?? '')
                ),
            ]);
            if (!empty($identity['is_guest_checkout'])) {
                $this->checkoutIdentityService->validateGuestCheckout($identity, [
                    'shipping_address' => $shippingAddress,
                    'guest_email' => $identity['guest_email'],
                ]);
            }

            $data = [
                'customer_id' => !empty($identity['is_guest_checkout']) ? 0 : max(0, (int)$identity['customer_id']),
                'authenticated_customer_id' => $authenticatedCustomerId,
                'checkout_mode' => (string)$identity['checkout_mode'],
                'is_guest_checkout' => !empty($identity['is_guest_checkout']),
                'guest_email' => (string)($identity['guest_email'] ?? ''),
                'guest_allowed' => true,
                'items' => $this->request->getPost('items', []),
                'shipping_address' => $shippingAddress,
                'billing_address' => $this->request->getPost('billing_address', []),
                'shipping_method' => $this->request->getPost('shipping_method', ''),
                'shipping_amount' => (float)$this->request->getPost('shipping_amount', 0),
                'tax_amount' => (float)$this->request->getPost('tax_amount', 0),
                'discount_amount' => (float)$this->request->getPost('discount_amount', 0),
                'payment_method' => $this->request->getPost('payment_method', ''),
                'currency' => $this->request->getPost('currency', 'CNY'),
                'remark' => $this->request->getPost('remark', ''),
            ];

            $order = $this->checkoutService->createOrder($data);

            return $this->fetchJson([
                'success' => true,
                'message' => __('订单创建成功'),
                'data' => [
                    'order_id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'redirect_url' => $this->getUrl('checkout/success-page', ['order_id' => $order->getId()])
                ]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('订单创建失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 结账成功页面
     * 
     * @return string
     */
    public function successPage(): string
    {
        $orderId = (int)$this->request->getParam('order_id');
        
        if (!$orderId) {
            return $this->redirect(self::CART_PATH);
        }

        /** @var \Weline\Checkout\Service\OrderService $orderService */
        $orderService = ObjectManager::getInstance(\Weline\Checkout\Service\OrderService::class);
        $order = $orderService->getOrder($orderId);

        if (!$order) {
            return $this->redirect(self::ORDER_LIST_PATH);
        }

        // 验证订单所有权
        if ($this->isLoggedIn()) {
            $customerId = $this->getLoginUserId();
            if ($order->getCustomerId() != $customerId) {
                return $this->redirect(self::ORDER_LIST_PATH);
            }
        }

        $this->assign('page_title', __('结账成功'));
        $this->assign('order', $order);
        $this->layoutType = 'checkout';
        
        return $this->fetch('Weline_Checkout::frontend/checkout/success.phtml');
    }

    /**
     * 处理支付（AJAX）
     * 
     * @return string
     */
    public function processPayment(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $orderId = (int)$this->request->getPost('order_id');
        $paymentMethod = $this->request->getPost('payment_method', '');
        $paymentData = $this->request->getPost('payment_data', []);

        try {
            $result = $this->paymentService->processPayment($orderId, $paymentMethod, $paymentData);

            return $this->fetchJson([
                'success' => true,
                'message' => __('支付处理成功'),
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('支付处理失败：%{1}', $e->getMessage())
            ]);
        }
    }
}
