<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 订单详情控制器
 */
class View extends FrontendController
{
    /**
     * 订单详情页
     */
    public function index(): string
    {
        $orderId = (int)($this->request->getParam('id') ?? 0);
        
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
        $order = $orderService->getOrder($orderId);
        
        if (!$order || $order->getCustomerId() !== $customer->getId()) {
            $this->getMessageManager()->addError(__('订单不存在'));
            return $this->redirect('weshop/order/list');
        }
        
        $this->assign('order', $order);
        
        return $this->fetch();
    }
}
