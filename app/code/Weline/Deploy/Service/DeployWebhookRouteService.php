<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\ModuleRouter\Api\RouteCache;

/**
 * Webhook 公网路径：固定特征前缀 + 随机段，供 ModuleRouter 快速短路匹配。
 */
class DeployWebhookRouteService
{
    /** 路由特征前缀：正常业务 URL 几乎不会命中，便于 O(1) 跳过 */
    public const MARKER = '~wh~';

    private const CACHE_POOL_KEY = 'deploy_webhook_route_paths_v1';

    private static ?array $memoryCache = null;

    public function __construct(
        private readonly DeployConfigService $deployConfigService
    ) {
    }

    public function generatePath(): string
    {
        return self::MARKER . bin2hex(random_bytes(16));
    }

    public function isLegacyOrEmptyPath(string $path): bool
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === '') {
            return true;
        }
        if ($normalized === 'deploy') {
            return true;
        }

        return !str_starts_with($normalized, self::MARKER);
    }

    public function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    public function versionPath(string $webhookPath): string
    {
        $base = $this->normalizePath($webhookPath);
        return $base === '' ? '' : $base . '/version';
    }

    /**
     * @return array{webhook:string,version:string}|null
     */
    public function getResolvedPaths(): ?array
    {
        if (self::$memoryCache !== null) {
            return self::$memoryCache ?: null;
        }

        $cached = w_cache('module_router')->get(self::CACHE_POOL_KEY);
        if (is_array($cached) && isset($cached['webhook'], $cached['version']) && $cached['webhook'] !== '') {
            self::$memoryCache = $cached;
            return $cached;
        }

        $webhook = $this->normalizePath((string)($this->deployConfigService->getSettings()['webhook_path'] ?? ''));
        if ($webhook === '' || $this->isLegacyOrEmptyPath($webhook)) {
            self::$memoryCache = [];
            return null;
        }

        $resolved = [
            'webhook' => $webhook,
            'version' => $this->versionPath($webhook),
        ];
        w_cache('module_router')->set(self::CACHE_POOL_KEY, $resolved);
        self::$memoryCache = $resolved;

        return $resolved;
    }

    public function clearCache(): void
    {
        self::$memoryCache = null;
        w_cache('module_router')->delete(self::CACHE_POOL_KEY);
        RouteCache::clear();
    }
}
