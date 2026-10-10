<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Cache\Contract\CacheWarmerInterface;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * Seeds homepage shelf id plan + catalog offer summaries per enabled locale.
 *
 * Soft-clear / empty L2 otherwise makes home first hit pay shelf + offers builders
 * (~0.2–0.35s). Light only: website shelf plan once + publishedOfferSummaries(48)
 * per locale×USD. Fail-open; no chrome mix; no heavy 1000-row offers; no featured widget strip.
 *
 * Clear already warmUp('product'); Registry runs this after search_projection (220→230).
 */
final class HomepageShelfOffersLocaleCacheWarmer implements CacheWarmerInterface
{
    /** Soft wall so Clear stays usable alongside chrome warmer budget. */
    private const BUDGET_MS = 45000;

    /** Floor for multi-locale summary hydrate during cache:clear (CLI default 128M OOMs). */
    private const MEMORY_FLOOR = '512M';

    private const SUMMARY_LIMIT = 48;

    public function getName(): string
    {
        return 'product.homepage_shelf_offers_locale';
    }

    public function getTargetPool(): string
    {
        return StorefrontCatalogViewService::cachePool();
    }

    public function getPriority(): int
    {
        return 230;
    }

    public function canWarm(): bool
    {
        return \class_exists(StorefrontCatalogViewService::class)
            && \class_exists(StorefrontProductWidgetCatalog::class)
            && \class_exists(WebsiteLanguage::class);
    }

    public function warm(): array
    {
        $prevMem = (string)\ini_get('memory_limit');
        $this->ensureMemoryFloor();

        $hadRequest = RequestContext::isInitialized();
        if (!$hadRequest) {
            try {
                RequestContext::init();
            } catch (\Throwable $e) {
                $this->restoreMemoryLimit($prevMem);

                return [
                    'warmed' => 0,
                    'skipped' => 1,
                    'message' => 'request_init_fail:' . $e->getMessage(),
                ];
            }
        }

        $skipped = 0;
        $remaining = 0;
        $warmedL2Hit = 0;
        $notes = [];
        $started = \hrtime(true);
        $shelfOk = false;
        $shelfL2 = false;
        $offersLocaleOk = 0;
        $offersL2 = 0;

        try {
            $this->installDefaultChannelIdentity();
            RequestContext::setWelineUserCurrency('USD');

            $locales = $this->resolveEnabledLocales();
            $notes[] = 'locales=' . \count($locales);
            if ($locales === []) {
                $this->restoreMemoryLimit($prevMem);

                return [
                    'warmed' => 0,
                    'skipped' => 1,
                    'message' => 'no_enabled_locales',
                ];
            }

            /** @var StorefrontCacheKeyContextResolver $resolver */
            $resolver = ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class);
            /** @var StorefrontCatalogViewService $catalog */
            $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
            /** @var StorefrontProductWidgetCatalog $widgetCatalog */
            $widgetCatalog = ObjectManager::getInstance(StorefrontProductWidgetCatalog::class);
            /** @var StorefrontScopeHotCache $hot */
            $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);
            $websiteId = Website::ID_DEFAULT;

