<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

use Weline\Framework\App\Env;

/**
 * Resolves the active edge adapter. Invalid values fail closed to nginx.
 */
final class EdgeAdapterResolver
{
    public const DEFAULT_ADAPTER = EdgeAdapterInterface::NAME_NGINX;

    private ?EdgeAdapterInterface $resolved = null;

    private ?string $resolvedName = null;

    /**
     * @param array<string, mixed>|null $envConfig Full env.php array, or null to read Env.
     */
    public function resolve(?array $envConfig = null): EdgeAdapterInterface
    {
        $name = $this->resolveName($envConfig);
        if ($this->resolved !== null && $this->resolvedName === $name) {
            return $this->resolved;
        }

        $this->resolvedName = $name;
        $this->resolved = $name === EdgeAdapterInterface::NAME_WLS
            ? new WlsNativeEdgeAdapter()
            : new NginxEdgeAdapter();

        return $this->resolved;
    }

    /**
     * @param array<string, mixed> $wlsConfig The `wls` section only (as used by server:start).
     */
    public function resolveFromWlsSection(array $wlsConfig): EdgeAdapterInterface
    {
        return $this->resolve(['wls' => $wlsConfig]);
    }

    /**
     * @param array<string, mixed>|null $envConfig
     */
    public function resolveName(?array $envConfig = null): string
    {
        if ($envConfig === null) {
            $raw = Env::getInstance()->getConfig();
            $envConfig = \is_array($raw) ? $raw : [];
        }

        // 未配置 / 空字符串 → nginx（无需在 env.php 写 edge 段）
        if (!\array_key_exists('edge', \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [])
            || !\array_key_exists('adapter', \is_array($envConfig['wls']['edge'] ?? null) ? $envConfig['wls']['edge'] : [])
        ) {
            return self::DEFAULT_ADAPTER;
        }

        $value = $envConfig['wls']['edge']['adapter'] ?? self::DEFAULT_ADAPTER;
        $normalized = \strtolower(\trim((string)$value));
        if ($normalized === '' || $normalized === EdgeAdapterInterface::NAME_NGINX) {
            return EdgeAdapterInterface::NAME_NGINX;
        }
        if ($normalized === EdgeAdapterInterface::NAME_WLS) {
            return EdgeAdapterInterface::NAME_WLS;
        }

        if (\function_exists('w_msg')) {
            w_msg(
                'wls_edge_adapter_invalid',
                'warning',
                __('WLS 边缘适配器配置无效'),
                __('wls.edge.adapter=%{1} 无效，已回退为 nginx。', [$normalized]),
                ['configured' => $normalized]
            );
        }

        return EdgeAdapterInterface::NAME_NGINX;
    }

    public function clearCache(): void
    {
        $this->resolved = null;
        $this->resolvedName = null;
    }
}
