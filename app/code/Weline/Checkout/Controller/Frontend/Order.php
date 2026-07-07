<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Controller\Frontend;

use Weline\Checkout\Service\OrderService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 前端订单控制器
 */
class Order extends FrontendController
{
    private const LOGIN_PATH = '/customer/account/login';
    private const ORDER_LIST_PATH = '/weline_checkout/frontend/order/list';
    private const ORDER_VIEW_PATH = '/weline_checkout/frontend/order/view';

    private OrderService $orderService;

    public function __construct(
        OrderService $orderService
    ) {
        $this->orderService = $orderService;
    }

    /**
     * 订单列表
     * 
     * @return string
     */
    public function list(): string
    {
        if (!$this->isLoggedIn()) {
            return $this->redirectToLogin(self::ORDER_LIST_PATH);
        }

        $customerId = $this->getLoginUserId();
        $page = max(1, (int)$this->request->getParam('page', 1));
        $pageSize = 20;

        $orders = $this->orderService->getCustomerOrders($customerId, $page, $pageSize);

        $this->assign('page_title', __('我的订单'));
        $this->assign('orders', $orders);
        $this->assign('page', $page);
        $this->assign('page_size', $pageSize);
        $this->layoutType = 'account';
        
        return $this->fetch('Weline_Checkout::frontend/order/list.phtml');
    }

    /**
     * 订单详情
     * 
     * @return string
     */
    public function view(): string
    {
        $orderId = (int)$this->request->getParam('order_id');
        $orderNumber = $this->request->getParam('order_number', '');

        if (!$this->isLoggedIn()) {
            return $this->redirectToLogin($this->orderViewPath($orderId, $orderNumber));
        }

        if (!$orderId && !$orderNumber) {
            return $this->redirect($this->getUrl('weline_checkout/frontend/order/list'));
        }

        $order = null;
        if ($orderId) {
            $order = $this->orderService->getOrder($orderId);
        } elseif ($orderNumber) {
            $order = $this->orderService->getOrderByNumber($orderNumber);
        }

        if (!$order) {
            return $this->redirect($this->getUrl('weline_checkout/frontend/order/list'));
        }

        // 验证订单所有权
        $customerId = $this->getLoginUserId();
        if ($order->getCustomerId() != $customerId) {
            return $this->redirect($this->getUrl('weline_checkout/frontend/order/list'));
        }

        $this->assign('page_title', __('订单详情'));
        $this->assign('order', $order);
        $this->layoutType = 'account';
        
        return $this->fetch('Weline_Checkout::frontend/order/view.phtml');
    }

    /**
     * 取消订单（AJAX）
     * 
     * @return string
     */
    public function cancel(): string
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
        $customerId = $this->getLoginUserId();

        try {
            $order = $this->orderService->getOrder($orderId);
            
            if (!$order) {
                throw new \Exception(__('订单不存在'));
            }

            // 验证订单所有权
            if ($order->getCustomerId() != $customerId) {
                throw new \Exception(__('无权操作此订单'));
            }

            $this->orderService->cancelOrder($orderId);

            return $this->fetchJson([
                'success' => true,
                'message' => __('订单已取消')
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('取消订单失败：%{1}', $e->getMessage())
            ]);
        }
    }

    private function redirectToLogin(string $targetPath): string
    {
        return $this->redirect(self::LOGIN_PATH, ['redirect_url' => $targetPath]);
    }

    private function orderViewPath(int $orderId, string $orderNumber): string
    {
        if ($orderId > 0) {
            return self::ORDER_VIEW_PATH . '?order_id=' . $orderId;
        }

        $orderNumber = trim($orderNumber);
        if ($orderNumber !== '') {
            return self::ORDER_VIEW_PATH . '?order_number=' . $orderNumber;
        }

        return self::ORDER_LIST_PATH;
    }
}
