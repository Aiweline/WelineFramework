<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Cache\Contract\CacheWarmerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Data\ScopeData;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

/**
 * Control-plane warm for website pool store/channel catalog after cache:clear.
 *
 * Channel HotCache needs a frozen channel ScopeIdentity (policy scope=store).
 * Discover default store/channel via Model (avoid fence), install identity once,
 * then publish via ScopeCatalogHotCachePublisher.
 */
final class ScopeCatalogCacheWarmer implements CacheWarmerInterface
{
    public function getName(): string
    {
        return 'websites.scope_catalog';
    }

    public function getTargetPool(): string
    {
        return 'website';
    }

    public function getPriority(): int
    {
        return 200;
    }

    public function canWarm(): bool
    {
        return \class_exists(ScopeCatalogHotCachePublisher::class);
    }

    public function warm(): array
    {
        $warmed = 0;
        $skipped = 0;
        $notes = [];

        try {
            /** @var ScopeCatalogHotCachePublisher $publisher */
            $publisher = ObjectManager::getInstance(ScopeCatalogHotCachePublisher::class);
            /** @var StoreCatalogInterface $stores */
            $stores = ObjectManager::getInstance(StoreCatalogInterface::class);
            /** @var SalesChannelCatalogInterface $channels */
            $channels = ObjectManager::getInstance(SalesChannelCatalogInterface::class);
        } catch (\Throwable $e) {
            return [
                'warmed' => 0,
                'skipped' => 1,
                'message' => 'resolve_fail:' . $e->getMessage(),
            ];
        }

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

        try {
            $pair = $this->resolveDefaultStoreAndChannel();
            if ($pair === null) {
                return [
                    'warmed' => 0,
                    'skipped' => 1,
                    'message' => 'no_default_store_or_channel',
                ];
            }
            [$store, $channel] = $pair;

            $storeMode = $store->storeMode !== '' ? $store->storeMode : Store::MODE_NORMAL;
            $identity = ScopeIdentity::channel(
                Website::ID_DEFAULT,
                Website::CODE_DEFAULT,
                $store->code,
                $channel->code,
                $storeMode,
            );
            RequestContext::setWelineWebsiteId(Website::ID_DEFAULT);
            RequestContext::setWelineWebsiteCode(Website::CODE_DEFAULT);
            RequestContext::setWelineStoreId($store->id);
            RequestContext::setWelineStoreCode($store->code);
            RequestContext::setWelineStoreMode($storeMode);
            RequestContext::setWelineChannelId($channel->id);
            RequestContext::setWelineChannelCode($channel->code);
            RequestContext::installScopeIdentity($identity);
            ScopeData::install($store, $channel, '/');

            // Website-scoped store keys (now with complete identity).
            $stores->byId($store->id);
            ++$warmed;
            $stores->byWebsite($store->websiteId);
            ++$warmed;

            $published = $publisher->publishAfterScopeFrozen($store, $channel);
            $warmed += \max(0, $published);
            $notes[] = 'published=' . $published;

            // Touch channel APIs so request memo + L2 align with consumers.
            $channels->byId($channel->id);
            $channels->byStore($store->id);
        } catch (\Throwable $e) {
            ++$skipped;
            $notes[] = 'error:' . $e->getMessage();
        } finally {
            try {
                ScopeData::resetRequestState();
            } catch (\Throwable) {
            }
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

    /**
     * @return array{0:StoreSummary,1:SalesChannelSummary}|null
     */
    private function resolveDefaultStoreAndChannel(): ?array
    {
        try {
            /** @var Store $storeModel */
            $storeModel = ObjectManager::getInstance(Store::class);
            $storeRows = $storeModel->clearQuery()->clearData()
                ->where(Store::schema_fields_WEBSITE_ID, Website::ID_DEFAULT)
                ->where(Store::schema_fields_IS_DEFAULT, 1)
                ->select()
                ->fetchArray();
            if (!\is_array($storeRows) || $storeRows === []) {
                $storeRows = $storeModel->clearQuery()->clearData()
                    ->where(Store::schema_fields_WEBSITE_ID, Website::ID_DEFAULT)
                    ->where(Store::schema_fields_CODE, Store::CODE_DEFAULT)
                    ->select()
                    ->fetchArray();
            }
            if (!\is_array($storeRows) || $storeRows === [] || !\is_array($storeRows[0] ?? null)) {
                return null;
            }
            $sr = $storeRows[0];
            $store = new StoreSummary(
                (int)$sr[Store::schema_fields_ID],
                (int)$sr[Store::schema_fields_WEBSITE_ID],
                (string)$sr[Store::schema_fields_CODE],
                (string)($sr[Store::schema_fields_NAME] ?? ''),
                (string)($sr[Store::schema_fields_STORE_MODE] ?? Store::MODE_NORMAL),
                (int)($sr[Store::schema_fields_IS_DEFAULT] ?? 0) === 1,
                (int)($sr[Store::schema_fields_STATUS] ?? 1) === 1,
                (string)($sr[Store::schema_fields_LIFECYCLE_STATUS] ?? Store::LIFECYCLE_ACTIVE),
                isset($sr[Store::schema_fields_TOMBSTONED_AT]) ? (string)$sr[Store::schema_fields_TOMBSTONED_AT] : null,
            );

            /** @var SalesChannel $channelModel */
            $channelModel = ObjectManager::getInstance(SalesChannel::class);
            $channelRows = $channelModel->clearQuery()->clearData()
                ->where(SalesChannel::schema_fields_STORE_ID, $store->id)
                ->where(SalesChannel::schema_fields_IS_DEFAULT, 1)
                ->select()
                ->fetchArray();
            if (!\is_array($channelRows) || $channelRows === []) {
                $channelRows = $channelModel->clearQuery()->clearData()
                    ->where(SalesChannel::schema_fields_STORE_ID, $store->id)
                    ->where(SalesChannel::schema_fields_CODE, SalesChannel::CODE_DEFAULT)
                    ->select()
                    ->fetchArray();
            }
            if (!\is_array($channelRows) || $channelRows === [] || !\is_array($channelRows[0] ?? null)) {
                return null;
            }
            $cr = $channelRows[0];
            $enabled = (int)($cr[SalesChannel::schema_fields_STATUS] ?? 1) === 1;
            $lifecycle = $store->lifecycleStatus;
            $channel = new SalesChannelSummary(
                (int)$cr[SalesChannel::schema_fields_ID],
                (int)$cr[SalesChannel::schema_fields_WEBSITE_ID],
                (int)$cr[SalesChannel::schema_fields_STORE_ID],
                (string)$cr[SalesChannel::schema_fields_CODE],
                (string)($cr[SalesChannel::schema_fields_NAME] ?? ''),
                (int)($cr[SalesChannel::schema_fields_IS_DEFAULT] ?? 0) === 1,
                $enabled,
                $lifecycle,
                $enabled && $store->enabled && $lifecycle === Store::LIFECYCLE_ACTIVE,
            );

            return [$store, $channel];
        } catch (\Throwable) {
            return null;
        }
    }
}
