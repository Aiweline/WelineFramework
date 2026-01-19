<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Backend\Order;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 订单详情管理控制器
 */
class View extends BackendController
{
    /**
     * 订单详情
     */
    public function index(): string
    {
        $orderId = (int)($this->request->getParam('id') ?? 0);
        
        if (!$orderId) {
            $this->getMessageManager()->addError(__('订单ID不能为空'));
            return $this->redirect('weshop/backend/order/index');
        }
        
        /** @var OrderService $orderService */
        $orderService = ObjectManager::getInstance(OrderService::class);
        $order = $orderService->getOrder($orderId);
        
        if (!$order) {
            $this->getMessageManager()->addError(__('订单不存在'));
            return $this->redirect('weshop/backend/order/index');
        }
        
        $this->assign('order', $order);
        
        return $this->fetch();
    }
}
