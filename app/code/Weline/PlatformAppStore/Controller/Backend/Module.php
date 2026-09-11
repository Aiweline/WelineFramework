<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\PlatformAppStore\Service\ModuleAdminService;

#[Acl('Weline_PlatformAppStore::module', '模块管理', 'mdi mdi-puzzle', '平台应用商店模块管理', 'Weline_PlatformAppStore::platform')]
class Module extends BackendController
{
    #[Acl('Weline_PlatformAppStore::module_view', '查看模块', 'list', '查看平台模块列表')]
    public function index(): string
    {
        /** @var ModuleAdminService $admin */
        $admin = ObjectManager::getInstance(ModuleAdminService::class);
        $result = $admin->listModules([
            'q' => (string)$this->request->getGet('q', ''),
            'status' => (string)$this->request->getGet('status', ''),
            'pricing_type' => (string)$this->request->getGet('pricing_type', ''),
            'page' => (int)$this->request->getGet('page', 1),
            'page_size' => (int)$this->request->getGet('page_size', 20),
        ]);

        $createUrl = $this->request->getUrlBuilder()->getBackendUrl('platform-appstore/backend/module/edit');
        $hasFilters = $admin->hasActiveFilters($result['filters']);
        $this->assign('modules', $result['items']);
        $this->assign('total', $result['total']);
        $this->assign('filters', $result['filters']);
        $this->assign('empty_state', $admin->emptyStateMeta($hasFilters && $result['total'] === 0));
        $this->assign('create_url', $createUrl);
        $this->assign('list_url', $this->request->getUrlBuilder()->getBackendUrl('platform-appstore/backend/module'));
        $this->assign('page_title', __('模块管理'));
        $this->assign('title', __('模块管理'));

        return $this->fetch();
    }

    #[Acl('Weline_PlatformAppStore::module_edit', '编辑模块', 'edit', '新建或编辑平台模块草稿')]
    public function edit(): string
    {
        $this->assign('form', [
            'name' => '',
            'display_name' => '',
            'description' => '',
        ]);
        $this->assign('save_url', $this->request->getUrlBuilder()->getBackendUrl('platform-appstore/backend/module/save'));
        $this->assign('list_url', $this->request->getUrlBuilder()->getBackendUrl('platform-appstore/backend/module'));
        $this->assign('page_title', __('新建模块'));
        $this->assign('title', __('新建模块'));

        return $this->fetch('Weline_PlatformAppStore::templates/Backend/Module/edit.phtml');
    }

    #[Acl('Weline_PlatformAppStore::module_save', '保存模块', 'save', '保存平台模块草稿')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('platform-appstore/backend/module/edit'));
        }

        try {
            /** @var ModuleAdminService $admin */
            $admin = ObjectManager::getInstance(ModuleAdminService::class);
            $admin->createDraft([
                'name' => (string)$this->request->getPost('name', ''),
                'display_name' => (string)$this->request->getPost('display_name', ''),
                'description' => (string)$this->request->getPost('description', ''),
            ]);
            $this->getMessageManager()->addSuccess((string)__('模块草稿已创建。'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
            return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('platform-appstore/backend/module/edit'));
        }

        return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('platform-appstore/backend/module'));
    }
}
