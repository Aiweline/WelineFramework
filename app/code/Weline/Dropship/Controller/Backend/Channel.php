<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Dropship\Service\DropshipChannelConfigDeepLinkBuilder;
use Weline\Dropship\Service\DropshipChannelManager;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

#[Acl('Weline_Dropship::commerce:dropship:channels', '货源平台', 'package', '货源平台列表', 'Weline_Dropship::commerce:dropship:group')]
class Channel extends BackendController
{
    #[Acl('Weline_Dropship::commerce:dropship:channels_index', '查看货源平台', 'package', '查看已注册货源平台')]
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

        /** @var DropshipChannelManager $manager */
        $manager = ObjectManager::getInstance(DropshipChannelManager::class);
        /** @var DropshipChannelConfigDeepLinkBuilder $deepLink */
        $deepLink = ObjectManager::getInstance(DropshipChannelConfigDeepLinkBuilder::class);
        $registered = $manager->registerAllProviders();
        $providers = [];
        foreach ($manager->getProviders() as $p) {
            try {
                $probe = $p->probeConnection();
            } catch (\Throwable $e) {
                $probe = [
                    'ok' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'probe_failed',
                ];
            }
            if (!\is_array($probe)) {
                $probe = ['ok' => false, 'message' => 'probe_invalid'];
            }
            $schema = $p->getConfigSchema();
            $center = \is_array($schema['config_center'] ?? null) ? $schema['config_center'] : [];
            $credentialsUrl = $deepLink->build($center, $storageScope);
            $meta = $p->getDisplayMetadata();
            $embedModule = trim((string)($center['module'] ?? ''));
            $embedArea = trim((string)($center['area'] ?? 'backend'));
            if ($embedArea === '') {
                $embedArea = 'backend';
            }
            $embedGroup = trim((string)($center['group'] ?? ''));
            $embedTitle = trim((string)($center['guide_title'] ?? ''));
            if ($embedTitle === '') {
                $embedTitle = trim((string)($meta['title'] ?? $p->getCode()));
                if ($embedTitle !== '') {
                    $embedTitle .= ' 凭证';
                }
            }
            $credentialsEmbed = null;
            if ($embedModule !== '' && $embedGroup !== '') {
                $credentialsEmbed = [
                    'module' => $embedModule,
                    'area' => $embedArea,
                    'group' => $embedGroup,
                    'title' => $embedTitle !== '' ? $embedTitle : (string)__('配置凭证'),
                ];
            }
            $caps = $p->getCapabilities();
            $webhookUrl = '';
            $webhookEndpoint = '';
            if (!empty($caps['webhook'])) {
                $webhookEndpoint = $p->getCode() . '.sandbox.default';
                $base = (string)$this->request->getUrlBuilder()->getFrontendUrl(
                    'dropship/frontend/callback/notify',
                    ['endpoint_code' => $webhookEndpoint],
                    false
                );
                // Fallback if builder still omits query.
                if ($base !== '' && !str_contains($base, 'endpoint_code=')) {
                    $sep = str_contains($base, '?') ? '&' : '?';
                    $base .= $sep . 'endpoint_code=' . rawurlencode($webhookEndpoint);
                }
                $webhookUrl = $base;
            }
            $providers[] = [
                'code' => $p->getCode(),
                'meta' => $meta,
                'capabilities' => $caps,
                'probe' => $probe,
                'credentials_url' => $credentialsUrl,
                'credentials_embed' => $credentialsEmbed,
                'webhook_url' => $webhookUrl,
                'webhook_endpoint_code' => $webhookEndpoint,
            ];
        }
        $this->assign('page_title', __('货源平台'));
        $this->assign('registered', $registered);
        $this->assign('providers', $providers);
        $this->assign('selected_scope', $storageScope);
        $this->assign('cred_dialog', trim((string)$this->request->getGet('cred_dialog', '')));
        $this->assign('probe_url', $this->request->getUrlBuilder()->getBackendUrl('dropship/backend/channel/probe'));

        return $this->fetch();
    }

    #[Acl('Weline_Dropship::commerce:dropship:channels_probe', '测试货源连接', 'link', '探活已注册货源供应商')]
    public function probe(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        if (!$this->request->isPost()) {
            return json_encode([
                'success' => false,
                'message' => (string)__('请使用 POST 测试连接'),
            ], JSON_UNESCAPED_UNICODE);
        }

        $code = trim((string)$this->request->getPost('provider_code', ''));
        if ($code === '') {
            $code = trim((string)$this->request->getBodyParam('provider_code'));
        }
        if ($code === '') {
            return json_encode([
                'success' => false,
                'message' => (string)__('缺少供应商代码'),
            ], JSON_UNESCAPED_UNICODE);
        }

        /** @var DropshipChannelManager $manager */
        $manager = ObjectManager::getInstance(DropshipChannelManager::class);
        $manager->registerAllProviders();
        $provider = $manager->getProvider($code);
        if ($provider === null) {
            return json_encode([
                'success' => false,
                'message' => (string)__('未找到供应商：%{1}', $code),
            ], JSON_UNESCAPED_UNICODE);
        }

        try {
            $probe = $provider->probeConnection();
        } catch (\Throwable $e) {
            $probe = [
                'ok' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'probe_failed',
            ];
        }
        if (!\is_array($probe)) {
            $probe = ['ok' => false, 'message' => 'probe_invalid'];
        }

        $ok = !empty($probe['ok']);
        $raw = (string)($probe['message'] ?? '');
        $labels = [
            'cj_credentials_missing' => (string)__('缺少凭证'),
            'cj_probe_ok' => (string)__('连接成功'),
            'cj_token_empty' => (string)__('令牌为空'),
            'fake_ok' => (string)__('连接成功'),
            'probe_failed' => (string)__('探活失败'),
            'probe_invalid' => (string)__('探活返回无效'),
        ];
        $message = $ok
            ? ($labels[$raw] ?? ($raw !== '' ? $raw : (string)__('连接成功')))
            : ($labels[$raw] ?? ($raw !== '' ? $raw : (string)__('连接失败')));

        return json_encode([
            'success' => $ok,
            'message' => $message,
            'data' => [
                'provider_code' => $code,
                'raw_message' => $raw,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}
