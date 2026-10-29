<?php

declare(strict_types=1);

namespace Weline\Cron\Controller\Backend\Cron;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

/**
 * 计划任务 CPU 预算配置：内嵌 Weline_Cron 已声明的 SystemConfig（cron/cpu/*）。
 *
 * 类级 ACL 依附菜单 Weline_Cron::cron_system_config（子菜单），parent_source 指向父菜单
 * Weline_Cron::system_cron，两者都在 Cron/etc/backend/menu.xml 里，避免 ACL 树断链。
 */
#[Acl('Weline_Cron::cron_system_config', '计划任务系统配置', 'settings', '计划任务 CPU 预算等系统配置', 'Weline_Cron::system_cron')]
class Config extends BackendController
{
    #[Acl('Weline_Cron::cron_system_config_index', '查看计划任务系统配置', 'settings', '查看计划任务 CPU 预算等系统配置')]
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

        $this->assign('page_title', __('计划任务CPU预算'));
        $this->assign('selected_scope', $storageScope);
        $this->assign('target_scope', $storageScope);
        $this->assign('scope_website_code', (string)($resolved['website_code'] ?? ''));
        $this->assign('scope_store_code', (string)($resolved['store_code'] ?? ''));
        $this->assign('scope_channel_code', (string)($resolved['channel_code'] ?? ''));

        return $this->fetch();
    }
}
