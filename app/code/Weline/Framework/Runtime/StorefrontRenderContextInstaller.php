<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\App\Localization\LocalizationProviderRegistry;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;

/**
 * Installs {@see StorefrontRenderContext} once per request after ScopeIdentity freeze.
 *
 * Idempotent: bag present → no-op. Never leaves a half-installed bag (single atomic set).
 * Skeleton only from RequestContext / ScopeIdentity + LocalizationProviderRegistry.
 * Owning modules fill maintenance / website_table_snapshot / theme_meta via
 * {@see StorefrontRenderContextReader::mergeFields()}.
 */
final class StorefrontRenderContextInstaller
{
    private const PENDING_KEY = 'storefront.render_context.install_pending.v1';

    /**
     * Process-level cache for built snapshots, keyed by the scope triple.
     *
     * buildSnapshot() projects website-localisation rows and resolves the locale
     * catalogue (installed-language list) on every storefront request. Both are
     * global facts for a given (website, store, channel) and measured ~10 ms per
     * request before this cache. The bag installed into StorefrontRenderContext
     * stays request-scoped; only the snapshot it is built from is shared.
     *
     * @var array<string, StorefrontRenderContext>
     */
    private static array $processSnapshotCache = [];

    private const PROCESS_CACHE_MAX = 64;

    /**
     * Drop cached render-context snapshots (website / locale config changed).
     */
    public static function clearProcessCache(): void
    {
        self::$processSnapshotCache = [];
    }

    /**
     * Build one snapshot for a scope triple and store it in the process cache.
     *
     * Called from module preload providers at Worker start-up. It deliberately
     * does NOT install into the request bag (there is no request during
     * warm-up); the request path installs the cached snapshot itself.
     *
     * @return bool whether a snapshot was cached
     */
    public function warmSnapshot(
        int $websiteId,
        string $websiteCode,
        string $websiteUrl,
        string $locale,
        string $currency,
        string $timezone,
    ): bool {
        if ($websiteCode === '') {
            return false;
        }

        $cacheKey = \implode("\n", [$websiteId, $websiteCode, $locale, $currency, $timezone]);
        if (isset(self::$processSnapshotCache[$cacheKey])) {
            return true;
        }

        try {
            $snapshot = $this->buildSnapshotFor(
                $websiteId,
                $websiteCode,
                $websiteUrl,
                $locale,
                $currency,
                $timezone,
            );
        } catch (\Throwable) {
            return false;
        }

        if (!$snapshot->complete) {
            return false;
        }
        if (\count(self::$processSnapshotCache) >= self::PROCESS_CACHE_MAX
            && !isset(self::$processSnapshotCache[$cacheKey])
        ) {
            unset(self::$processSnapshotCache[\array_key_first(self::$processSnapshotCache)]);
        }
        self::$processSnapshotCache[$cacheKey] = $snapshot;

        return true;
    }

    public function installOnce(): StorefrontRenderContext
    {
        $existing = StorefrontRenderContext::current();
        if ($existing instanceof StorefrontRenderContext) {
            return $existing;
        }

        if (!Context::hasCurrent()) {
            return $this->incomplete('', '', 'storefront_render_no_request_context');
        }

        // Re-entrancy / concurrent Fiber install → refuse silent half-bag.
        if (RequestContext::has(self::PENDING_KEY)) {
            return $this->incomplete(
                (string)RequestContext::getWelineWebsiteCode(),
                '',
                'storefront_render_install_reentrant',
            );
        }

        RequestContext::set(self::PENDING_KEY, true);
        try {
            $cacheKey = self::snapshotCacheKey();
            if ($cacheKey !== null && isset(self::$processSnapshotCache[$cacheKey])) {
                $cached = self::$processSnapshotCache[$cacheKey];
                StorefrontRenderContext::install($cached);

                return $cached;
            }

            $built = $this->buildSnapshot();
            if ($cacheKey !== null && $built->complete) {
                if (\count(self::$processSnapshotCache) >= self::PROCESS_CACHE_MAX
                    && !isset(self::$processSnapshotCache[$cacheKey])
                ) {
                    unset(self::$processSnapshotCache[\array_key_first(self::$processSnapshotCache)]);
                }
                self::$processSnapshotCache[$cacheKey] = $built;
            }
            StorefrontRenderContext::install($built);

            return $built;
        } catch (\Throwable $e) {
            $failed = $this->incomplete(
                trim(RequestContext::getWelineWebsiteCode()),
                trim(RequestContext::getWelineWebsiteUrl()),
                'storefront_render_install_failed',
            );
            StorefrontRenderContext::install($failed);
            return $failed;
        } finally {
            RequestContext::remove(self::PENDING_KEY);
        }
    }

