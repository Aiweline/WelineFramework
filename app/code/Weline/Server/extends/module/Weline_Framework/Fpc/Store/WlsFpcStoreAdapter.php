<?php

declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Framework\Fpc\Store;

use Weline\Framework\Http\Fpc\FpcStoreAdapterInterface;
use Weline\Framework\Router\FullPageCacheCoordinator;

/**
 * WLS FPC 存储适配器：process L1 + shared/pool + 外置 payload（经路径索引按 URL 清盘）。
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
            if (\class_exists(FullPageCacheCoordinator::class)) {
                FullPageCacheCoordinator::purgeUrls($urls);
            }
        } catch (\Throwable) {
            $this->clearProcessCache();
        }
    }

    public function purgeAll(string $reason = ''): void
    {
        $this->clearProcessCache();
        try {
            $manager = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Cache\CacheManager::class
            );
            foreach (['fpc', 'router'] as $pool) {
                if (!\method_exists($manager, 'hasPool') || !$manager->hasPool($pool)) {
                    continue;
                }
                $manager->pool($pool)->clear();
            }
        } catch (\Throwable) {
        }
        try {
            $this->purgePayloadTree(
                BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads'
            );
            $this->purgePayloadTree(
                BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-path-index'
            );
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

    private function purgePayloadTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $node) {
            if (!$node instanceof \SplFileInfo) {
                continue;
            }
            $path = $node->getPathname();
            if ($node->isDir()) {
                @\rmdir($path);
            } elseif ($node->isFile()) {
                @\unlink($path);
            }
        }
    }
}
