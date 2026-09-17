<?php

declare(strict_types=1);

namespace Weline\Smtp\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

/**
 * 邮件配置：Smtp 侧栏入口，用分组 config:embed 管理邮件壳背景等配置项。
 */
#[Acl('Weline_Smtp::system_smtp_mail_config', '邮件配置', 'settings', '邮件配置项管理', 'Weline_Smtp::system_smtp')]
class MailConfig extends BackendController
{
    #[Acl('Weline_Smtp::smtp_mail_config_index', '查看邮件配置', 'settings', '查看邮件配置项', 'Weline_Smtp::system_smtp_mail_config')]
    public function index(): string
    {
        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
        $get = $this->request->getGet();
        $resolved = $targetScopeService->resolveFromInput([
            'target_scope' => (string)($get['target_scope'] ?? ''),
            'scope' => (string)($get['scope'] ?? ''),
            'website_code' => (string)($get['website_code'] ?? ''),
            'store_code' => (string)($get['store_code'] ?? ''),
            'channel_code' => (string)($get['channel_code'] ?? ''),
        ], false);

        $storageScope = (string)($resolved['storage_scope'] ?? 'default.default.default');
        $hasExplicit = trim((string)($get['target_scope'] ?? '')) !== ''
            || trim((string)($get['scope'] ?? '')) !== ''
            || array_key_exists('website_code', $get);

        if (!$hasExplicit) {
            return $this->redirect($this->request->getUrlBuilder()->getBackendUrl(
                'smtp/backend/mail-config',
                [
                    'target_scope' => $storageScope,
                    'website_code' => (string)($resolved['website_code'] ?? ''),
                    'store_code' => (string)($resolved['store_code'] ?? ''),
                    'channel_code' => (string)($resolved['channel_code'] ?? ''),
                ]
            ));
        }

        $this->assign('page_title', __('邮件配置'));
        $this->assign('selected_scope', $storageScope);
        $this->assign('target_scope', $storageScope);
        $this->assign('scope_website_code', (string)($resolved['website_code'] ?? ''));
        $this->assign('scope_store_code', (string)($resolved['store_code'] ?? ''));
        $this->assign('scope_channel_code', (string)($resolved['channel_code'] ?? ''));

        return $this->fetch('Weline_Smtp::Backend/MailConfig');
    }
}