    /**
     * Contract alias — same as {@see installOnce()} (freeze after ScopeIdentity).
     */
    public function freezeCurrent(): StorefrontRenderContext
    {
        return $this->installOnce();
    }

    /**
     * Cache key for the built snapshot: the resolved scope triple plus the
     * request-localisation inputs the snapshot embeds.
     *
     * Returns null when any input is missing, which keeps the caller on the
     * uncached path instead of storing a snapshot built from partial facts.
     */
    private static function snapshotCacheKey(): ?string
    {
        if (!Context::hasCurrent()) {
            return null;
        }
        $identity = RequestContext::scopeIdentity();
        $websiteId = $identity?->websiteId;
        if ($websiteId === null) {
            $websiteId = RequestContext::getWelineWebsiteId();
        }
        $websiteCode = \trim((string)($identity?->websiteCode ?? ''));
        if ($websiteCode === '') {
            $websiteCode = \trim((string)RequestContext::getWelineWebsiteCode());
        }
        if ($websiteCode === '') {
            return null;
        }

        return \implode("\n", [
            (string)(int)$websiteId,
            $websiteCode,
            \trim((string)RequestContext::getWelineUserLang()),
            \trim((string)RequestContext::getWelineUserCurrency()),
            \trim((string)RequestContext::getWelineTimezone()),
        ]);
    }

    /**
     * Build a snapshot from the current request context.
     */
    private function buildSnapshot(): StorefrontRenderContext
    {
        $identity = RequestContext::scopeIdentity();
        $websiteId = $identity?->websiteId;
        if ($websiteId === null) {
            $websiteId = RequestContext::getWelineWebsiteId();
        }
        $websiteCode = trim((string)($identity?->websiteCode ?? ''));
        if ($websiteCode === '') {
            $websiteCode = trim(RequestContext::getWelineWebsiteCode());
        }

        return $this->buildSnapshotFor(
            (int)$websiteId,
            $websiteCode,
            trim(RequestContext::getWelineWebsiteUrl()),
            trim(RequestContext::getWelineUserLang()),
            trim(RequestContext::getWelineUserCurrency()),
            trim(RequestContext::getWelineTimezone()),
        );
    }

    /**
     * Build a snapshot for an explicit scope triple.
     *
     * Split out of {@see self::buildSnapshot()} so preload providers can build the
     * same snapshot at Worker start-up without a request context present.
     */
    private function buildSnapshotFor(
        int $websiteId,
        string $websiteCode,
        string $websiteUrl,
        string $locale,
        string $currency,
        string $timezone,
    ): StorefrontRenderContext {
        $websiteCode = trim($websiteCode);
        $websiteUrl = trim($websiteUrl);
        $locale = trim($locale) ?: 'zh_Hans_CN';
        $currency = trim($currency) ?: 'CNY';
        $timezone = trim($timezone) ?: \date_default_timezone_get();

        $hasWebsite = $websiteCode !== ''
            && ($websiteId > 0 || $websiteCode === 'default' || $websiteId === 0);

        if (!$hasWebsite) {
            return new StorefrontRenderContext(
                (int)$websiteId,
                $websiteCode,
                $websiteUrl,
                null,
                $locale,
                $currency,
                $timezone,
                ['active' => [], 'installed' => []],
                null,
                null,
                null,
                false,
                'storefront_render_scope_incomplete',
            );
        }

        $websiteLocal = $this->projectWebsiteLocal((int)$websiteId);
        $localeCatalog = $this->resolveLocaleCatalog();

        return new StorefrontRenderContext(
            (int)$websiteId,
            $websiteCode,
            $websiteUrl,
            $websiteLocal,
            $locale,
            $currency,
            $timezone,
            $localeCatalog,
            null,
            null,
            null,
            true,
            '',
        );
    }

