<?php

declare(strict_types=1);

namespace Weline\B2B\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

/**
 * 批发配置页：本页用 config:embed 嵌入售卖模式启用开关，并支持 URL 范围。
 */
#[Acl('Weline_B2B::config', '批发配置', 'settings', '批发售卖模式启用与关闭', 'Weline_B2B::commerce:partner:control-center')]
class Config extends BackendController
{
    #[Acl('Weline_B2B::config_index', '查看批发配置', 'settings', '查看批发售卖模式配置')]
    public function index(): string
    {
        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
        $resolved = $targetScopeService->resolveFromInput([
            'target_scope' => (string)$this->request->getGet('target_scope', ''),
            'scope' => (string)$this->request->getGet('scope', ''),
            'website_code' => (string)$this->request->getGet('website_code', ''),
            'store_code' => (string)$this->request->getGet('store_code', ''),
            'channel_code' => (string)$this->request->getGet('channel_code', ''),
        ], false);

        $storageScope = (string)($resolved['storage_scope'] ?? 'default.default.default');
        $hasExplicit = trim((string)$this->request->getGet('target_scope', '')) !== ''
            || trim((string)$this->request->getGet('scope', '')) !== ''
            || array_key_exists('website_code', $this->request->getGet());

        if (!$hasExplicit) {
            return $this->redirect($this->request->getUrlBuilder()->getBackendUrl(
                '*/backend/config',
                [
                    'target_scope' => $storageScope,
                    'website_code' => (string)($resolved['website_code'] ?? ''),
                    'store_code' => (string)($resolved['store_code'] ?? ''),
                    'channel_code' => (string)($resolved['channel_code'] ?? ''),
                ]
            ));
        }

        $this->assign('page_title', __('批发配置'));
        $this->assign('selected_scope', $storageScope);
        $this->assign('target_scope', $storageScope);
        $this->assign('scope_website_code', (string)($resolved['website_code'] ?? ''));
        $this->assign('scope_store_code', (string)($resolved['store_code'] ?? ''));
        $this->assign('scope_channel_code', (string)($resolved['channel_code'] ?? ''));

        return $this->fetch();
    }
}
