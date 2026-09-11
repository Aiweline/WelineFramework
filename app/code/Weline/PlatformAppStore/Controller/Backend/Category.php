<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\PlatformAppStore\Service\ModuleAdminService;

#[Acl('Weline_PlatformAppStore::category', '模块分类', 'mdi mdi-folder-multiple', '平台模块分类管理', 'Weline_PlatformAppStore::platform')]
class Category extends BackendController
{
    #[Acl('Weline_PlatformAppStore::category_view', '查看分类', 'list', '查看模块分类列表')]
    public function index(): string
    {
        /** @var ModuleAdminService $admin */
        $admin = ObjectManager::getInstance(ModuleAdminService::class);
        $items = $admin->listCategories();

        $this->assign('categories', $items);
        $this->assign('total', count($items));
        $this->assign('page_title', __('模块分类'));
        $this->assign('title', __('模块分类'));

        return $this->fetch();
    }
}