    /**
     * @return list<array{local_code?:string,name?:string,description?:string}>|null
     */
    private function projectWebsiteLocal(int $websiteId): ?array
    {
        if ($websiteId < 0) {
            return null;
        }
        try {
            if (!\class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                return null;
            }
            /** @var list<array{local_code?:string,name?:string,description?:string}> $rows */
            $rows = \Weline\Websites\Data\WebsiteData::localRowsForWebsite($websiteId);

            return $rows;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{active:list<string>,installed:list<string>} */
    private function resolveLocaleCatalog(): array
    {
        // Authority: ResolvedLanguageCatalog process bag / LocalizationProviderRegistry.
        // No parallel RequestContext locale-catalog staging bag.
        $active = [];
        $installed = [];
        try {
            /** @var LocalizationProviderRegistry $registry */
            $registry = ObjectManager::getInstance(LocalizationProviderRegistry::class);
            // active = resolved Website→Store→Channel order when Query available.
            $resolved = $this->tryResolvedLanguageCodes();
            if ($resolved !== []) {
                $active = $resolved;
            } else {
                $codes = $registry->preferredLanguageCodes();
                if (\is_array($codes)) {
                    $active = \array_values(\array_map('strval', $codes));
                }
            }
            $installedCodes = $registry->preferredInstalledLanguageCodes();
            if (\is_array($installedCodes) && $installedCodes !== []) {
                $installed = \array_values(\array_map('strval', $installedCodes));
            } else {
                // Website union for translation inventory when providers omit installed.
                $union = $registry->preferredLanguageCodes();
                $installed = \is_array($union) ? \array_values(\array_map('strval', $union)) : [];
            }
        } catch (\Throwable) {
            $active = [];
            $installed = [];
        }
        if ($installed === []) {
            $installed = $active;
        }

        return ['active' => $active, 'installed' => $installed];
    }

    /**
     * @return list<string>
     */
    private function tryResolvedLanguageCodes(): array
    {
        try {
            if (!\function_exists('w_query') || !Context::hasCurrent()) {
                return [];
            }
            $websiteId = RequestContext::getWelineWebsiteId();
            $result = \w_query('websites', 'getResolvedLanguageCodes', [
                'website_id' => $websiteId,
                'store_id' => RequestContext::getWelineStoreId(),
                'channel_id' => RequestContext::getWelineChannelId(),
            ]);
            if (!\is_array($result) || $result === []) {
                return [];
            }

            return \array_values(\array_map('strval', $result));
        } catch (\Throwable) {
            return [];
        }
    }

    private function incomplete(string $websiteCode, string $websiteUrl, string $failureCode): StorefrontRenderContext
    {
        $locale = 'zh_Hans_CN';
        $currency = 'CNY';
        $timezone = \date_default_timezone_get();
        $websiteId = 0;
        if (Context::hasCurrent()) {
            try {
                $locale = trim(RequestContext::getWelineUserLang()) ?: $locale;
                $currency = trim(RequestContext::getWelineUserCurrency()) ?: $currency;
                $timezone = trim(RequestContext::getWelineTimezone()) ?: $timezone;
                $websiteId = RequestContext::getWelineWebsiteId();
                if ($websiteCode === '') {
                    $websiteCode = trim(RequestContext::getWelineWebsiteCode());
                }
                if ($websiteUrl === '') {
                    $websiteUrl = trim(RequestContext::getWelineWebsiteUrl());
                }
            } catch (\Throwable) {
                // keep defaults
            }
        }

        return new StorefrontRenderContext(
            $websiteId,
            $websiteCode,
            $websiteUrl,
            null,
            $locale,
            $currency,
            $timezone,
            ['active' => [], 'installed' => []],
            null,
            null,
            null,
            false,
            $failureCode,
        );
    }
}
