<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;

/**
 * Local storefront/FPC cleanup for catalog-adjacent side paths (B2B / scripts).
 * Primary product saves go through w_changed → purge_fpc_urls (auto warm).
 * This helper must never rotate the whole FPC disk tree (purgeAll).
 */
final class ProductStorefrontCacheInvalidator
{
    /**
     * Directed URL purge only. Prefer absolute http(s) locs so disk leaves resolve.
     *
     * @param list<string> $urls
     */
    public function purgeForUrls(array $urls, string $reason): void
    {
        $normalized = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $normalized[$url] = $url;
            }
        }
        $normalized = array_values($normalized);
        if ($normalized === []) {
            $normalized = $this->fallbackAbsoluteShells();
        }
        if ($normalized === []) {
            return;
        }

        try {
            FullPageCacheCoordinator::purgeUrls($normalized);
        } catch (\Throwable $e) {
            $this->logFailure('fpc_purge_urls', $reason, $e);
        }
    }

    /**
     * Legacy entry: purge fixed listing shells across website origins (never whole-tree rotate).
     */
    public function clearForCatalogChange(string $reason): void
    {
        $this->purgeForUrls($this->fallbackAbsoluteShells(), $reason);
    }

    /**
     * @return list<string>
     */
    private function fallbackAbsoluteShells(): array
    {
        try {
            /** @var StorefrontCatalogShellUrlBuilder $shells */
            $shells = ObjectManager::getInstance(StorefrontCatalogShellUrlBuilder::class);

            return $shells->absoluteFixedShellsForAllWebsites();
        } catch (\Throwable) {
            return [];
        }
    }

    private function logFailure(string $step, string $reason, \Throwable $e): void
    {
        if (!function_exists('w_log_warning')) {
            return;
        }
        w_log_warning(
            (string) __('商品目录变更后清理前台/FPC 缓存失败：%{1}', [$e->getMessage()]),
            ['step' => $step, 'reason' => $reason],
            'product'
        );
    }
}
