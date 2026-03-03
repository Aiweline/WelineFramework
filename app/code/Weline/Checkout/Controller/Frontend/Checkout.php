<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Controller\Frontend;

use Weline\Checkout\Service\CheckoutService;
use Weline\Checkout\Service\PaymentService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 前端结账控制器
 */
class Checkout extends FrontendController
{
    private CheckoutService $checkoutService;
    private PaymentService $paymentService;

    public function __construct(
        CheckoutService $checkoutService,
        PaymentService $paymentService
    ) {
        $this->checkoutService = $checkoutService;
        $this->paymentService = $paymentService;
    }

    /**
     * 结账页面
     * 
     * @return string
     */
    public function index(): string
    {
        // 检查登录状态
        if (!$this->isLoggedIn()) {
            return $this->redirect($this->getUrl('*/frontend/index'));
        }

        $this->assign('page_title', __('结账'));
        $this->layoutType = 'checkout';
        
        return $this->fetch();
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

        if (!$this->isLoggedIn()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        $customerId = $this->getLoginUserId();
        
        try {
            $data = [
                'customer_id' => $customerId,
                'items' => $this->request->getPost('items', []),
                'shipping_address' => $this->request->getPost('shipping_address', []),
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
                    'redirect_url' => $this->getUrl('checkout/frontend/checkout/successPage', ['order_id' => $order->getId()])
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
            return $this->redirect($this->getUrl('*/frontend/index'));
        }

        /** @var \Weline\Checkout\Service\OrderService $orderService */
        $orderService = ObjectManager::getInstance(\Weline\Checkout\Service\OrderService::class);
        $order = $orderService->getOrder($orderId);

        if (!$order) {
            return $this->redirect($this->getUrl('*/frontend/index'));
        }

        // 验证订单所有权
        if ($this->isLoggedIn()) {
            $customerId = $this->getLoginUserId();
            if ($order->getCustomerId() != $customerId) {
                return $this->redirect($this->getUrl('*/frontend/index'));
            }
        }

        $this->assign('page_title', __('结账成功'));
        $this->assign('order', $order);
        $this->layoutType = 'checkout';
        
        return $this->fetch();
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

        if (!$this->isLoggedIn()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先登录')
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

