<?php

declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Framework\Fpc\Store;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Http\Fpc\FpcStoreAdapterInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;

/**
 * WLS FPC 存储适配器：process L1 + shared/pool + 外置 payload 目录。
 */
final class WlsFpcStoreAdapter implements FpcStoreAdapterInterface
{
    public function code(): string
    {
        return 'wls';
    }

    public function purgeUrls(array $urls): void
    {
        try {
            $manager = ObjectManager::getInstance(CacheManager::class);
            foreach (['router', 'fpc'] as $poolName) {
                if (!\method_exists($manager, 'hasPool') || !$manager->hasPool($poolName)) {
                    continue;
                }
                $pool = $manager->pool($poolName);
                foreach ($urls as $url) {
                    $path = $this->pathFromUrl((string)$url);
                    if ($path === '') {
                        continue;
                    }
                    if (\method_exists($pool, 'deleteMatching')) {
                        $pool->deleteMatching('*' . \md5($path) . '*');
                    } elseif (\method_exists($pool, 'delete')) {
                        $pool->delete('unified-fpc:' . \hash('sha256', $path));
                        $pool->delete(\hash('sha256', $path));
                    }
                }
            }
        } catch (\Throwable) {
        }
        $this->clearProcessCache();
    }

    public function purgeAll(string $reason = ''): void
    {
        $this->clearProcessCache();
        try {
            $manager = ObjectManager::getInstance(CacheManager::class);
            foreach (['fpc', 'router'] as $pool) {
                if (!\method_exists($manager, 'hasPool') || !$manager->hasPool($pool)) {
                    continue;
                }
                $manager->pool($pool)->clear();
            }
        } catch (\Throwable) {
        }
        try {
            $dir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads';
            if (\is_dir($dir)) {
                foreach (\glob($dir . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    if (\is_file($file)) {
                        @\unlink($file);
                    }
                }
            }
        } catch (\Throwable) {
        }
        unset($reason);
    }

    public function clearProcessCache(): void
    {
        try {
            if (\class_exists(FullPageCacheCoordinator::class)) {
                FullPageCacheCoordinator::clearProcessCache();
            }
        } catch (\Throwable) {
        }
    }

    private function pathFromUrl(string $url): string
    {
        if (\preg_match('#^https?://#i', $url) === 1) {
            $path = \parse_url($url, \PHP_URL_PATH);

            return \is_string($path) && $path !== '' ? $path : '/';
        }

        return $url !== '' && $url[0] === '/' ? $url : '/' . \ltrim($url, '/');
    }
}
