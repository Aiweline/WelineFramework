<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 订单列表控制器
 * 
 * 支持3种布局变体：
 * - account_page_1
 * - account_page_2
 * - account_page_3
 * 
 * 布局变体通过主题配置设置：layouts.account = account_page_1
 */
class OrderList extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'account';
    
    /**
     * 订单列表页
     */
    public function index(): string
    {
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        $customer = $customerSession->getCustomer();
        
        if (!$customer || !$customer->getId()) {
            $this->getMessageManager()->addError(__('请先登录'));
            return $this->redirect('weshop/customer/account/login');
        }
        
        $page = (int)($this->request->getParam('page') ?? 1);
        $pageSize = (int)($this->request->getParam('page_size') ?? 20);
        
        /** @var OrderService $orderService */
        $orderService = ObjectManager::getInstance(OrderService::class);
        
        // 获取所有订单
        $result = $orderService->getCustomerOrders($customer->getId(), $page, $pageSize);
        
        // 获取未支付订单（用于显示提示）
        $unpaidOrders = $orderService->getUnpaidOrders($customer->getId());
        
        // 格式化订单数据
        $orders = [];
        $orderModel = ObjectManager::getInstance(\WeShop\Order\Model\Order::class);
        foreach ($result['items'] as $order) {
            $orderData = [
                'order_id' => $order['order_id'] ?? $order[$orderModel::schema_fields_ID] ?? 0,
                'increment_id' => $order['increment_id'] ?? $order[$orderModel::schema_fields_increment_id] ?? '',
                'status' => $order['status'] ?? $order[$orderModel::schema_fields_status] ?? 'pending',
                'payment_status' => $order['payment_status'] ?? OrderService::PAYMENT_STATUS_PENDING,
                'total' => $order['total'] ?? $order[$orderModel::schema_fields_total] ?? 0,
                'created_at' => $order['created_at'] ?? $order[$orderModel::schema_fields_created_at] ?? '',
            ];
            
            // 判断是否可以继续支付
            $orderData['can_retry_payment'] = $orderService->canRetryPayment(
                $orderData['order_id'],
                $customer->getId()
            );
            
            // 判断是否可以取消（使用新的检查方法）
            $cancelCheck = $orderService->canCancelOrder($orderData['order_id'], $customer->getId());
            $orderData['can_cancel'] = $cancelCheck['can_cancel'];
            $orderData['cancel_reason'] = $cancelCheck['reason'] ?? null;
            $orderData['require_return'] = $cancelCheck['require_return'] ?? false;
            $orderData['require_refund'] = $cancelCheck['require_refund'] ?? false;
            
            $orders[] = $orderData;
        }
        
        // 准备模板数据
        $this->assign('orders', $orders);
        $this->assign('unpaid_orders', $unpaidOrders);
        $this->assign('unpaid_count', count($unpaidOrders));
        $this->assign('pagination', $result['pagination']);
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/account/account_page_{variant}.phtml
        return $this->fetch();
    }
}
