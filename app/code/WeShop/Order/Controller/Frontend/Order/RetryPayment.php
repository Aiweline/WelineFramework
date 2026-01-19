<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Order\Service\OrderService;
use WeShop\Payment\Service\PaymentService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 订单继续支付控制器
 * 
 * 用于处理支付失败的订单继续支付
 */
class RetryPayment extends BaseController
{
    /**
     * 继续支付
     * 
     * 重定向到结账页面，携带订单ID参数
     */
    public function index(): string
    {
        $orderId = (int)($this->request->getParam('order_id') ?? 0);
        
        if (!$orderId) {
            $this->getMessageManager()->addError(__('订单ID不能为空'));
            return $this->redirect('weshop/order/list');
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
        
        // 检查订单是否可以继续支付
        if (!$orderService->canRetryPayment($orderId, $customer->getId())) {
            $this->getMessageManager()->addError(__('该订单无法继续支付'));
            return $this->redirect('weshop/order/list');
        }
        
        // 获取订单信息
        $order = $orderService->getOrder($orderId);
        if (!$order) {
            $this->getMessageManager()->addError(__('订单不存在'));
            return $this->redirect('weshop/order/list');
        }
        
        // 将订单商品恢复到购物车（可选，根据业务需求）
        // TODO: 实现将订单商品恢复到购物车的逻辑
        
        // 重定向到结账页面，携带订单ID参数
        $this->getMessageManager()->addSuccess(__('请完成订单支付'));
        return $this->redirect('weshop/checkout?order_id=' . $orderId);
    }
}
