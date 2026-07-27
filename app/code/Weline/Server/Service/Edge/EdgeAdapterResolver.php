<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

use Weline\Framework\App\Env;

/**
 * Resolves the only supported public edge adapter.
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
        $this->resolved = new NginxEdgeAdapter();

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

        // 未配置 / 空字符串 → 项目隔离 Nginx。普通 start 只复用已安装二进制，
        // 绝不下载或编译；安装必须由 server:nginx:install 显式完成。
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

        throw new \InvalidArgumentException(
            'wls.edge.adapter=' . $normalized . ' is retired; Nginx is the only supported public edge.'
        );
    }

    public function clearCache(): void
    {
        $this->resolved = null;
        $this->resolvedName = null;
    }
}
