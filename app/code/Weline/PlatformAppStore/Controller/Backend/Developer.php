<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\PlatformAppStore\Service\ModuleAdminService;

#[Acl('Weline_PlatformAppStore::developer', '开发者中心', 'mdi mdi-account-cog', '平台开发者管理', 'Weline_PlatformAppStore::platform')]
class Developer extends BackendController
{
    #[Acl('Weline_PlatformAppStore::developer_view', '查看开发者', 'list', '查看平台开发者列表')]
    public function index(): string
    {
        /** @var ModuleAdminService $admin */
        $admin = ObjectManager::getInstance(ModuleAdminService::class);
        $items = $admin->listDevelopers();

        $this->assign('developers', $items);
        $this->assign('total', count($items));
        $this->assign('page_title', __('开发者中心'));
        $this->assign('title', __('开发者中心'));

        return $this->fetch();
    }
}
