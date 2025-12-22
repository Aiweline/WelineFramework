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
use Weline\Framework\Session\Session;

/**
 * 前端订单控制器
 */
class Order extends FrontendController
{
    protected ?Session $session;
    private OrderService $orderService;

    public function __construct(
        OrderService $orderService,
        Session $session
    ) {
        $this->orderService = $orderService;
        $this->session = $session;
    }

    /**
     * 检查登录状态
     * 
     * @return bool
     */
    private function checkLogin(): bool
    {
        return $this->session && $this->session->isLogin();
    }

    /**
     * 订单列表
     * 
     * @return string
     */
    public function list(): string
    {
        if (!$this->checkLogin()) {
            return $this->redirect($this->getUrl('*/frontend/index'));
        }

        $customerId = $this->session->getLoginUserData('entity_id');
        $page = max(1, (int)$this->request->getParam('page', 1));
        $pageSize = 20;

        $orders = $this->orderService->getCustomerOrders($customerId, $page, $pageSize);

        $this->assign('page_title', __('我的订单'));
        $this->assign('orders', $orders);
        $this->assign('page', $page);
        $this->assign('page_size', $pageSize);
        $this->layoutType = 'account';
        
        return $this->fetch();
    }

    /**
     * 订单详情
     * 
     * @return string
     */
    public function view(): string
    {
        if (!$this->checkLogin()) {
            return $this->redirect($this->getUrl('*/frontend/index'));
        }

        $orderId = (int)$this->request->getParam('order_id');
        $orderNumber = $this->request->getParam('order_number', '');

        if (!$orderId && !$orderNumber) {
            return $this->redirect($this->getUrl('checkout/frontend/order/list'));
        }

        $order = null;
        if ($orderId) {
            $order = $this->orderService->getOrder($orderId);
        } elseif ($orderNumber) {
            $order = $this->orderService->getOrderByNumber($orderNumber);
        }

        if (!$order) {
            return $this->redirect($this->getUrl('checkout/frontend/order/list'));
        }

        // 验证订单所有权
        $customerId = $this->session->getLoginUserData('entity_id');
        if ($order->getCustomerId() != $customerId) {
            return $this->redirect($this->getUrl('checkout/frontend/order/list'));
        }

        $this->assign('page_title', __('订单详情'));
        $this->assign('order', $order);
        $this->layoutType = 'account';
        
        return $this->fetch();
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

        if (!$this->checkLogin()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        $orderId = (int)$this->request->getPost('order_id');
        $customerId = $this->session->getLoginUserData('entity_id');

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
}

