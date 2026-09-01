<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\WlsRuntimeAdapterInterface;

/**
 * 活动主题保存后的本地前台/FPC 缓存清理。
 * CDN 清理留给 Weline_Framework::resource_changed 的 CDN Observer。
 */
final class PromotionStorefrontCacheInvalidator
{
    public function clearForTheme(string $reason): void
    {
        $this->clearProcessFpc($reason);
        $this->clearRouterFpcPools($reason);
        $this->clearWlsSharedState($reason);
        $this->broadcastWorkers($reason);
        $this->purgeRouterFpcPayloadFiles($reason);
    }

    private function clearProcessFpc(string $reason): void
    {
        try {
            if (class_exists(FullPageCacheCoordinator::class)) {
                FullPageCacheCoordinator::clearProcessCache();
            }
        } catch (\Throwable $e) {
            $this->logFailure('fpc_process_cache', $reason, $e);
        }
    }

    private function clearRouterFpcPools(string $reason): void
    {
        try {
            $cacheManager = ObjectManager::getInstance(CacheManager::class);
            foreach (['fpc', 'router'] as $pool) {
                if (!method_exists($cacheManager, 'hasPool') || !$cacheManager->hasPool($pool)) {
                    continue;
                }
                $cacheManager->pool($pool)->clear();
            }
        } catch (\Throwable $e) {
            $this->logFailure('router_fpc_pools', $reason, $e);
        }
    }

    private function clearWlsSharedState(string $reason): void
    {
        try {
            $adapter = $this->runtimeProvider(WlsRuntimeAdapterInterface::class);
            if (!$adapter instanceof WlsRuntimeAdapterInterface) {
                return;
            }
            $facade = $adapter->createSharedState([
                'consumer_code' => $reason,
                'prefer_direct_connect' => true,
                'pool_size' => 1,
                'auto_start' => false,
            ]);
            $facade->clearCache('router');
            $facade->clearCache('fpc');
            $facade->disconnect();
        } catch (\Throwable $e) {
            $this->logFailure('wls_shared_memory', $reason, $e);
        }
    }

    private function broadcastWorkers(string $reason): void
    {
        try {
            $broadcaster = $this->runtimeProvider(RuntimeControlBroadcasterInterface::class);
            if ($broadcaster instanceof RuntimeControlBroadcasterInterface) {
                $broadcaster->cacheClear();
            }
        } catch (\Throwable $e) {
            $this->logFailure('wls_worker_broadcast', $reason, $e);
        }
    }

    private function purgeRouterFpcPayloadFiles(string $reason): void
    {
        $dir = BP . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'router-fpc-payloads';
        $base = realpath(BP);
        $resolved = realpath($dir);
        if ($base === false || $resolved === false || !is_dir($resolved)) {
            return;
        }

        $baseNormalized = strtolower(rtrim(str_replace('\\', '/', $base), '/') . '/');
        $dirNormalized = strtolower(rtrim(str_replace('\\', '/', $resolved), '/') . '/');
        if ($dirNormalized !== $baseNormalized . 'var/cache/router-fpc-payloads/') {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                    continue;
                }
                @unlink($item->getPathname());
            }
        } catch (\Throwable $e) {
            $this->logFailure('router_fpc_payload_files', $reason, $e);
        }
    }

    private function runtimeProvider(string $contract): ?object
    {
        try {
            return ObjectManager::getInstance(RuntimeProviderResolver::class)->resolve($contract);
        } catch (\Throwable) {
            return null;
        }
    }

    private function logFailure(string $step, string $reason, \Throwable $e): void
    {
        if (!function_exists('w_log_warning')) {
            return;
        }
        w_log_warning(
            (string)__('Promotion 清理前台/FPC 缓存失败：%{1}', [$e->getMessage()]),
            ['step' => $step, 'reason' => $reason],
            'promotion'
        );
    }
}
