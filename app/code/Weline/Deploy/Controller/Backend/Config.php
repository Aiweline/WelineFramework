<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller\Backend;

use Weline\Deploy\Service\DeployConfigService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;

#[Acl('Weline_Deploy::deploy_config', '部署配置', 'mdi mdi-source-branch', '管理部署仓库、Webhook 与缓存清理配置', 'Weline_Backend::system_maintenance')]
class Config extends BackendController
{
    public function __construct(
        private readonly DeployConfigService $deployConfigService
    ) {
    }

    #[Acl('Weline_Deploy::deploy_config_index', '查看部署配置', 'mdi mdi-source-branch', '查看部署信息配置')]
    public function index(): string
    {
        $settings = $this->deployConfigService->getSettings();
        foreach (DeployConfigService::SECRET_KEYS as $secretKey) {
            if (($settings[$secretKey] ?? '') !== '') {
                $settings[$secretKey . '_configured'] = '1';
            }
            $settings[$secretKey] = '';
        }

        $this->assign('settings', $settings);
        $this->assign('saveUrl', $this->_url->getBackendUrl('deploy/backend/config/save'));

        return (string)$this->fetch();
    }

    #[Acl('Weline_Deploy::deploy_config_save', '保存部署配置', 'mdi mdi-content-save', '保存部署信息配置')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            MessageManager::warning((string)__('请求方式错误'));
            $this->redirect($this->_url->getBackendUrl('deploy/backend/config'));
            return '';
        }

        try {
            $existing = $this->deployConfigService->getStoredSettings();
            $data = $this->request->getPost();
            $settings = [];
            foreach (array_keys($this->deployConfigService->getDefaults()) as $key) {
                $value = $data[$key] ?? '';
                if (in_array($key, DeployConfigService::SECRET_KEYS, true) && trim((string)$value) === '') {
                    $settings[$key] = $existing[$key] ?? '';
                    continue;
                }
                $settings[$key] = is_array($value) ? '' : (string)$value;
            }

            $this->deployConfigService->saveSettings($settings);
            MessageManager::success((string)__('部署配置已保存'));
        } catch (\Throwable $throwable) {
            MessageManager::error((string)__('部署配置保存失败：%{1}', [$throwable->getMessage()]));
        }

        $this->redirect($this->_url->getBackendUrl('deploy/backend/config'));
        return '';
    }
}
