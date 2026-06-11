<?php
declare(strict_types=1);

namespace Weline\Multipass\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Multipass\Model\IdentityProvider as IdentityProviderModel;
use Weline\Multipass\Service\IdentityClientService;

#[Acl('Weline_Multipass::identity_provider_management', '官网登录提供方', 'mdi mdi-login-variant', '配置本站通过官网授权登录', 'Weline_Multipass::menu_multipass_management')]
class IdentityProvider extends BackendController
{
    private ?IdentityClientService $identityClientService = null;

    public function index(): string
    {
        $this->assign([
            'providers' => $this->getIdentityClientService()->listProviders(),
            'page_title' => __('官网登录提供方'),
        ]);

        return (string) $this->fetch();
    }

    #[Acl('Weline_Multipass::identity_provider_save', '保存官网登录提供方', 'mdi mdi-content-save', '保存本站官网登录提供方配置', 'Weline_Multipass::identity_provider_management')]
    public function postSave(): string
    {
        try {
            $this->getIdentityClientService()->saveProvider([
                'provider_id' => (int) ($this->request->getPost('provider_id') ?? 0),
                'name' => (string) ($this->request->getPost('name') ?? ''),
                'issuer_base_url' => (string) ($this->request->getPost('issuer_base_url') ?? ''),
                'rest_base_url' => (string) ($this->request->getPost('rest_base_url') ?? ''),
                'client_id' => (string) ($this->request->getPost('client_id') ?? ''),
                'client_secret' => (string) ($this->request->getPost('client_secret') ?? ''),
                'redirect_uri' => (string) ($this->request->getPost('redirect_uri') ?? ''),
                'scopes' => (array) ($this->request->getPost('scopes') ?? []),
                'status' => (string) ($this->request->getPost('status') ?? IdentityProviderModel::STATUS_ACTIVE),
                'sort_order' => (int) ($this->request->getPost('sort_order') ?? 100),
            ]);
            MessageManager::success(__('官网登录提供方已保存'));
        } catch (\Throwable $e) {
            MessageManager::error(__('保存失败：%{1}', [$e->getMessage()]));
        }

        return (string) $this->redirect('*/multipass/backend/identity-provider/index');
    }

    #[Acl('Weline_Multipass::identity_provider_delete', '删除官网登录提供方', 'mdi mdi-delete', '删除本站官网登录提供方配置', 'Weline_Multipass::identity_provider_management')]
    public function postDelete(): string
    {
        $providerId = (int) ($this->request->getPost('provider_id') ?? 0);
        if ($this->getIdentityClientService()->deleteProvider($providerId)) {
            MessageManager::success(__('官网登录提供方已删除'));
        } else {
            MessageManager::error(__('官网登录提供方不存在'));
        }

        return (string) $this->redirect('*/multipass/backend/identity-provider/index');
    }

    private function getIdentityClientService(): IdentityClientService
    {
        if ($this->identityClientService === null) {
            $this->identityClientService = ObjectManager::getInstance(IdentityClientService::class);
        }

        return $this->identityClientService;
    }
}
