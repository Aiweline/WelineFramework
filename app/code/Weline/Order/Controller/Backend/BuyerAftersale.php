<?php

declare(strict_types=1);

namespace Weline\Order\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Service\BuyerAftersaleConfig;
use Weline\Order\Service\BuyerAftersaleService;

/**
 * 买家退换货列表页。通过、拒绝、收货、重发运单走 buyer-aftersale-admin 查询，不在本控制器写资料。
 */
#[Acl('Weline_Order::buyer_aftersale_controller', '买家退换货', 'undo', '查看买家退换货申请', 'Weline_Backend::order_group')]
final class BuyerAftersale extends BackendPageController
{
    #[Acl('Weline_Order::buyer_aftersale_manage', '买家退换货', 'list', '查看并处理买家退换货申请', 'Weline_Backend::order_group')]
    public function index(): string
    {
        $status = trim((string)$this->request->getParam('status', ''));
        $keyword = trim((string)$this->request->getParam('q', ''));
        /** @var BuyerAftersaleConfig $config */
        $config = ObjectManager::getInstance(BuyerAftersaleConfig::class);
        /** @var BuyerAftersaleService $service */
        $service = ObjectManager::getInstance(BuyerAftersaleService::class);
        $this->assign('buyerAftersaleEnabled', $config->isBuyerAftersaleEnabled());
        $this->assign('requests', $service->listForAdmin($status, $keyword));
        $this->assign('filterStatus', $status);
        $this->assign('filterKeyword', $keyword);

        return $this->fetch();
    }
}
