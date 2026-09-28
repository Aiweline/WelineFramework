<?php

declare(strict_types=1);

namespace Weline\Cdn\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

/**
 * CDN 系统配置：内嵌 Weline_Cdn 已声明的 SystemConfig（含 Cloudflare OAuth 等）。
 */
#[Acl('Weline_Cdn::cdn_system_config', 'CDN系统配置', 'settings', 'CDN模块系统配置', 'Weline_Cdn::cdn_manager')]
class Config extends BackendController
{
    #[Acl('Weline_Cdn::cdn_system_config_index', '查看CDN系统配置', 'settings', '查看CDN模块系统配置')]
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

        $this->assign('page_title', __('CDN系统配置'));
        $this->assign('selected_scope', $storageScope);
        $this->assign('target_scope', $storageScope);
        $this->assign('scope_website_code', (string)($resolved['website_code'] ?? ''));
        $this->assign('scope_store_code', (string)($resolved['store_code'] ?? ''));
        $this->assign('scope_channel_code', (string)($resolved['channel_code'] ?? ''));

        return $this->fetch();
    }
}
