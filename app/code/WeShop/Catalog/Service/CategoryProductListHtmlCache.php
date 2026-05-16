<?php

declare(strict_types=1);

namespace WeShop\Catalog\Service;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\MemoryStateFacade;

final class CategoryProductListHtmlCache
{
    private const TTL = 300;

    /** @var array<string, array{expires_at: float, html: string}> */
    private static array $cache = [];
    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;

    public static function get(string $key): ?string
    {
        if (isset(self::$cache[$key])) {
            if (self::$cache[$key]['expires_at'] >= microtime(true)) {
                return self::$cache[$key]['html'];
            }
            unset(self::$cache[$key]);
        }

        $cache = self::runtimeCache();
        if ($cache === null) {
            return null;
        }

        try {
            $html = $cache->get('weshop_catalog_runtime', 'category.products_html.' . $key);
            if (is_string($html)) {
                self::$cache[$key] = [
                    'expires_at' => microtime(true) + self::ttl(),
                    'html' => $html,
                ];
                return $html;
            }
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }

        return null;
    }

    public static function set(string $key, string $html): void
    {
        if (count(self::$cache) > 64) {
            self::$cache = array_slice(self::$cache, -32, null, true);
        }

        self::$cache[$key] = [
            'expires_at' => microtime(true) + self::ttl(),
            'html' => $html,
        ];
        $cache = self::runtimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->set('weshop_catalog_runtime', 'category.products_html.' . $key, $html, self::ttl());
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
    }

    public static function clear(): void
    {
        self::$cache = [];
    }

    private static function runtimeCache(): ?MemoryStateFacade
    {
        if (self::$runtimeCacheResolved) {
            return self::$runtimeCache;
        }
        self::$runtimeCacheResolved = true;

        if (!class_exists(Runtime::class, false) || !Runtime::isPersistent() || !class_exists(MemoryStateFacade::class)) {
            return null;
        }

        try {
            /** @var RuntimeCachePolicy $policy */
            $policy = ObjectManager::getInstance(RuntimeCachePolicy::class);
            self::$runtimeCache = new MemoryStateFacade($policy->memoryOptions([
                'consumer_code' => 'weshop_catalog_runtime',
                'prefer_direct_connect' => true,
                'persistent' => true,
                'lazy_connect' => true,
            ]));
        } catch (\Throwable) {
            self::$runtimeCache = null;
        }

        return self::$runtimeCache;
    }

    private static function ttl(): int
    {
        try {
            /** @var RuntimeCachePolicy $policy */
            $policy = ObjectManager::getInstance(RuntimeCachePolicy::class);
            return $policy->ttl('page.category_view_ttl', self::TTL);
        } catch (\Throwable) {
            return self::TTL;
        }
    }
}
