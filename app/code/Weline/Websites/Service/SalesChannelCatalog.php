<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Data\ScopeData;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;

final class SalesChannelCatalog implements SalesChannelCatalogInterface
{
    private const MAX_CATALOG_ID = 2147483647;

    private bool $hotCacheResolved = false;

    public function __construct(
        private readonly SalesChannel $channel,
        private readonly StoreCatalogInterface $storeCatalog,
        private ?StorefrontScopeHotCache $hotCache = null,
    ) {
    }

    public function byStore(int $storeId): array
    {
        if ($storeId < 0 || $storeId > self::MAX_CATALOG_ID) {
            throw new \InvalidArgumentException((string)__('店铺 ID 不能为负数（0 是系统默认店铺）'));
        }
        $key = 'store:' . $storeId;
        return $this->remember($key, function () use ($storeId): array {
            $parentStore = $this->requireStore($storeId);
            $rows = $this->newChannel()
                ->where(SalesChannel::schema_fields_STORE_ID, $storeId)
                ->order(SalesChannel::schema_fields_ID, 'ASC')
                ->select()
                ->fetchArray();

            return $this->mapRows($rows, $storeId, $parentStore);
        }, StorefrontScopeCatalogCacheCoordinator::channelPolicy());
    }

    public function byCode(int $storeId, string $channelCode): ?SalesChannelSummary
    {
        if ($storeId < 0) {
            return null;
        }
        $this->assertCatalogIdMaximum($storeId, __('店铺 ID'));
        $channelCode = Store::normalizeCode($channelCode);
        if ($channelCode === '') {
            return null;
        }
        $key = 'code:' . $storeId . ':' . $channelCode;
        return $this->remember($key, function () use ($storeId, $channelCode): ?SalesChannelSummary {
            $parentStore = $this->requireStore($storeId);
            $rows = $this->newChannel()
                ->where(SalesChannel::schema_fields_STORE_ID, $storeId)
                ->where(SalesChannel::schema_fields_CODE, $channelCode)
                ->select()
                ->fetchArray();
            $mapped = $this->mapRows($rows, $storeId, $parentStore);

            return $this->singleOrNull($mapped, __('渠道代码在同一店铺下不唯一'));
        }, StorefrontScopeCatalogCacheCoordinator::channelPolicy());
    }

    public function byId(int $channelId): ?SalesChannelSummary
    {
        if ($channelId < 0) {
            return null;
        }
        $this->assertCatalogIdMaximum($channelId, __('销售渠道 ID'));
        if (ScopeData::matchesChannelId($channelId)) {
            return ScopeData::getChannel();
        }
        $shared = ObjectManager::getInstance(ScopePathMatchCache::class)->readChannelSnapshot($channelId);
        if ($shared instanceof SalesChannelSummary) {
            return $shared;
        }
        $key = 'id:' . $channelId;
        return $this->remember($key, function () use ($channelId): ?SalesChannelSummary {
            $rows = $this->newChannel()
                ->where(SalesChannel::schema_fields_ID, $channelId)
                ->select()
                ->fetchArray();
            $mapped = $this->mapRows($rows);

            return $this->singleOrNull($mapped, __('渠道 ID 不唯一'));
        }, StorefrontScopeCatalogCacheCoordinator::channelPolicy());
    }

    public function defaultChannel(int $storeId): ?SalesChannelSummary
    {
        if ($storeId < 0) {
            return null;
        }
        $this->assertCatalogIdMaximum($storeId, __('店铺 ID'));

        return $this->defaultChannelWithStore(
            $storeId,
            fn(): StoreSummary => $this->requireStore($storeId),
        );
    }

    public function defaultChannelForStore(StoreSummary $store): ?SalesChannelSummary
    {
        $storeId = $store->id;
        if ($storeId < 0) {
            return null;
        }
        $this->assertCatalogIdMaximum($storeId, __('店铺 ID'));

        return $this->defaultChannelWithStore($storeId, static fn(): StoreSummary => $store);
    }

