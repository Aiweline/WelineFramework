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

/**
 * 前端订单控制器
 */
class Order extends FrontendController
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService) {
        $this->orderService = $orderService;
    }

    /**
     * 订单列表
     * 
     * @return string
     */
    public function list(): string
    {
        return $this->redirect($this->getUrl('customer/account/index') . '#orders');
    }

    /**
     * 订单详情
     * 
     * @return string
     */
    public function view(): string
    {
        $orderUuid = trim((string)$this->request->getParam('order_uuid', ''));

        return $this->redirectToAccountOrders($orderUuid);
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

    private function redirectToAccountOrders(string $orderUuid = ''): string
    {
        $hash = '#orders';
        if ($orderUuid !== '') {
            $hash .= '?order_uuid=' . rawurlencode($orderUuid);
        }

        return $this->redirect($this->getUrl('customer/account/index') . $hash);
    }
}
