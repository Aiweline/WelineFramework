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

/**
 * Seeds product.search_projection_scope HotCache after cache:clear.
 *
 * Channel-scoped (not lang-vary): one durable L2 bag per website×store×channel×watermark.
 * Soft-clear / empty L2 otherwise forces /search first hit to nested-rebuild inside
 * search.query_result (~0.5–1.2s). Fail-open; never whole-website snapshot.
 *
 * IMPORTANT: a projection peek_hit / L2 HIT is NOT proof that tip search.query_result
 * is warm. Tip result bags are owned by Weline_Search (SearchQueryResultTipCacheWarmer).
 * Sticky hit2 alone is also not an acceptance pass for query_result cold hit1.
 */
final class SearchProjectionScopeCacheWarmer implements CacheWarmerInterface
{
    public function getName(): string
    {
        return 'product.search_projection_scope';
    }

    public function getTargetPool(): string
    {
        return StorefrontCatalogViewService::cachePool();
    }

    public function getPriority(): int
    {
        return 220;
    }

    public function canWarm(): bool
    {
        return \class_exists(ProductSearchProjectionService::class)
            && \class_exists(StorefrontScopeHotCache::class);
    }

    public function warm(): array
    {
        $hadRequest = RequestContext::isInitialized();
        if (!$hadRequest) {
            try {
                RequestContext::init();
            } catch (\Throwable $e) {
                return [
                    'warmed' => 0,
                    'skipped' => 1,
                    'message' => 'request_init_fail:' . $e->getMessage(),
                ];
            }
        }

        $warmed = 0;
        $skipped = 0;
        $notes = [];

        try {
            $this->installDefaultChannelIdentity();
            $resolver = ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class);
            if ($resolver instanceof StorefrontCacheKeyContextResolver) {
                $ctx = $resolver->freezeCurrent();
                if (!$ctx->cacheable) {
                    return [
                        'warmed' => 0,
                        'skipped' => 1,
                        'message' => 'freeze_not_cacheable:' . ($ctx->failureCode ?: 'unknown'),
                    ];
                }
            }

            $websiteId = Website::ID_DEFAULT;
            $storeId = 0;
            $channelId = 0;
            try {
                $storeId = \max(0, (int)RequestContext::getWelineStoreId());
                $channelId = \max(0, (int)RequestContext::getWelineChannelId());
            } catch (\Throwable) {
            }

            /** @var ProductSearchProjectionService $projection */
            $projection = ObjectManager::getInstance(ProductSearchProjectionService::class);
            $watermark = $projection->currentWatermark($websiteId);
            $logicalKey = StorefrontCatalogCacheCoordinator::searchProjectionScopeLogicalKey(
                $websiteId,
                $storeId,
                $channelId,
                $watermark,
            );

            /** @var StorefrontScopeHotCache $hot */
            $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);
            $peek = $hot->peekPolicy(
                StorefrontCatalogCacheCoordinator::searchProjectionScopePolicy(),
                $logicalKey,
            );
            if (\is_array($peek) && $peek !== []) {
                $notes[] = 'peek_hit';
                $notes[] = 'key=' . $logicalKey;

                return [
                    'warmed' => 0,
                    'skipped' => 0,
                    'message' => \implode(';', $notes),
                ];
            }

            $snapshot = $projection->snapshotScope($websiteId, $storeId, $channelId);
            if (\is_array($snapshot) && $snapshot !== []) {
                ++$warmed;
                $notes[] = 'primed=1';
                $notes[] = 'wm=' . $watermark;
                $notes[] = 'store=' . $storeId;
                $notes[] = 'channel=' . $channelId;
            } else {
                ++$skipped;
                $notes[] = 'empty_snapshot';
            }
        } catch (\Throwable $e) {
            ++$skipped;
            $notes[] = 'error:' . $e->getMessage();
        } finally {
            try {
                RequestContext::resetWelineVars();
            } catch (\Throwable) {
            }
        }

        return [
            'warmed' => $warmed,
            'skipped' => $skipped,
            'message' => \implode(';', $notes) ?: 'ok',
        ];
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
