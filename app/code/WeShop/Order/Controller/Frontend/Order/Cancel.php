<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 订单取消控制器
 * 
 * 用于取消未支付的订单
 */
class Cancel extends BaseController
{
    /**
     * 取消订单
     */
    public function postIndex(): string
    {
        $orderId = (int)($this->request->getPost('order_id') ?? 0);
        
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
        
        try {
            // 先检查是否可以取消
            $checkResult = $orderService->canCancelOrder($orderId, $customer->getId());
            
            if (!$checkResult['can_cancel']) {
                $this->getMessageManager()->addError($checkResult['reason'] ?? __('订单不能取消'));
                
                // 如果需要退货，引导用户去退货页面
                if (!empty($checkResult['require_return'])) {
                    $this->getMessageManager()->addNotice(__('请先申请退货'));
                    return $this->redirect('weshop/rma/create?order_id=' . $orderId);
                }
                
                return $this->redirect('weshop/order/list');
            }
            
            // 取消订单
            $orderService->cancelOrder($orderId, $customer->getId());
            
            // 根据是否需要退款显示不同的提示
            if (!empty($checkResult['require_refund'])) {
                $this->getMessageManager()->addSuccess(__('订单已取消，退款将在3-7个工作日内处理'));
            } else {
                $this->getMessageManager()->addSuccess(__('订单已取消'));
            }
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        return $this->redirect('weshop/order/list');
    }
}
