<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller\Backend;

use Weline\Deploy\Service\DeployConfigService;
use Weline\Deploy\Service\DeployWebhookPierceGate;
use Weline\Deploy\Service\DeployWebhookSetupService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;

#[Acl('Weline_Deploy::deploy_config', '部署配置', 'circle', '管理部署仓库、Webhook 与缓存清理配置', 'Weline_Backend::system_maintenance')]
class Config extends BackendController
{
    public function __construct(
        private readonly DeployConfigService $deployConfigService,
        private readonly DeployWebhookSetupService $deployWebhookSetupService,
        private readonly DeployWebhookPierceGate $pierceGate
    ) {
    }

    #[Acl('Weline_Deploy::deploy_config_index', '查看部署配置', 'circle', '查看部署信息配置')]
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
        $this->assign('webhookMeta', $this->buildWebhookMeta($this->deployConfigService->getSettings()));
        $this->assign('pierceAckPending', $this->pierceGate->isPierceAckPending());
        $this->assign(
            'pierceAckUrl',
            $this->_url->getBackendUrl('deploy/backend/config/confirmPierceUrl')
        );

        return (string)$this->fetch();
    }

    #[Acl('Weline_Deploy::deploy_config_confirm_pierce', '确认 Webhook 签章 URL 已同步', 'check', '开发者确认已更新 Git 平台 Webhook 中的 FPC 签章参数')]
    public function confirmPierceUrl(): string
    {
        if (!$this->request->isPost()) {
            MessageManager::warning((string)__('请求方式错误'));
            $this->redirect($this->_url->getBackendUrl('deploy/backend/config'));

            return '';
        }
        if ($this->pierceGate->acknowledgeCurrentPierce()) {
            MessageManager::success((string)__('已记录：你确认 Git 平台 Webhook 地址中的签章参数已更新。'));
        } else {
            MessageManager::warning((string)__('当前无可用 FPC 签章，或非 WLS 边缘模式，无需确认。'));
        }
        $this->redirect($this->_url->getBackendUrl('deploy/backend/config'));

        return '';
    }

    #[Acl('Weline_Deploy::deploy_config_save', '保存部署配置', 'save', '保存部署信息配置')]
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
                if ($key === 'webhook_path' || $key === DeployWebhookPierceGate::ACK_CONFIG_KEY) {
                    // Path and pierce-ack fingerprint are not form fields; keep stored values.
                    $settings[$key] = (string)($existing[$key] ?? '');
                    continue;
                }
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

    /**
     * @param array<string, mixed> $settings
     * @return array{
     *     url:string,
     *     version_url:string,
     *     path:string,
     *     base_url:string,
     *     base_url_source:string,
     *     setup_command:string,
     *     rotate_path_command:string,
     *     ready:bool
     * }
     */
    private function buildWebhookMeta(array $settings): array
    {
        $baseUrl = $this->resolveCurrentSiteRootUrl();
        $baseUrlSource = $baseUrl !== '' ? (string)__('当前站点请求域名') : '';

        if ($baseUrl === '') {
            $baseInfo = $this->deployWebhookSetupService->resolveSiteBaseUrlInfo(null);
            $baseUrl = $this->normalizeSiteRootUrl(trim((string)($baseInfo['url'] ?? '')));
            $baseUrlSource = (string)($baseInfo['source'] ?? '');
        }

        $webhookUrl = $this->deployWebhookSetupService->buildWebhookUrl(
            $settings,
            null,
            $baseUrl !== '' ? $baseUrl : null
        );
        $versionUrl = $this->deployWebhookSetupService->buildVersionUrl(
            $settings,
            $baseUrl !== '' ? $baseUrl : null
        );
        $path = trim((string)($settings['webhook_path'] ?? ''));
        $setupBase = $baseUrl !== '' ? $baseUrl : 'https://你的域名';

        return [
            'url' => $webhookUrl,
            'version_url' => $versionUrl,
            'path' => $path,
            'base_url' => $baseUrl,
            'base_url_source' => $baseUrlSource,
            'setup_command' => 'php bin/w deploy:webhook:setup --base-url=' . $setupBase,
            'rotate_path_command' => 'php bin/w deploy:webhook:setup --rotate-path -y --base-url=' . $setupBase,
            'ready' => $webhookUrl !== '',
        ];
    }

    private function resolveCurrentSiteRootUrl(): string
    {
        $host = trim((string)($this->request->getServer('HTTP_HOST') ?? ''));
        if ($host === '') {
            return '';
        }

        $scheme = $this->request->getSsl() ? 'https' : 'http';

        return $scheme . '://' . $host;
    }

    private function normalizeSiteRootUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || !isset($parsed['host'])) {
            return rtrim($url, '/');
        }

        $scheme = (string)($parsed['scheme'] ?? 'https');
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return $scheme . '://' . $parsed['host'] . $port;
    }
}
