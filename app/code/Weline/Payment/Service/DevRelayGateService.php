<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Env;
use Weline\Payment\Model\PaymentDevRelaySession;

/**
 * Dev webhook relay 环境门禁。
 *
 * 开关与参数以后台「支付钩子 / DevRelay 控制台」为准（SystemConfig），
 * 不再要求在 app/etc/env.php 手写 payment.dev_relay。
 */
final class DevRelayGateService
{
    public function __construct(
        private readonly DevRelaySettingsService $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $cfg = $this->settings->get();

        return [
            'enabled' => !empty($cfg['enabled']),
            'allow_server_push' => !empty($cfg['allow_server_push']),
            'allow_on_production' => !empty($cfg['allow_on_production']),
            'session_ttl_seconds' => max(300, (int) ($cfg['session_ttl_seconds'] ?? 28800)),
            'outbound_mode' => $this->normalizeOutboundMode((string) ($cfg['outbound_mode'] ?? 'local_direct')),
            'online_base_url' => rtrim(trim((string) ($cfg['online_base_url'] ?? '')), '/'),
            'source' => (string) ($cfg['source'] ?? 'store'),
        ];
    }

    public function isFeatureEnabled(): bool
    {
        return $this->config()['enabled'] === true;
    }

    public function isAllowed(): bool
    {
        if (!$this->isFeatureEnabled()) {
            return false;
        }

        if ($this->isProductionLive()) {
            return !empty($this->config()['allow_on_production']);
        }

        return true;
    }

    /**
     * 控制台页可打开（含未启用时展示开关），与 isAllowed() 分离。
     */
    public function canOpenConsolePage(): bool
    {
        return true;
    }

    public function isLocalEnvironment(): bool
    {
        $systemEnv = strtolower(trim((string) Env::get('system.env', '')));
        $deploy = strtolower(trim((string) Env::get('deploy', '')));
        if ($deploy === '') {
            $deploy = strtolower(trim((string) Env::get('system.deploy', '')));
        }

        if (\in_array($systemEnv, ['local', 'dev', 'development'], true)) {
            return true;
        }

        return \in_array($deploy, ['dev', 'development', 'local'], true);
    }

    public function isOnlineRelayHost(): bool
    {
        return $this->isAllowed() && !$this->isLocalEnvironment();
    }

    public function canOpenUi(): bool
    {
        return $this->isAllowed();
    }

    public function isOnlineProxyOutboundEnabled(?string $sessionOutboundMode = null): bool
    {
        $mode = $sessionOutboundMode !== null && $sessionOutboundMode !== ''
            ? $this->normalizeOutboundMode($sessionOutboundMode)
            : (string) $this->config()['outbound_mode'];

        return $mode === PaymentDevRelaySession::OUTBOUND_ONLINE_PROXY;
    }

    private function isProductionLive(): bool
    {
        $systemEnv = strtolower(trim((string) Env::get('system.env', '')));
        if ($systemEnv === 'production' || $systemEnv === 'prod') {
            return true;
        }

        $deploy = strtolower(trim((string) Env::get('deploy', '')));
        if ($deploy === '') {
            $deploy = strtolower(trim((string) Env::get('system.deploy', '')));
        }

        return $deploy === 'production' || $deploy === 'prod';
    }

    private function normalizeOutboundMode(string $mode): string
    {
        return strtolower(trim($mode)) === PaymentDevRelaySession::OUTBOUND_ONLINE_PROXY
            ? PaymentDevRelaySession::OUTBOUND_ONLINE_PROXY
            : PaymentDevRelaySession::OUTBOUND_LOCAL_DIRECT;
    }
}
