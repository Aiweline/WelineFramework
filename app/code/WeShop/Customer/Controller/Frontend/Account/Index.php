<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Frontend\Account;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 用户账户首页控制器
 * 
 * 支持3种布局变体：
 * - account_page_1
 * - account_page_2
 * - account_page_3
 * 
 * 布局变体通过主题配置设置：layouts.account = account_page_1
 */
class Index extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'account';
    
    /**
     * 用户账户首页
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
        
        /** @var OrderService $orderService */
        $orderService = ObjectManager::getInstance(OrderService::class);
        
        // 获取最近订单（最近5条）
        $recentOrdersResult = $orderService->getCustomerOrders($customer->getId(), 1, 5);
        $recentOrders = [];
        foreach ($recentOrdersResult['items'] as $order) {
            $recentOrders[] = [
                'order_id' => $order['order_id'] ?? $order[\WeShop\Order\Model\Order::schema_fields_ID] ?? 0,
                'increment_id' => $order['increment_id'] ?? $order[\WeShop\Order\Model\Order::schema_fields_increment_id] ?? '',
                'status' => $order['status'] ?? $order[\WeShop\Order\Model\Order::schema_fields_status] ?? 'pending',
                'total' => $order['total'] ?? $order[\WeShop\Order\Model\Order::schema_fields_total] ?? 0,
                'created_at' => $order['created_at'] ?? $order[\WeShop\Order\Model\Order::schema_fields_created_at] ?? '',
            ];
        }
        
        // 获取订单统计
        $allOrdersResult = $orderService->getCustomerOrders($customer->getId(), 1, 1);
        $orderCount = $allOrdersResult['total'] ?? 0;
        
        // 获取未支付订单数量
        $unpaidCount = $orderService->getUnpaidOrderCount($customer->getId());
        
        // 格式化客户数据
        $customerData = [
            'customer_id' => $customer->getId(),
            'firstname' => $customer->getData(\WeShop\Customer\Model\Customer::schema_fields_FIRST_NAME) ?? '',
            'lastname' => $customer->getData(\WeShop\Customer\Model\Customer::schema_fields_LAST_NAME) ?? '',
            'email' => $customer->getData(\WeShop\Customer\Model\Customer::schema_fields_EMAIL) ?? '',
            'username' => $customer->getData('username') ?? $customer->getData(\WeShop\Customer\Model\Customer::schema_fields_EMAIL) ?? '',
            'phone' => $customer->getData(\WeShop\Customer\Model\Customer::schema_fields_PHONE) ?? '',
            'created_at' => $customer->getData(\WeShop\Customer\Model\Customer::schema_fields_CREATED_AT) ?? '',
        ];
        
        // 准备模板数据
        $this->assign('customer', $customerData);
        $this->assign('recent_orders', $recentOrders);
        $this->assign('order_count', $orderCount);
        $this->assign('unpaid_count', $unpaidCount);
        
        // 快捷操作链接
        $this->assign('quick_links', [
            [
                'title' => __('我的订单'),
                'url' => $this->getUrl('weshop/order/list'),
                'icon' => 'receipt_long',
            ],
            [
                'title' => __('我的收藏'),
                'url' => $this->getUrl('weshop/wishlist'),
                'icon' => 'favorite',
            ],
            [
                'title' => __('地址管理'),
                'url' => $this->getUrl('weshop/address'),
                'icon' => 'location_on',
            ],
            [
                'title' => __('账户设置'),
                'url' => $this->getUrl('weshop/customer/account/forgot-password'),
                'icon' => 'shield',
            ],
        ]);
        
        // 设置页面标题
        $this->assign('title', __('我的账户'));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/account/account_page_{variant}.phtml
        return $this->fetch();
    }
}
