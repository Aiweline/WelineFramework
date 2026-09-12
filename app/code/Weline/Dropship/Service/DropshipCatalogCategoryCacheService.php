<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Interface\DropshipCatalogBrowseProviderInterface;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;

/**
 * 选品分类本地缓存（壳层通用：CJ / Fake / 其它 Browse Provider）。
 *
 * 按 provider + locale + country 分键；命中则不再打远程 listCategories。
 */
final class DropshipCatalogCategoryCacheService
{
    public const CACHE_POOL = 'dropship_catalog_categories';
    /** 分类变更少，默认缓存 24h。 */
    public const TTL_SECONDS = 86400;

    private CachePoolInterface $cache;

    public function __construct(CacheManager|CachePoolInterface $cacheOrManager)
    {
        if ($cacheOrManager instanceof CachePoolInterface) {
            $this->cache = $cacheOrManager;
        } else {
            $this->cache = $cacheOrManager->pool(self::CACHE_POOL);
        }
    }

    public static function cacheKey(string $providerCode, string $locale, string $countryCode, bool $useCountry): string
    {
        $providerCode = strtolower(trim($providerCode));
        $locale = trim(str_replace('-', '_', $locale));
        if ($locale === '') {
            $locale = '_';
        }
        $country = $useCountry ? strtoupper(trim($countryCode)) : '_';
        if ($country === '') {
            $country = '_';
        }

        return 'cats:' . $providerCode . ':' . $locale . ':' . $country;
    }

    /**
     * @param array{locale?:string,country_code?:string} $query
     * @return array{categories:list<array<string,mixed>>,cache:string}
     *   cache = hit|miss|bypass|error
     */
    public function getCategories(
        DropshipCatalogBrowseProviderInterface $provider,
        string $providerCode,
        array $query,
        bool $useCountryFilter,
        bool $forceRefresh = false
    ): array {
        $providerCode = trim($providerCode);
        if ($providerCode === '') {
            $providerCode = method_exists($provider, 'getCode') ? (string)$provider->getCode() : 'unknown';
        }
        $locale = trim((string)($query['locale'] ?? ''));
        $country = strtoupper(trim((string)($query['country_code'] ?? '')));
        $key = self::cacheKey($providerCode, $locale, $country, $useCountryFilter);

        if (!$forceRefresh) {
            try {
                $cached = $this->cache->get($key);
                if (is_array($cached) && array_key_exists('categories', $cached) && is_array($cached['categories'])) {
                    /** @var list<array<string,mixed>> $cats */
                    $cats = array_values(array_filter($cached['categories'], 'is_array'));

                    return ['categories' => $cats, 'cache' => 'hit'];
                }
            } catch (\Throwable) {
                // fall through to remote
            }
        }

        try {
            $remote = $provider->listCategories($query);
            if (!is_array($remote)) {
                $remote = [];
            }
            /** @var list<array<string,mixed>> $cats */
            $cats = [];
            foreach ($remote as $row) {
                if (is_array($row)) {
                    $cats[] = $row;
                }
            }
            try {
                $this->cache->set($key, [
                    'categories' => $cats,
                    'cached_at' => time(),
                    'provider' => $providerCode,
                ], self::TTL_SECONDS);
            } catch (\Throwable) {
                // ignore write failure
            }

            return ['categories' => $cats, 'cache' => $forceRefresh ? 'bypass' : 'miss'];
        } catch (\Throwable) {
            return ['categories' => [], 'cache' => 'error'];
        }
    }

    public function forget(string $providerCode, string $locale = '', string $countryCode = '', bool $useCountry = true): void
    {
        try {
            $this->cache->delete(self::cacheKey($providerCode, $locale, $countryCode, $useCountry));
        } catch (\Throwable) {
        }
    }
}
