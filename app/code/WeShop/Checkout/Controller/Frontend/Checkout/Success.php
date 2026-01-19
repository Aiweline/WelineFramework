<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller\Frontend\Checkout;

use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Order\Service\OrderService;
use WeShop\Customer\Session\CustomerSession;

/**
 * 订单确认成功页控制器
 * 
 * 支持4种布局变体：
 * - order_confirmation_page_1
 * - order_confirmation_page_2
 * - order_confirmation_page_3
 * - order_confirmation_page_4
 * 
 * 布局变体通过主题配置设置：layouts.checkout_success = order_confirmation_page_1
 */
class Success extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'checkout_success';
    
    public function index()
    {
        $orderId = (int)($this->request->getParam('order_id') ?? 0);
        
        if (!$orderId) {
            $this->getMessageManager()->addError(__('订单ID不能为空'));
            return $this->redirect('weshop/cart');
        }
        
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        $customer = $customerSession->getCustomer();
        
        if (!$customer || !$customer->getId()) {
            $this->getMessageManager()->addError(__('请先登录'));
            return $this->redirect('weshop/customer/account/login');
        }
        
        /** @var OrderService $orderService */
        $orderService = ObjectManager::getInstance(OrderService::class);
        $order = $orderService->getOrder($orderId);
        
        if (!$order || $order->getCustomerId() !== $customer->getId()) {
            $this->getMessageManager()->addError(__('订单不存在'));
            return $this->redirect('weshop/cart');
        }
        
        // 格式化订单数据
        $orderData = [
            'order_id' => $order->getId(),
            'increment_id' => $order->getIncrementId(),
            'grand_total' => $order->getGrandTotal(),
            'subtotal' => $order->getSubtotal(),
            'shipping_amount' => $order->getShippingAmount(),
            'tax_amount' => $order->getTaxAmount(),
            'estimated_delivery' => $order->getEstimatedDelivery() ?? __('Thursday, Oct 24'),
            'shipping_address' => [
                'firstname' => $order->getShippingFirstname(),
                'lastname' => $order->getShippingLastname(),
                'street' => $order->getShippingStreet(),
                'city' => $order->getShippingCity(),
                'region' => $order->getShippingRegion(),
                'postcode' => $order->getShippingPostcode(),
            ],
            'payment_method_title' => $order->getPaymentMethodTitle() ?? 'Visa',
            'payment_last4' => $order->getPaymentLast4() ?? '1234',
        ];
        
        // 获取订单商品
        $orderItems = [];
        $orderItemCollection = $orderService->getOrderItems($orderId);
        foreach ($orderItemCollection as $item) {
            $orderItems[] = [
                'product_id' => $item->getProductId(),
                'name' => $item->getProductName(),
                'image' => $item->getProductImage() ?? '',
                'price' => $item->getPrice(),
                'qty' => $item->getQty(),
                'option' => $item->getOption() ?? null,
                'seller' => $item->getSeller() ?? null,
                'is_bestseller' => $item->getIsBestseller() ?? false,
            ];
        }
        
        // 准备模板数据
        $this->assign('order', $orderData);
        $this->assign('order_items', $orderItems);
        $this->assign('subtotal', $orderData['subtotal']);
        $this->assign('shipping', $orderData['shipping_amount']);
        $this->assign('tax', $orderData['tax_amount']);
        $this->assign('grand_total', $orderData['grand_total']);
        $this->assign('shipping_address', $orderData['shipping_address']);
        
        // 获取推荐商品（可选）
        // TODO: 实现推荐商品逻辑
        $this->assign('recommendations', []);
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/checkout_success/order_confirmation_page_{variant}.phtml
        return $this->fetch();
    }
}
