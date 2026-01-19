<?php

declare(strict_types=1);

namespace WeShop\Order\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 订单API控制器
 * 
 * 提供订单相关的API接口，用于异步数据加载
 */
class Order extends FrontendRestController
{
    /**
     * 获取未支付订单数量
     * 
     * 用于header下拉菜单显示消息红点
     * 
     * @return string JSON响应
     */
    public function getUnpaidCount(): string
    {
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        $customer = $customerSession->getCustomer();
        
        if (!$customer || !$customer->getId()) {
            return $this->fetchJson([
                'code' => 401,
                'msg' => __('请先登录'),
                'data' => ['count' => 0]
            ]);
        }
        
        /** @var OrderService $orderService */
        $orderService = ObjectManager::getInstance(OrderService::class);
        $count = $orderService->getUnpaidOrderCount($customer->getId());
        
        return $this->fetchJson([
            'code' => 200,
            'msg' => __('获取成功'),
            'data' => [
                'count' => $count,
                'has_unpaid' => $count > 0
            ]
        ]);
    }
    
    /**
     * 获取未支付订单列表（简要信息）
     * 
     * 用于header下拉菜单显示订单列表
     * 
     * @return string JSON响应
     */
    public function getUnpaidList(): string
    {
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        $customer = $customerSession->getCustomer();
        
        if (!$customer || !$customer->getId()) {
            return $this->fetchJson([
                'code' => 401,
                'msg' => __('请先登录'),
                'data' => ['orders' => []]
            ]);
        }
        
        /** @var OrderService $orderService */
        $orderService = ObjectManager::getInstance(OrderService::class);
        $orders = $orderService->getUnpaidOrders($customer->getId());
        
        // 格式化订单数据（只返回简要信息）
        $orderList = [];
        foreach ($orders as $order) {
            $orderList[] = [
                'order_id' => $order['order_id'] ?? $order[\WeShop\Order\Model\Order::fields_ID] ?? 0,
                'increment_id' => $order['increment_id'] ?? $order[\WeShop\Order\Model\Order::fields_increment_id] ?? '',
                'total' => $order['total'] ?? $order[\WeShop\Order\Model\Order::fields_total] ?? 0,
                'created_at' => $order['created_at'] ?? $order[\WeShop\Order\Model\Order::fields_created_at] ?? '',
            ];
        }
        
        return $this->fetchJson([
            'code' => 200,
            'msg' => __('获取成功'),
            'data' => [
                'orders' => $orderList,
                'count' => count($orderList)
            ]
        ]);
    }
}
