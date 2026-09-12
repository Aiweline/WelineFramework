<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\DevRelayGateService;
use Weline\Payment\Service\DevRelaySettingsService;

/**
 * 货源 Webhook 中继说明页：复用支付 DevRelay，不另建一套会话/SSE。
 */
#[Acl('Weline_Dropship::commerce:dropship:dev_relay', '货源 Webhook 中继', 'webhook', '复用支付 DevRelay 转发货源回调', 'Weline_Dropship::commerce:dropship:group')]
class DevRelay extends BackendController
{
    public function index(): string
    {
        $paymentRelayUrl = (string)$this->request->getUrlBuilder()->getBackendUrl('payment/backend/dev-relay');
        $gateConfig = [];
        $isOnlineHost = false;
        $relayReady = false;
        try {
            if (class_exists(DevRelayGateService::class)) {
                /** @var DevRelayGateService $gate */
                $gate = ObjectManager::getInstance(DevRelayGateService::class);
                $gateConfig = $gate->config();
                $isOnlineHost = $gate->isOnlineRelayHost();
                $relayReady = $isOnlineHost;
            }
        } catch (\Throwable) {
            $gateConfig = [];
        }

        $onlineBase = rtrim(trim((string)($gateConfig['online_base_url'] ?? '')), '/');
        if ($onlineBase === '') {
            $onlineBase = 'https://www.aiweline.com';
        }
        if (class_exists(DevRelaySettingsService::class)) {
            try {
                $saved = ObjectManager::getInstance(DevRelaySettingsService::class)->get();
                $fromSaved = rtrim(trim((string)($saved['online_base_url'] ?? '')), '/');
                if ($fromSaved !== '') {
                    $onlineBase = $fromSaved;
                }
            } catch (\Throwable) {
            }
        }
        $siteBase = rtrim(trim((string)$this->request->getBaseHost()), '/');
        if ($siteBase !== '' && !$this->looksLocalHost($siteBase)) {
            $onlineBase = $siteBase;
        }

        $cjSandboxHook = $onlineBase . '/dropship/frontend/callback/notify?endpoint_code=' . rawurlencode('cj.sandbox.default');
        $cjLiveHook = $onlineBase . '/dropship/frontend/callback/notify?endpoint_code=' . rawurlencode('cj.prod.default');

        return $this->fetch('Weline_Dropship::templates/Backend/DevRelay/index.phtml', [
            'page_title' => (string)__('货源 Webhook 中继'),
            'payment_relay_url' => $paymentRelayUrl,
            'relay_ready' => $relayReady,
            'gate_config' => $gateConfig,
            'cj_sandbox_hook' => $cjSandboxHook,
            'cj_live_hook' => $cjLiveHook,
            'online_base' => $onlineBase,
        ]);
    }

    private function looksLocalHost(string $base): bool
    {
        $host = (string)(parse_url($base, PHP_URL_HOST) ?: $base);
        $host = strtolower($host);

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.weline.test')
            || str_ends_with($host, '.test.weline.com');
    }
}
