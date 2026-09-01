<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Env;
use Weline\Payment\Model\PaymentDevRelaySession;

/**
 * DevRelay 后台统一配置。
 *
 * 存 var/payment-dev-relay-settings.json（由控制台开关写入），
 * 不要求改 app/etc/env.php；也不强依赖 SystemConfig 表结构。
 */
final class DevRelaySettingsService
{
    private const FILE = 'var/payment-dev-relay-settings.json';

    /**
     * @return array{
     *   enabled:bool,
     *   allow_on_production:bool,
     *   allow_server_push:bool,
     *   session_ttl_seconds:int,
     *   outbound_mode:string,
     *   online_base_url:string,
     *   source:string
     * }
     */
    public function get(): array
    {
        $stored = $this->readFile();
        if ($stored !== null) {
            return $this->normalize($stored, 'file');
        }

        $legacy = $this->readLegacyEnv();
        if (!empty($legacy['enabled']) || !empty($legacy['allow_on_production']) || ($legacy['online_base_url'] ?? '') !== '') {
            try {
                $this->save($legacy);
            } catch (\Throwable) {
                return $this->normalize($legacy, 'env_legacy');
            }

            return $this->normalize($legacy, 'env_migrated');
        }

        return $this->normalize([], 'default');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $normalized = $this->normalize($input, 'file');
        if ($this->isProductionLive() && !empty($normalized['enabled'])) {
            $normalized['allow_on_production'] = true;
        }

        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException((string) __('无法创建 DevRelay 配置目录。'));
        }

        $payload = [
            'enabled' => !empty($normalized['enabled']),
            'allow_on_production' => !empty($normalized['allow_on_production']),
            'allow_server_push' => !empty($normalized['allow_server_push']),
            'session_ttl_seconds' => (int) $normalized['session_ttl_seconds'],
            'outbound_mode' => (string) $normalized['outbound_mode'],
            'online_base_url' => (string) $normalized['online_base_url'],
            'updated_at' => date('c'),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException((string) __('无法写入 DevRelay 配置文件。'));
        }
        @chmod($path, 0664);

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readFile(): ?array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    private function path(): string
    {
        return rtrim((string) BP, '/') . '/' . self::FILE;
    }

    /**
     * @return array<string, mixed>
     */
    private function readLegacyEnv(): array
    {
        $payment = Env::get('payment', []);
        if (!\is_array($payment)) {
            $payment = [];
        }
        $relay = $payment['dev_relay'] ?? [];
        if (!\is_array($relay)) {
            $relay = [];
        }

        return [
            'enabled' => !empty($relay['enabled']),
            'allow_on_production' => !empty($relay['allow_on_production']),
            'allow_server_push' => !empty($relay['allow_server_push']),
            'session_ttl_seconds' => (int) ($relay['session_ttl_seconds'] ?? 28800),
            'outbound_mode' => (string) ($relay['outbound_mode'] ?? PaymentDevRelaySession::OUTBOUND_LOCAL_DIRECT),
            'online_base_url' => rtrim(trim((string) ($relay['online_base_url'] ?? '')), '/'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   enabled:bool,
     *   allow_on_production:bool,
     *   allow_server_push:bool,
     *   session_ttl_seconds:int,
     *   outbound_mode:string,
     *   online_base_url:string,
     *   source:string
     * }
     */
    private function normalize(array $input, string $source): array
    {
        $mode = strtolower(trim((string) ($input['outbound_mode'] ?? PaymentDevRelaySession::OUTBOUND_LOCAL_DIRECT)));
        if ($mode !== PaymentDevRelaySession::OUTBOUND_ONLINE_PROXY) {
            $mode = PaymentDevRelaySession::OUTBOUND_LOCAL_DIRECT;
        }

        return [
            'enabled' => !empty($input['enabled']),
            'allow_on_production' => !empty($input['allow_on_production']),
            'allow_server_push' => !empty($input['allow_server_push']),
            'session_ttl_seconds' => max(300, (int) ($input['session_ttl_seconds'] ?? 28800)),
            'outbound_mode' => $mode,
            'online_base_url' => rtrim(trim((string) ($input['online_base_url'] ?? '')), '/'),
            'source' => $source,
        ];
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
}
