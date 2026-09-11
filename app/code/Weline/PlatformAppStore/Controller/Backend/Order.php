<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\PlatformAppStore\Service\ModuleAdminService;

#[Acl('Weline_PlatformAppStore::order', '订单管理', 'mdi mdi-receipt', '平台订单管理', 'Weline_PlatformAppStore::platform')]
class Order extends BackendController
{
    #[Acl('Weline_PlatformAppStore::order_view', '查看订单', 'list', '查看平台订单列表')]
    public function index(): string
    {
        /** @var ModuleAdminService $admin */
        $admin = ObjectManager::getInstance(ModuleAdminService::class);
        $items = $admin->listOrders();

        $this->assign('orders', $items);
        $this->assign('total', count($items));
        $this->assign('page_title', __('订单管理'));
        $this->assign('title', __('订单管理'));

        return $this->fetch();
    }
}
