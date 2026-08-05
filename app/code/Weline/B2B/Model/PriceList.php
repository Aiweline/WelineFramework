<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

/**
 * 版本化价目表。channel_id=null 表示 website 级；非空为 Channel 覆盖。
 */
final class PriceList
{
    /**
     * @param array<string, int> $skuAmountsMinor sku => amount_minor
     */
    public function __construct(
        public readonly string $listId,
        public readonly string $groupId,
        public readonly int $websiteId,
        public readonly int $version,
        public readonly array $skuAmountsMinor,
        public readonly ?string $channelId = null,
        public readonly bool $active = true,
    ) {
        if ($listId === '' || strlen($listId) > 64) {
            throw new \InvalidArgumentException(__('B2B price list_id 必填且不能超过 64 字符'));
        }
        if ($groupId === '' || strlen($groupId) > 64) {
            throw new \InvalidArgumentException(__('B2B price list group_id 非法'));
        }
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('B2B price list website_id 不能为负数：%{1}', [$websiteId]));
        }
        if ($version < 1) {
            throw new \InvalidArgumentException(__('B2B price list version 必须大于 0'));
        }
        if ($channelId !== null && ($channelId === '' || strlen($channelId) > 64)) {
            throw new \InvalidArgumentException(__('B2B price list channel_id 非法'));
        }
        if ($skuAmountsMinor === []) {
            throw new \InvalidArgumentException(__('B2B price list 至少包含一个 SKU'));
        }
        foreach ($skuAmountsMinor as $sku => $amountMinor) {
            if (!is_string($sku) || trim($sku) === '' || strlen($sku) > 128) {
                throw new \InvalidArgumentException(__('B2B price list SKU 非法'));
            }
            if (!is_int($amountMinor) || $amountMinor < 0) {
                throw new \InvalidArgumentException(
                    __('B2B price list amount_minor 非法：%{1}', [$sku]),
                );
            }
        }
    }

    public function amountForSku(string $sku): ?int
    {
        return array_key_exists($sku, $this->skuAmountsMinor)
            ? (int) $this->skuAmountsMinor[$sku]
            : null;
    }

    /**
     * @return array{list_id:string,group_id:string,website_id:int,version:int,channel_id:?string,active:bool,sku_count:int}
     */
    public function toMeta(): array
    {
        return [
            'list_id' => $this->listId,
            'group_id' => $this->groupId,
            'website_id' => $this->websiteId,
            'version' => $this->version,
            'channel_id' => $this->channelId,
            'active' => $this->active,
            'sku_count' => count($this->skuAmountsMinor),
        ];
    }
}