    /**
     * Keep parent-store loading inside the cache builder for the legacy ID API.
     * A shared/L1 default-channel hit must remain free of a Store lookup.
     *
     * @param callable():StoreSummary $storeProvider
     */
    private function defaultChannelWithStore(int $storeId, callable $storeProvider): ?SalesChannelSummary
    {
        $key = 'default:' . $storeId;
        return $this->remember($key, function () use ($storeProvider, $storeId): ?SalesChannelSummary {
            $store = $storeProvider();
            $rows = $this->newChannel()
                ->where(SalesChannel::schema_fields_STORE_ID, $storeId)
                ->where(SalesChannel::schema_fields_IS_DEFAULT, 1)
                ->select()
                ->fetchArray();
            $mapped = $this->mapRows($rows, $storeId, $store);
            $default = $this->singleOrNull($mapped, __('同一店铺存在多个默认渠道'));
            if ($default !== null) {
                return $default;
            }
            // Keep the already resolved parent Store on the fallback path too.
            // A missing default flag should not trigger another Store lookup.
            $byCode = $this->byCodeForStore($store, SalesChannel::CODE_DEFAULT);
            if ($byCode !== null && !$byCode->isDefault) {
                throw new \RuntimeException(__('code=default 的渠道缺少默认标记'));
            }
            return $byCode;
        }, StorefrontScopeCatalogCacheCoordinator::channelPolicy());
    }

    private function byCodeForStore(StoreSummary $store, string $channelCode): ?SalesChannelSummary
    {
        $storeId = $store->id;
        $key = 'code:' . $storeId . ':' . $channelCode;

        return $this->remember($key, function () use ($store, $storeId, $channelCode): ?SalesChannelSummary {
            $rows = $this->newChannel()
                ->where(SalesChannel::schema_fields_STORE_ID, $storeId)
                ->where(SalesChannel::schema_fields_CODE, $channelCode)
                ->select()
                ->fetchArray();
            $mapped = $this->mapRows($rows, $storeId, $store);

            return $this->singleOrNull($mapped, __('渠道代码在同一店铺下不唯一'));
        }, StorefrontScopeCatalogCacheCoordinator::channelPolicy());
    }

    private function remember(string $logicalKey, callable $builder, ?\Weline\Framework\Cache\CachePolicy $policy = null): mixed
    {
        $hotCache = $this->hotCache();
        if (!$hotCache instanceof StorefrontScopeHotCache) {
            return $builder();
        }

        $value = $hotCache->rememberForRequest(
            'websites.sales_channel_catalog',
            $logicalKey,
            $policy === null
                ? $builder
                : fn(): mixed => $hotCache->rememberPolicy($policy, $logicalKey, $builder),
        );

        return $this->rehydrateCachedValue($value);
    }

    /**
     * Shared pools may JSON-round-trip DTO payloads into arrays; restore SalesChannelSummary.
     */
    private function rehydrateCachedValue(mixed $value): mixed
    {
        if ($value instanceof SalesChannelSummary || $value === null) {
            return $value;
        }
        if (!\is_array($value)) {
            throw new \RuntimeException((string)__('销售渠道目录缓存载荷类型无效'));
        }
        if ($value === [] || \array_is_list($value)) {
            $restored = [];
            foreach ($value as $item) {
                $restored[] = $this->salesChannelSummaryFromCached($item);
            }

            return $restored;
        }

        return $this->salesChannelSummaryFromCached($value);
    }

    private function salesChannelSummaryFromCached(mixed $item): SalesChannelSummary
    {
        if ($item instanceof SalesChannelSummary) {
            return $item;
        }
        if (!\is_array($item)) {
            throw new \RuntimeException((string)__('销售渠道目录缓存条目必须是数组或 SalesChannelSummary'));
        }

        return new SalesChannelSummary(
            (int)($item['channel_id'] ?? $item['id'] ?? 0),
            (int)($item['website_id'] ?? $item['websiteId'] ?? 0),
            (int)($item['store_id'] ?? $item['storeId'] ?? 0),
            (string)($item['code'] ?? ''),
            (string)($item['name'] ?? ''),
            (bool)($item['is_default'] ?? $item['isDefault'] ?? false),
            (bool)($item['enabled'] ?? true),
            (string)($item['parent_store_lifecycle_status'] ?? $item['parentStoreLifecycleStatus'] ?? Store::LIFECYCLE_ACTIVE),
            (bool)($item['effective_enabled'] ?? $item['effectiveEnabled'] ?? false),
        );
    }

