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
 *
 * display_type / channel.url are private Model fields (not Catalog Summary v1).
 */
final class StoreChannelAdminService
{
    public function __construct(
        private readonly StoreCatalogInterface $stores,
        private readonly SalesChannelCatalogInterface $channels,
        private readonly Store $storeModel,
        private readonly SalesChannel $channelModel,
        private readonly ScopeDisplayTypeResolver $displayTypes,
        private readonly ScopeChannelUrlValidator $channelUrlValidator,
        private readonly ScopeChannelUrlReader $channelUrlReader,
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
        ?string $displayType = null,
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
        $normalizedType = $this->displayTypes->normalizeAssignedCode($displayType);

        $store = clone $this->storeModel;
        $store->clear()->setData([
            Store::schema_fields_WEBSITE_ID => $websiteId,
            Store::schema_fields_CODE => $code,
            Store::schema_fields_NAME => $name,
            Store::schema_fields_STORE_MODE => $mode,
            Store::schema_fields_IS_DEFAULT => 0,
            Store::schema_fields_STATUS => 1,
            Store::schema_fields_URL => $url !== null && trim($url) !== '' ? trim($url) : null,
            Store::schema_fields_DISPLAY_TYPE => $normalizedType !== '' ? $normalizedType : null,
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
        ?string $url = null,
        ?string $displayType = null,
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
        $normalizedUrl = $this->channelUrlValidator->normalizeAndAssert($url, $storeId);
        $normalizedType = $this->displayTypes->normalizeAssignedCode($displayType);

        $channel = clone $this->channelModel;
        $channel->clear()->setData([
            SalesChannel::schema_fields_WEBSITE_ID => $websiteId,
            SalesChannel::schema_fields_STORE_ID => $storeId,
            SalesChannel::schema_fields_CODE => $code,
            SalesChannel::schema_fields_NAME => $name,
            SalesChannel::schema_fields_IS_DEFAULT => 0,
            SalesChannel::schema_fields_STATUS => 1,
            SalesChannel::schema_fields_URL => $normalizedUrl,
            SalesChannel::schema_fields_DISPLAY_TYPE => $normalizedType !== '' ? $normalizedType : null,
        ])->save();

        return $this->channels->byCode($storeId, $code)
            ?? throw new \RuntimeException(__('销售渠道写入后无法通过目录回读'));
    }

    /** @return array<string,mixed>|null */
    public function getStore(int $storeId): ?array
    {
        if ($storeId < 0) {
            throw new \InvalidArgumentException(__('store_id 不能为负'));
        }
        $store = $this->stores->byId($storeId);
        if ($store === null) {
            return null;
        }
        $row = $store->toArray();
        $model = clone $this->storeModel;
        $model->clear()->load($storeId);
        if ((int)$model->getData(Store::schema_fields_ID) === $storeId
            || ($storeId === Store::ID_DEFAULT && $model->hasData(Store::schema_fields_CODE))
        ) {
            $row['display_type'] = (string)$model->getData(Store::schema_fields_DISPLAY_TYPE);
        } else {
            $row['display_type'] = '';
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function getChannel(int $channelId): ?array
    {
        if ($channelId < 0) {
            throw new \InvalidArgumentException(__('channel_id 不能为负'));
        }
        $channel = $this->channels->byId($channelId);
        if ($channel === null) {
            return null;
        }
        $row = $channel->toArray();
        $model = clone $this->channelModel;
        $model->clear()->load($channelId);
        if ((int)$model->getData(SalesChannel::schema_fields_ID) === $channelId
            || ($channelId === SalesChannel::ID_DEFAULT && $model->hasData(SalesChannel::schema_fields_CODE))
        ) {
            $row['display_type'] = (string)$model->getData(SalesChannel::schema_fields_DISPLAY_TYPE);
            $row['url'] = (string)$model->getData(SalesChannel::schema_fields_URL);
        } else {
            $row['display_type'] = '';
            $row['url'] = (string)($this->channelUrlReader->urlForChannel($channelId) ?? '');
        }

        return $row;
    }

    public function updateStore(
        int $storeId,
        string $name,
        string $mode,
        ?string $url = null,
        ?string $displayType = null,
    ): StoreSummary {
        $existing = $this->stores->byId($storeId);
        if ($existing === null) {
            throw new \InvalidArgumentException(__('店铺不存在'));
        }
        $name = trim($name);
        $mode = strtolower(trim($mode));
        if ($name === '') {
            throw new \InvalidArgumentException(__('店铺名称不能为空'));
        }
        $normalizedType = $this->displayTypes->normalizeAssignedCode($displayType);

        $store = clone $this->storeModel;
        $store->load($storeId);
        if (!$store->hasData(Store::schema_fields_ID)
            || (int)$store->getData(Store::schema_fields_ID) !== $storeId) {
            throw new \InvalidArgumentException(__('店铺不存在'));
        }
        $store->setData(Store::schema_fields_NAME, $name)
            ->setData(Store::schema_fields_STORE_MODE, $mode)
            ->setData(
                Store::schema_fields_URL,
                $url !== null && trim($url) !== '' ? trim($url) : null
            )
            ->setData(
                Store::schema_fields_DISPLAY_TYPE,
                $normalizedType !== '' ? $normalizedType : null
            )
            ->save();

        return $this->stores->byId($storeId)
            ?? throw new \RuntimeException(__('店铺更新后无法通过目录回读'));
    }

    public function updateChannel(
        int $channelId,
        string $name,
        ?string $url = null,
        ?string $displayType = null,
    ): SalesChannelSummary {
        $existing = $this->channels->byId($channelId);
        if ($existing === null) {
            throw new \InvalidArgumentException(__('销售渠道不存在'));
        }
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('销售渠道名称不能为空'));
        }
        $normalizedUrl = $this->channelUrlValidator->normalizeAndAssert($url, (int)$existing->storeId);
        $normalizedType = $this->displayTypes->normalizeAssignedCode($displayType);

        $channel = clone $this->channelModel;
        $channel->load($channelId);
        if (!$channel->hasData(SalesChannel::schema_fields_ID)
            || (int)$channel->getData(SalesChannel::schema_fields_ID) !== $channelId) {
            throw new \InvalidArgumentException(__('销售渠道不存在'));
        }
        $channel->setData(SalesChannel::schema_fields_NAME, $name)
            ->setData(SalesChannel::schema_fields_URL, $normalizedUrl)
            ->setData(
                SalesChannel::schema_fields_DISPLAY_TYPE,
                $normalizedType !== '' ? $normalizedType : null
            )
            ->save();

        return $this->channels->byId($channelId)
            ?? throw new \RuntimeException(__('销售渠道更新后无法通过目录回读'));
    }

    private function assertWebsiteId(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负'));
        }
    }
}
