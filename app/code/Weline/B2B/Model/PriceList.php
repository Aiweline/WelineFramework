<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

/**
 * 版本化价目表。channel_id=null 表示 website 级；非空为 Channel 覆盖。
 *
 * SKU 金额支持数量档：构造参数可为
 * - 旧式 flat：`sku => amount_minor`（等价 min_qty=1）
 * - 档位：`sku => [min_qty => amount_minor]`
 */
final class PriceList
{
    /**
     * Flat 兼容视图：每个 SKU 取 min_qty=1 档（若无则取最低档）金额。
     *
     * @var array<string, int>
     */
    public readonly array $skuAmountsMinor;

    /**
     * 完整数量档：sku => [min_qty => amount_minor]（min_qty 升序键）。
     *
     * @var array<string, array<int, int>>
     */
    public readonly array $skuQtyTiers;

    /**
     * @param array<string, int|array<int, int>> $skuAmountsMinor
     */
    public function __construct(
        public readonly string $listId,
        public readonly string $groupId,
        public readonly int $websiteId,
        public readonly int $version,
        array $skuAmountsMinor,
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

        $tiers = self::normalizeSkuTiers($skuAmountsMinor);
        $flat = [];
        foreach ($tiers as $sku => $byMinQty) {
            $flat[$sku] = array_key_exists(1, $byMinQty)
                ? $byMinQty[1]
                : (int) reset($byMinQty);
        }
        ksort($flat, SORT_STRING);
        $this->skuQtyTiers = $tiers;
        $this->skuAmountsMinor = $flat;
    }

    /**
     * @param array<string, int|array<int, int>> $skuAmountsMinor
     * @return array<string, array<int, int>>
     */
    public static function normalizeSkuTiers(array $skuAmountsMinor): array
    {
        $tiers = [];
        foreach ($skuAmountsMinor as $sku => $amountOrTiers) {
            if (!is_string($sku) || trim($sku) === '' || strlen($sku) > 128) {
                throw new \InvalidArgumentException(__('B2B price list SKU 非法'));
            }
            if (is_int($amountOrTiers)) {
                if ($amountOrTiers < 0) {
                    throw new \InvalidArgumentException(
                        __('B2B price list amount_minor 非法：%{1}', [$sku]),
                    );
                }
                $tiers[$sku][1] = $amountOrTiers;
                continue;
            }
            if (!is_array($amountOrTiers) || $amountOrTiers === []) {
                throw new \InvalidArgumentException(__('B2B price list amount_minor 非法：%{1}', [$sku]));
            }
            foreach ($amountOrTiers as $minQty => $amountMinor) {
                $minQty = (int) $minQty;
                if ($minQty < 1) {
                    throw new \InvalidArgumentException(
                        __('B2B price list min_qty 必须 ≥ 1：%{1}', [$sku]),
                    );
                }
                if (!is_int($amountMinor) || $amountMinor < 0) {
                    throw new \InvalidArgumentException(
                        __('B2B price list amount_minor 非法：%{1}', [$sku]),
                    );
                }
                $tiers[$sku][$minQty] = $amountMinor;
            }
            ksort($tiers[$sku], SORT_NUMERIC);
        }
        ksort($tiers, SORT_STRING);

        return $tiers;
    }

    public function hasSku(string $sku): bool
    {
        return array_key_exists($sku, $this->skuQtyTiers);
    }

    /**
     * 选取 qty ≥ min_qty 中最高档金额；无匹配档返回 null。
     */
    public function amountForSku(string $sku, int $qty = 1): ?int
    {
        $qty = max(1, $qty);
        $tiers = $this->skuQtyTiers[$sku] ?? null;
        if ($tiers === null) {
            return null;
        }
        $bestMin = -1;
        $bestAmount = null;
        foreach ($tiers as $minQty => $amountMinor) {
            if ($qty >= $minQty && $minQty > $bestMin) {
                $bestMin = $minQty;
                $bestAmount = $amountMinor;
            }
        }

        return $bestAmount;
    }

    /**
     * @return list<array{sku:string,min_qty:int,amount_minor:int}>
     */
    public function itemRows(): array
    {
        $rows = [];
        foreach ($this->skuQtyTiers as $sku => $tiers) {
            foreach ($tiers as $minQty => $amountMinor) {
                $rows[] = [
                    'sku' => $sku,
                    'min_qty' => (int) $minQty,
                    'amount_minor' => (int) $amountMinor,
                ];
            }
        }

        return $rows;
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
            'sku_count' => count($this->skuQtyTiers),
        ];
    }
}