    private function hotCache(): ?StorefrontScopeHotCache
    {
        if ($this->hotCacheResolved) {
            return $this->hotCache;
        }
        $this->hotCacheResolved = true;
        if ($this->hotCache instanceof StorefrontScopeHotCache || !Context::hasCurrent()) {
            return $this->hotCache;
        }
        try {
            $this->hotCache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
        } catch (\Throwable) {
            $this->hotCache = null;
        }

        return $this->hotCache;
    }

    /**
     * @param mixed $rows
     * @return list<SalesChannelSummary>
     */
    private function mapRows(
        mixed $rows,
        ?int $expectedStoreId = null,
        ?StoreSummary $expectedStore = null,
    ): array
    {
        if (!\is_array($rows)) {
            throw new \RuntimeException((string)__('销售渠道目录查询结果必须是数组'));
        }

        $result = [];
        $seenIds = [];
        $seenCodes = [];
        $defaultChannelIds = [];
        /** @var array<int, StoreSummary> $parentStores */
        $parentStores = [];
        if ($expectedStore !== null) {
            $parentStores[$expectedStore->id] = $expectedStore;
        }
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new \RuntimeException((string)__('销售渠道目录包含非法数据行'));
            }

            $id = $this->requireIntegerField($row, SalesChannel::schema_fields_ID, 0);
            $websiteId = $this->requireIntegerField($row, SalesChannel::schema_fields_WEBSITE_ID, 0);
            $storeId = $this->requireIntegerField($row, SalesChannel::schema_fields_STORE_ID, 0);
            if ($expectedStoreId !== null && $storeId !== $expectedStoreId) {
                throw new \RuntimeException(
                    (string)__('销售渠道 %{1} 的店铺 ID 与查询范围不一致', [$id])
                );
            }

            $parentStore = $this->requireParentStore($storeId, $parentStores);
            if ($parentStore->id !== $storeId || $parentStore->websiteId !== $websiteId) {
                throw new \RuntimeException(
                    (string)__('销售渠道 %{1} 与父店铺的 Website 归属不一致', [$id])
                );
            }

            $code = $this->requireStringField($row, SalesChannel::schema_fields_CODE);
            if ($code === '' || Store::normalizeCode($code) !== $code) {
                throw new \RuntimeException(
                    (string)__('销售渠道 %{1} 的代码不是规范值', [$id])
                );
            }
            if (\mb_strlen($code, 'UTF-8') > SalesChannel::CODE_MAX_LENGTH) {
                throw new \RuntimeException(
                    (string)__('渠道代码不能超过 %{1} 个字符', [SalesChannel::CODE_MAX_LENGTH])
                );
            }
            $name = \trim($this->requireStringField($row, SalesChannel::schema_fields_NAME));
            if ($name === '') {
                throw new \RuntimeException((string)__('销售渠道 %{1} 缺少名称', [$id]));
            }
            if (\mb_strlen($name, 'UTF-8') > SalesChannel::NAME_MAX_LENGTH) {
                throw new \RuntimeException(
                    (string)__('渠道名称不能超过 %{1} 个字符', [SalesChannel::NAME_MAX_LENGTH])
                );
            }
            $isDefault = $this->requireBinaryFlag($row, SalesChannel::schema_fields_IS_DEFAULT) === 1;
            $enabled = $this->requireBinaryFlag($row, SalesChannel::schema_fields_STATUS) === 1;
            $parentStoreLifecycleStatus = $parentStore->lifecycleStatus;
            if (!\in_array(
                $parentStoreLifecycleStatus,
                [Store::LIFECYCLE_ACTIVE, Store::LIFECYCLE_TOMBSTONE],
                true,
            )) {
                throw new \RuntimeException(
                    (string)__('销售渠道 %{1} 的父店铺生命周期状态无效', [$id])
                );
            }
            $effectiveEnabled = $enabled
                && $parentStore->enabled
                && $parentStoreLifecycleStatus === Store::LIFECYCLE_ACTIVE;

            if (isset($seenIds[$id])) {
                throw new \RuntimeException((string)__('销售渠道目录存在重复 ID：%{1}', [$id]));
            }
            if (isset($seenCodes[$storeId][$code])) {
                throw new \RuntimeException(
                    (string)__('店铺 %{1} 的销售渠道代码重复：%{2}', [$storeId, $code])
                );
            }
            if ($isDefault && isset($defaultChannelIds[$storeId])) {
                throw new \RuntimeException(
                    (string)__('店铺 %{1} 存在多个默认销售渠道', [$storeId])
                );
            }
            if (($code === SalesChannel::CODE_DEFAULT) !== $isDefault) {
                throw new \RuntimeException(
                    (string)__('销售渠道 %{1} 的 default 代码与默认标记不一致', [$id])
                );
            }
            if ($isDefault && !$enabled) {
                throw new \RuntimeException(
                    (string)__('店铺 %{1} 的默认销售渠道必须启用', [$storeId])
                );
            }

