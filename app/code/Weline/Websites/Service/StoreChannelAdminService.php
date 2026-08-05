<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;

/**
 * Backend workbench facade: reads only through catalogs and writes only through
 * the existing Store/SalesChannel model transaction and lifecycle boundaries.
 */
final class StoreChannelAdminService
{
    public function __construct(
        private readonly StoreCatalogInterface $stores,
        private readonly SalesChannelCatalogInterface $channels,
        private readonly Store $storeModel,
        private readonly SalesChannel $channelModel,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function listStores(int $websiteId): array
    {
        $this->assertWebsiteId($websiteId);
        return array_map(static fn (StoreSummary $store): array => $store->toArray(), $this->stores->byWebsite($websiteId));
    }

    /** @return list<array<string,mixed>> */
    public function listChannels(int $websiteId): array
    {
        $rows = [];
        foreach ($this->stores->byWebsite($websiteId) as $store) {
            foreach ($this->channels->byStore($store->id) as $channel) {
                $rows[] = $channel->toArray();
            }
        }
        return $rows;
    }

    public function createStore(
        int $websiteId,
        string $code,
        string $name,
        string $mode,
        ?string $url = null,
    ): StoreSummary {
        $this->assertWebsiteId($websiteId);
        $code = Store::normalizeCode($code);
        $name = trim($name);
        $mode = strtolower(trim($mode));
        if ($code === '' || $name === '') {
            throw new \InvalidArgumentException(__('店铺代码和名称不能为空'));
        }
        if ($this->stores->byCode($websiteId, $code) !== null) {
            throw new \InvalidArgumentException(__('店铺代码已存在：%{1}', [$code]));
        }

        $store = clone $this->storeModel;
        $store->clear()->setData([
            Store::schema_fields_WEBSITE_ID => $websiteId,
            Store::schema_fields_CODE => $code,
            Store::schema_fields_NAME => $name,
            Store::schema_fields_STORE_MODE => $mode,
            Store::schema_fields_IS_DEFAULT => 0,
            Store::schema_fields_STATUS => 1,
            Store::schema_fields_URL => $url !== null && trim($url) !== '' ? trim($url) : null,
            Store::schema_fields_LIFECYCLE_STATUS => Store::LIFECYCLE_ACTIVE,
            Store::schema_fields_TOMBSTONED_AT => null,
        ])->save();

        return $this->stores->byCode($websiteId, $code)
            ?? throw new \RuntimeException(__('店铺写入后无法通过目录回读'));
    }

    public function createChannel(
        int $websiteId,
        int $storeId,
        string $code,
        string $name,
    ): SalesChannelSummary {
        $this->assertWebsiteId($websiteId);
        $store = $this->stores->byId($storeId);
        if ($store === null || $store->websiteId !== $websiteId) {
            throw new \InvalidArgumentException(__('销售渠道所属店铺不存在或 Website 不匹配'));
        }
        $code = Store::normalizeCode($code);
        $name = trim($name);
        if ($code === '' || $name === '') {
            throw new \InvalidArgumentException(__('销售渠道代码和名称不能为空'));
        }
        if ($this->channels->byCode($storeId, $code) !== null) {
            throw new \InvalidArgumentException(__('销售渠道代码已存在：%{1}', [$code]));
        }

        $channel = clone $this->channelModel;
        $channel->clear()->setData([
            SalesChannel::schema_fields_WEBSITE_ID => $websiteId,
            SalesChannel::schema_fields_STORE_ID => $storeId,
            SalesChannel::schema_fields_CODE => $code,
            SalesChannel::schema_fields_NAME => $name,
            SalesChannel::schema_fields_IS_DEFAULT => 0,
            SalesChannel::schema_fields_STATUS => 1,
        ])->save();

        return $this->channels->byCode($storeId, $code)
            ?? throw new \RuntimeException(__('销售渠道写入后无法通过目录回读'));
    }

    private function assertWebsiteId(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负'));
        }
    }
}
