<?php

declare(strict_types=1);

namespace WeShop\Logistics\Controller\Frontend\Tracking;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Logistics\Service\TrackingService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 物流追踪控制器
 */
class View extends FrontendController
{
    /**
     * 物流追踪页面
     */
    public function index(): string
    {
        $orderId = (int)($this->request->getParam('order_id') ?? 0);
        
        if (!$orderId) {
            $this->getMessageManager()->addError(__('订单ID不能为空'));
            return $this->redirect('weshop/order/list');
        }
        
        /** @var TrackingService $trackingService */
        $trackingService = ObjectManager::getInstance(TrackingService::class);
        $tracking = $trackingService->getOrderTracking($orderId);
        
        $this->assign('orderId', $orderId);
        $this->assign('tracking', $tracking);
        
        return $this->fetch();
    }
}