            $seenIds[$id] = true;
            $seenCodes[$storeId][$code] = true;
            if ($isDefault) {
                $defaultChannelIds[$storeId] = $id;
            }
            $result[] = new SalesChannelSummary(
                $id,
                $websiteId,
                $storeId,
                $code,
                $name,
                $isDefault,
                $enabled,
                $parentStoreLifecycleStatus,
                $effectiveEnabled,
            );
        }

        return $result;
    }

    /**
     * @param array<int, StoreSummary> $parentStores
     */
    private function requireParentStore(int $storeId, array &$parentStores): StoreSummary
    {
        if (isset($parentStores[$storeId])) {
            return $parentStores[$storeId];
        }

        $store = $this->requireStore($storeId);
        $parentStores[$storeId] = $store;

        return $store;
    }

    private function requireStore(int $storeId): StoreSummary
    {
        $store = $this->storeCatalog->byId($storeId);
        if ($store === null) {
            throw new \RuntimeException(
                (string)__('销售渠道引用了不存在的父店铺：%{1}', [$storeId])
            );
        }
        if ($store->id !== $storeId) {
            throw new \RuntimeException(
                (string)__('父店铺 ID 与销售渠道查询范围不一致：%{1}', [$storeId])
            );
        }

        return $store;
    }

    /** @param array<string, mixed> $row */
    private function requireIntegerField(array $row, string $field, int $minimum): int
    {
        if (!\array_key_exists($field, $row)) {
            throw new \RuntimeException(
                (string)__('销售渠道目录数据缺少字段：%{1}', [$field])
            );
        }

        $value = $row[$field];
        if (\is_int($value)) {
            $normalized = $value;
        } elseif (\is_string($value) && \preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $normalized = (int)$value;
            if ((string)$normalized !== $value) {
                throw new \RuntimeException(
                    (string)__('销售渠道目录字段 %{1} 超出整数范围', [$field])
                );
            }
        } else {
            throw new \RuntimeException(
                (string)__('销售渠道目录字段 %{1} 必须是规范整数', [$field])
            );
        }

        if ($normalized < $minimum) {
            throw new \RuntimeException(
                (string)__('销售渠道目录字段 %{1} 必须大于或等于 %{2}', [$field, $minimum])
            );
        }
        if ($normalized > self::MAX_CATALOG_ID) {
            throw new \RuntimeException(
                (string)__('销售渠道目录字段 %{1} 不能大于 %{2}', [$field, self::MAX_CATALOG_ID])
            );
        }

        return $normalized;
    }

    /** @param array<string, mixed> $row */
    private function requireStringField(array $row, string $field): string
    {
        if (!\array_key_exists($field, $row) || !\is_string($row[$field])) {
            throw new \RuntimeException(
                (string)__('销售渠道目录字段 %{1} 必须是字符串', [$field])
            );
        }

        return $row[$field];
    }

    /** @param array<string, mixed> $row */
    private function requireBinaryFlag(array $row, string $field): int
    {
        $value = $this->requireIntegerField($row, $field, 0);
        if ($value !== 0 && $value !== 1) {
            throw new \RuntimeException(
                (string)__('销售渠道目录字段 %{1} 只能是 0 或 1', [$field])
            );
        }

        return $value;
    }

    private function newChannel(): SalesChannel
    {
        $channel = clone $this->channel;
        return $channel->clearQuery()->clearData();
    }

    private function assertCatalogIdMaximum(int $id, string $label): void
    {
        if ($id > self::MAX_CATALOG_ID) {
            throw new \InvalidArgumentException(
                (string)__('%{1} 不能大于 %{2}', [$label, self::MAX_CATALOG_ID])
            );
        }
    }

    /** @param list<SalesChannelSummary> $channels */
    private function singleOrNull(array $channels, string $error): ?SalesChannelSummary
    {
        if (count($channels) > 1) {
            throw new \RuntimeException($error);
        }
        return $channels[0] ?? null;
    }
}