            // ① Website-shared shelf id plan once (vary=[]).
            try {
                RequestContext::remove('framework.cache.storefront_key_context.v2');
                RequestContext::remove('product.homepage.shelf_plan.request');
                $shelfFreezeOk = true;
                if ($resolver instanceof StorefrontCacheKeyContextResolver) {
                    $ctx = $resolver->freezeCurrent();
                    if (!$ctx->cacheable) {
                        $shelfFreezeOk = false;
                        ++$skipped;
                        $notes[] = 'shelf_freeze_fail:' . ($ctx->failureCode ?: 'unknown');
                    }
                }
                if ($shelfFreezeOk) {
                    $cards = $widgetCatalog->homepageFeaturedCards(8);
                    if (\is_array($cards)) {
                        $shelfOk = true;
                    }
                    $shelfKey = StorefrontCatalogCacheCoordinator::homepageShelfPlanLogicalKey($websiteId);
                    // Drop process L1 so peek proves durable shared L2 (Clear→HTTP path).
                    $hot->purgeProcessCacheForLogicalKey($shelfKey);
                    $peek = $hot->peekPolicy(
                        StorefrontCatalogCacheCoordinator::homepageShelfPlanPolicy(),
                        $shelfKey,
                    );
                    if (\is_array($peek) && $peek !== []) {
                        $shelfL2 = true;
                        ++$warmedL2Hit;
                    } elseif ($shelfOk) {
                        $notes[] = 'shelf_shared_l2_miss';
                    }
                }
            } catch (\Throwable $e) {
                ++$skipped;
                $notes[] = 'shelf_error:' . $e->getMessage();
            }
            $notes[] = 'shelf_ok=' . ($shelfOk ? '1' : '0');
            $notes[] = 'shelf_l2=' . ($shelfL2 ? 'hit' : 'miss');

            // ② Enabled locales × USD → light summary (+ lang surface via builder path).
            foreach ($locales as $idx => $locale) {
                $elapsedMs = (\hrtime(true) - $started) / 1e6;
                if ($elapsedMs >= self::BUDGET_MS) {
                    $remaining = \count($locales) - $idx;
                    $notes[] = 'budget_ms=' . self::BUDGET_MS;
                    $notes[] = 'remaining=' . $remaining;
                    break;
                }

                try {
                    RequestContext::setWelineUserLang($locale);
                    RequestContext::setWelineUserCurrency('USD');
                    RequestContext::remove('framework.cache.storefront_key_context.v2');
                    RequestContext::remove('product.catalog.summary.bag.request');
                    RequestContext::remove('product.homepage.shelf_plan.request');

                    if ($resolver instanceof StorefrontCacheKeyContextResolver) {
                        $ctx = $resolver->freezeCurrent();
                        if (!$ctx->cacheable) {
                            ++$skipped;
                            $notes[] = 'freeze_fail:' . $locale . ':' . ($ctx->failureCode ?: 'unknown');
                            continue;
                        }
                    }

                    $rows = $catalog->publishedOfferSummaries(self::SUMMARY_LIMIT);
                    if (\is_array($rows)) {
                        ++$offersLocaleOk;
                    } else {
                        ++$skipped;
                        $notes[] = 'empty_summary:' . $locale;
                        continue;
                    }

                    $summaryKey = StorefrontCatalogCacheCoordinator::catalogSummaryOffersLogicalKey(
                        $websiteId,
                        self::SUMMARY_LIMIT,
                    );
                    $langKey = StorefrontCatalogCacheCoordinator::catalogSummaryLangSurfaceLogicalKey(
                        $websiteId,
                        $locale,
                        self::SUMMARY_LIMIT,
                    );
                    $hot->purgeProcessCacheForLogicalKey($summaryKey);
                    $hot->purgeProcessCacheForLogicalKey($langKey);
                    $summaryPeek = $hot->peekPolicy(
                        StorefrontCatalogCacheCoordinator::catalogSummaryOffersPolicy(),
                        $summaryKey,
                    );
                    $langPeek = $hot->peekPolicy(
                        StorefrontCatalogCacheCoordinator::catalogSummaryLangSurfacePolicy(),
                        $langKey,
                    );
                    $localeL2 = (\is_array($summaryPeek) && $summaryPeek !== [])
                        || (\is_array($langPeek) && $langPeek !== []);
                    if ($localeL2) {
                        ++$warmedL2Hit;
                        ++$offersL2;
                    } else {
                        $notes[] = 'offers_shared_l2_miss:' . $locale;
                    }
                } catch (\Throwable $e) {
                    ++$skipped;
                    $notes[] = 'error:' . $locale . ':' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            ++$skipped;
            $notes[] = 'error:' . $e->getMessage();
        } finally {
            try {
                RequestContext::resetWelineVars();
            } catch (\Throwable) {
            }
            $this->restoreMemoryLimit($prevMem);
        }

        if ($remaining > 0) {
            ++$skipped;
        }

        $notes[] = 'offers_locale_ok=' . $offersLocaleOk;
        $notes[] = 'offers_l2_hit=' . $offersL2;
        $notes[] = 'warmed_l2_hit=' . $warmedL2Hit;
        if ($remaining > 0) {
            $notes[] = 'partial=1';
        }

        return [
            // Honest durable count for Clear registry; seed-only stays in message.
            'warmed' => \max(0, $warmedL2Hit),
            'skipped' => $skipped,
            'message' => \implode(';', $notes) ?: 'ok',
        ];
    }

