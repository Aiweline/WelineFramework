<?php

declare(strict_types=1);

namespace WeShop\Store\Service;

use WeShop\Store\Model\Store;
use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Websites\Data\WebsiteData;

class StoreContextService
{
    private const CACHE_NAMESPACE = 'weline_site_runtime';
    private const STORE_CACHE_TTL = 300;

    /** @var array<string, array{expires_at: float, data: array<string, mixed>}> */
    private static array $storeCache = [];

    /** @var array<string, array{expires_at: float, data: array<int, array<string, mixed>>}> */
    private static array $storeListCache = [];
    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;

    public function __construct(
        private readonly Store $storeModel
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCurrentStore(
        ?int $websiteId = null,
        ?string $locale = null,
        ?string $currency = null
    ): ?array {
        $websiteId ??= $this->resolveWebsiteId();
        $locale = $this->normalizeLocale($locale ?? $this->resolveLocale());
        $currency = $this->normalizeCurrency($currency ?? $this->resolveCurrency());
        $cacheKey = $this->buildCurrentStoreCacheKey($websiteId, $locale, $currency);
        $cachedStore = $this->storeCacheGet($cacheKey);
        if (is_array($cachedStore)) {
            $this->syncStoreScopeContext($cachedStore);
            return $cachedStore;
        }

        $stores = $websiteId > 0
            ? $this->getStoresByWebsiteIdCached($websiteId)
            : $this->getEnabledStoresCached();

        if ((!is_array($stores) || $stores === []) && $websiteId > 0) {
            $stores = $this->getEnabledStoresCached();
        }

        $store = $this->pickBestStore(is_array($stores) ? $stores : [], $websiteId, $locale, $currency);
        if (is_array($store)) {
            $this->syncStoreScopeContext($store);
            $this->storeCacheSet($cacheKey, $store);
        } else {
            ScopeContext::setStoreCode(null);
        }

        return $store;
    }

    protected function resolveWebsiteId(): int
    {
        return (int) (WebsiteData::getWebsiteId() ?? 0);
    }

    protected function resolveLocale(): string
    {
        try {
            return (string) State::getLangLocal();
        } catch (\Throwable) {
            return '';
        }
    }

    protected function resolveCurrency(): string
    {
        try {
            return (string) State::getCurrency();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param array<int, mixed> $stores
     * @return array<string, mixed>|null
     */
    private function pickBestStore(array $stores, int $websiteId, string $locale, string $currency): ?array
    {
        $bestStore = null;
        $bestScore = null;
        $bestSortOrder = null;

        foreach ($stores as $store) {
            if (!is_array($store)) {
                continue;
            }

            $score = $this->scoreStore($store, $websiteId, $locale, $currency);
            $sortOrder = (int) ($store[Store::schema_fields_SORT_ORDER] ?? $store['sort_order'] ?? 0);

            if ($bestStore === null || $score > $bestScore || ($score === $bestScore && $sortOrder < $bestSortOrder)) {
                $bestStore = $store;
                $bestScore = $score;
                $bestSortOrder = $sortOrder;
            }
        }

        return $bestStore;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function scoreStore(array $store, int $websiteId, string $locale, string $currency): int
    {
        $score = 0;

        $storeWebsiteId = (int) ($store[Store::schema_fields_WEBSITE_ID] ?? $store['website_id'] ?? 0);
        if ($websiteId > 0 && $storeWebsiteId === $websiteId) {
            $score += 100;
        }

        $storeLocale = $this->normalizeLocale((string) ($store[Store::schema_fields_LOCAL] ?? $store['local'] ?? ''));
        if ($locale !== '') {
            if ($storeLocale === $locale) {
                $score += 40;
            } elseif ($storeLocale !== '' && $this->extractLanguage($storeLocale) === $this->extractLanguage($locale)) {
                $score += 20;
            }
        }

        $storeCurrency = $this->normalizeCurrency((string) ($store[Store::schema_fields_CURRENCY] ?? $store['currency'] ?? ''));
        if ($currency !== '' && $storeCurrency === $currency) {
            $score += 30;
        }

        if ($storeLocale === '') {
            $score += 5;
        }

        if ($storeCurrency === '') {
            $score += 5;
        }

        return $score;
    }

    private function normalizeLocale(string $locale): string
    {
        return strtolower(str_replace('-', '_', trim($locale)));
    }

    private function normalizeCurrency(string $currency): string
    {
        return strtoupper(trim($currency));
    }

    private function extractLanguage(string $locale): string
    {
        $locale = $this->normalizeLocale($locale);
        if ($locale === '') {
            return '';
        }

        return explode('_', $locale)[0] ?? '';
    }

    private function buildCurrentStoreCacheKey(int $websiteId, string $locale, string $currency): string
    {
        return 'store.current.' . sha1($websiteId . '|' . $locale . '|' . $currency);
    }

    /**
     * @param array<string, mixed> $store
     */
    private function syncStoreScopeContext(array $store): void
    {
        ScopeContext::setStoreCode((string) ($store[Store::schema_fields_CODE] ?? $store['code'] ?? ''));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getStoresByWebsiteIdCached(int $websiteId): array
    {
        return $this->storeListCacheRemember(
            'store.list.website.' . $websiteId,
            fn (): array => $this->normalizeStoreRows($this->storeModel->getStoresByWebsiteId($websiteId))
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getEnabledStoresCached(): array
    {
        return $this->storeListCacheRemember(
            'store.list.enabled',
            fn (): array => $this->normalizeStoreRows($this->storeModel->getEnabledStores())
        );
    }

    /**
     * @param callable(): array<int, array<string, mixed>> $loader
     * @return array<int, array<string, mixed>>
     */
    private function storeListCacheRemember(string $key, callable $loader): array
    {
        $now = microtime(true);
        $cached = self::$storeListCache[$key] ?? null;
        if (is_array($cached) && ($cached['expires_at'] ?? 0.0) >= $now && is_array($cached['data'] ?? null)) {
            return $cached['data'];
        }

        $runtimeCached = $this->runtimeCacheGet($key);
        if (is_array($runtimeCached)) {
            $rows = $this->normalizeStoreRows($runtimeCached);
            self::$storeListCache[$key] = [
                'expires_at' => $now + $this->cacheTtl(),
                'data' => $rows,
            ];
            return $rows;
        }

        $rows = $loader();
        $ttl = $this->cacheTtl();
        self::$storeListCache[$key] = [
            'expires_at' => $now + $ttl,
            'data' => $rows,
        ];
        $this->runtimeCacheSet($key, $rows, $ttl);

        return $rows;
    }

    /**
     * @param mixed $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStoreRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storeCacheGet(string $key): ?array
    {
        $now = microtime(true);
        $cached = self::$storeCache[$key] ?? null;
        if (is_array($cached) && ($cached['expires_at'] ?? 0.0) >= $now && is_array($cached['data'] ?? null)) {
            return $cached['data'];
        }

        $runtimeCached = $this->runtimeCacheGet($key);
        if (is_array($runtimeCached)) {
            self::$storeCache[$key] = [
                'expires_at' => $now + $this->cacheTtl(),
                'data' => $runtimeCached,
            ];
            return $runtimeCached;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function storeCacheSet(string $key, array $store): void
    {
        if (count(self::$storeCache) > 64) {
            self::$storeCache = array_slice(self::$storeCache, -32, null, true);
        }

        $ttl = $this->cacheTtl();
        self::$storeCache[$key] = [
            'expires_at' => microtime(true) + $ttl,
            'data' => $store,
        ];
        $this->runtimeCacheSet($key, $store, $ttl);
    }

    private function runtimeCacheGet(string $key): mixed
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return null;
        }

        try {
            return $cache->get(self::CACHE_NAMESPACE, $key);
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
            return null;
        }
    }

    private function runtimeCacheSet(string $key, mixed $value, int $ttl): void
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->set(self::CACHE_NAMESPACE, $key, $value, max(1, $ttl));
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
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
                'consumer_code' => self::CACHE_NAMESPACE,
                'prefer_direct_connect' => true,
                'persistent' => true,
                'lazy_connect' => true,
            ]));
        } catch (\Throwable) {
            self::$runtimeCache = null;
        }

        return self::$runtimeCache;
    }

    private function cacheTtl(): int
    {
        try {
            /** @var RuntimeCachePolicy $policy */
            $policy = ObjectManager::getInstance(RuntimeCachePolicy::class);
            return $policy->ttl('site.store_ttl', self::STORE_CACHE_TTL);
        } catch (\Throwable) {
            return self::STORE_CACHE_TTL;
        }
    }
}
