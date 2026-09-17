<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

/**
 * 把网站/店/渠实体映射为 SystemConfig 可写 storage_scope（含 default 站哨兵）。
 */
final class SiteContactScopeMapper
{
    public function __construct(
        private readonly SystemConfigTargetScopeService $targetScopeService,
    ) {
    }

    /**
     * @return array{ok:bool,storage_scope:string,website_code:string,store_code:string,channel_code:string,scope_kind:string,label:string}
     */
    public function forWebsite(array $website): array
    {
        $code = strtolower(trim((string)($website['code'] ?? '')));
        if ($code === '') {
            return $this->fail();
        }

        return $this->pack(
            $this->targetScopeService->resolveFromInput([
                'website_code' => $code,
                'scope_kind' => ScopeIdentity::KIND_WEBSITE,
            ], false),
            (string)__('当前网站'),
        );
    }

    /**
     * @param array<string, mixed> $store
     * @return array{ok:bool,storage_scope:string,website_code:string,store_code:string,channel_code:string,scope_kind:string,label:string}
     */
    public function forStore(array $store): array
    {
        $storeCode = strtolower(trim((string)($store['code'] ?? '')));
        $websiteId = (int)($store['website_id'] ?? 0);
        $websiteCode = $this->websiteCodeById($websiteId);
        if ($websiteCode === '' || $storeCode === '') {
            return $this->fail();
        }

        return $this->pack(
            $this->targetScopeService->resolveFromInput([
                'website_code' => $websiteCode,
                'store_code' => $storeCode,
                'scope_kind' => ScopeIdentity::KIND_STORE,
            ], false),
            (string)__('当前商店'),
        );
    }

    /**
     * @param array<string, mixed> $channel
     * @return array{ok:bool,storage_scope:string,website_code:string,store_code:string,channel_code:string,scope_kind:string,label:string}
     */
    public function forChannel(array $channel): array
    {
        $channelCode = strtolower(trim((string)($channel['code'] ?? '')));
        $storeId = (int)($channel['store_id'] ?? 0);
        $websiteId = (int)($channel['website_id'] ?? 0);
        $storeCode = $this->storeCodeById($storeId);
        $websiteCode = $this->websiteCodeById($websiteId);
        if ($websiteCode === '' && $storeId > 0) {
            $websiteCode = $this->websiteCodeByStoreId($storeId);
        }
        if ($websiteCode === '' || $storeCode === '' || $channelCode === '') {
            return $this->fail();
        }

        return $this->pack(
            $this->targetScopeService->resolveFromInput([
                'website_code' => $websiteCode,
                'store_code' => $storeCode,
                'channel_code' => $channelCode,
                'scope_kind' => ScopeIdentity::KIND_CHANNEL,
            ], false),
            (string)__('当前渠道'),
        );
    }

    /**
     * @param array{kind:string,website_code:string,store_code:string,channel_code:string,storage_scope:string} $target
     * @return array{ok:bool,storage_scope:string,website_code:string,store_code:string,channel_code:string,scope_kind:string,label:string}
     */
    private function pack(array $target, string $label): array
    {
        $storage = trim((string)($target['storage_scope'] ?? ''));
        if ($storage === '') {
            return $this->fail();
        }

        return [
            'ok' => true,
            'storage_scope' => $storage,
            'website_code' => (string)($target['website_code'] ?? ''),
            'store_code' => (string)($target['store_code'] ?? ''),
            'channel_code' => (string)($target['channel_code'] ?? ''),
            'scope_kind' => (string)($target['kind'] ?? ''),
            'label' => $label,
        ];
    }

    /**
     * @return array{ok:bool,storage_scope:string,website_code:string,store_code:string,channel_code:string,scope_kind:string,label:string}
     */
    private function fail(): array
    {
        return [
            'ok' => false,
            'storage_scope' => '',
            'website_code' => '',
            'store_code' => '',
            'channel_code' => '',
            'scope_kind' => '',
            'label' => '',
        ];
    }

    private function websiteCodeById(int $websiteId): string
    {
        if ($websiteId < 0) {
            return '';
        }
        try {
            /** @var Website $model */
            $model = ObjectManager::getInstance(Website::class);
            $row = $model->clear()->where(Website::schema_fields_ID, $websiteId)->find()->fetch();
            if (!$row || $row->getId() === null || $row->getId() === '') {
                return '';
            }

            return strtolower(trim((string)$row->getData(Website::schema_fields_CODE)));
        } catch (\Throwable) {
            return '';
        }
    }

    private function storeCodeById(int $storeId): string
    {
        if ($storeId <= 0) {
            return '';
        }
        try {
            /** @var Store $model */
            $model = ObjectManager::getInstance(Store::class);
            $row = $model->clear()->where(Store::schema_fields_ID, $storeId)->find()->fetch();
            if (!$row || $row->getId() === null || $row->getId() === '') {
                return '';
            }

            return strtolower(trim((string)$row->getData(Store::schema_fields_CODE)));
        } catch (\Throwable) {
            return '';
        }
    }

    private function websiteCodeByStoreId(int $storeId): string
    {
        if ($storeId <= 0) {
            return '';
        }
        try {
            /** @var Store $model */
            $model = ObjectManager::getInstance(Store::class);
            $row = $model->clear()->where(Store::schema_fields_ID, $storeId)->find()->fetch();
            if (!$row || $row->getId() === null || $row->getId() === '') {
                return '';
            }

            return $this->websiteCodeById((int)$row->getData(Store::schema_fields_WEBSITE_ID));
        } catch (\Throwable) {
            return '';
        }
    }
}
