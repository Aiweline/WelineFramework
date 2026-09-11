<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\PlatformAppStore\Service\ModuleAdminService;

#[Acl('Weline_PlatformAppStore::license', '许可证管理', 'mdi mdi-key-variant', '平台许可证管理', 'Weline_PlatformAppStore::platform')]
class License extends BackendController
{
    #[Acl('Weline_PlatformAppStore::license_view', '查看许可证', 'list', '查看平台许可证列表')]
    public function index(): string
    {
        /** @var ModuleAdminService $admin */
        $admin = ObjectManager::getInstance(ModuleAdminService::class);
        $items = $admin->listLicenses();

        $this->assign('licenses', $items);
        $this->assign('total', count($items));
        $this->assign('page_title', __('许可证管理'));
        $this->assign('title', __('许可证管理'));

        return $this->fetch();
    }
}