    private function ensureMemoryFloor(): void
    {
        $current = \trim((string)\ini_get('memory_limit'));
        if ($current === '' || $current === '-1') {
            return;
        }
        if (!\preg_match('/^(\d+)([KMG]?)$/i', $current, $m)) {
            @\ini_set('memory_limit', self::MEMORY_FLOOR);

            return;
        }
        $number = (float)$m[1];
        $unit = \strtoupper($m[2] !== '' ? $m[2] : 'B');
        $bytes = match ($unit) {
            'G' => (int)($number * 1024 * 1024 * 1024),
            'M' => (int)($number * 1024 * 1024),
            'K' => (int)($number * 1024),
            default => (int)$number,
        };
        if ($bytes > 0 && $bytes < 512 * 1024 * 1024) {
            @\ini_set('memory_limit', self::MEMORY_FLOOR);
        }
    }

    private function restoreMemoryLimit(string $prevMem): void
    {
        if ($prevMem !== '') {
            @\ini_set('memory_limit', $prevMem);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveEnabledLocales(): array
    {
        $codes = [];
        try {
            /** @var WebsiteLanguage $wl */
            $wl = ObjectManager::getInstance(WebsiteLanguage::class);
            $raw = $wl->getWebsiteLanguageCodes(Website::ID_DEFAULT);
            if (\is_array($raw)) {
                foreach ($raw as $code) {
                    $code = \trim((string)$code);
                    if ($code !== '') {
                        $codes[] = $code;
                    }
                }
            }
        } catch (\Throwable) {
            $codes = [];
        }

        $codes = \array_values(\array_unique($codes));
        if ($codes === []) {
            $codes = ['zh_Hans_CN', 'en_US'];
        }

        // Baseline + Q3 probe locales first (only if enabled), then stable tail.
        $priority = ['zh_Hans_CN', 'en_US', 'fr_FR', 'it_IT', 'es_ES'];
        $head = [];
        foreach ($priority as $p) {
            if (\in_array($p, $codes, true)) {
                $head[] = $p;
            }
        }
        $tail = \array_values(\array_filter(
            $codes,
            static fn(string $c): bool => !\in_array($c, $priority, true),
        ));
        \sort($tail, \SORT_STRING);

        return \array_merge($head, $tail);
    }

    private function installDefaultChannelIdentity(): void
    {
        $storeMode = Store::MODE_NORMAL;
        $identity = ScopeIdentity::channel(
            Website::ID_DEFAULT,
            Website::CODE_DEFAULT,
            Store::CODE_DEFAULT,
            SalesChannel::CODE_DEFAULT,
            $storeMode,
        );
        RequestContext::setWelineWebsiteId(Website::ID_DEFAULT);
        RequestContext::setWelineWebsiteCode(Website::CODE_DEFAULT);
        RequestContext::setWelineStoreId(0);
        RequestContext::setWelineStoreCode(Store::CODE_DEFAULT);
        RequestContext::setWelineStoreMode($storeMode);
        RequestContext::setWelineChannelId(0);
        RequestContext::setWelineChannelCode(SalesChannel::CODE_DEFAULT);
        RequestContext::installScopeIdentity($identity);
    }
}
