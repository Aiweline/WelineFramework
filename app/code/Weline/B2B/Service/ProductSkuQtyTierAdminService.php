<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\PriceList;
use Weline\B2B\Model\SystemVipLadder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Offer;

/**
 * Product-edit website-level (channel_id=null) qty-tier upsert with full
 * copy-forward merge across immutable price-list revisions.
 */
final class ProductSkuQtyTierAdminService
{
    /** @var (callable(int,int): list<string>)|null */
    private $productSkuLoader;

    /**
     * @param (callable(int,int): list<string>)|null $productSkuLoader
     */
    public function __construct(
        private readonly B2BService $service,
        ?callable $productSkuLoader = null,
    ) {
        $this->productSkuLoader = $productSkuLoader;
    }

    public static function forTesting(B2BService $service, ?callable $productSkuLoader = null): self
    {
        return new self($service, $productSkuLoader);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function upsertSkuQtyTiers(array $input): array
    {
        $websiteId = (int)($input['website_id'] ?? -1);
        $groupId = trim((string)($input['group_id'] ?? ''));
        $productId = (int)($input['product_id'] ?? 0);
        $expectedVersion = array_key_exists('expected_version', $input)
            ? (int)$input['expected_version']
            : null;

        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)\__('网站无效'));
        }
        if ($groupId === '') {
            throw new \InvalidArgumentException((string)\__('请选择客户组'));
        }
        if ($productId <= 0) {
            throw new \InvalidArgumentException((string)\__('商品无效'));
        }

        $group = $this->service->engine()->groups()->get($groupId);
        if ($group === null) {
            throw new B2BConflictException(
                'b2b_group_not_found',
                \__('B2B group 不存在：%{1}', [$groupId]),
                ['group_id' => $groupId],
            );
        }
        if ($group->websiteId !== $websiteId) {
            throw new B2BConflictException(
                B2BPriceEngine::ERROR_GROUP_WEBSITE_MISMATCH,
                \__('B2B price list 与 group Website 不一致'),
                ['group_id' => $groupId, 'website_id' => $websiteId],
            );
        }

        $allowedSkus = $this->loadProductSkus($websiteId, $productId);
        if ($allowedSkus === []) {
            throw new \InvalidArgumentException((string)\__('请先保存商品 SKU 后再配置阶梯价'));
        }
        $allowedMap = array_fill_keys($allowedSkus, true);

        $productTiers = $this->normalizeIncomingTiers(
            $this->decodeTiersInput($input),
            $allowedMap,
        );
        $this->assertTiersPassGuard($websiteId, $productTiers, $input);
        $lists = $this->service->engine()->lists();
        $resolved = $this->resolveWebsiteList($groupId, $websiteId, $allowedSkus);

        if ($resolved['list'] === null) {
            if ($productTiers === []) {
                return [
                    'list_id' => '',
                    'version' => 0,
                    'active' => false,
                    'group_id' => $groupId,
                    'website_id' => $websiteId,
                    'channel_id' => null,
                    'sku_tiers' => [],
                    'created' => false,
                    'deactivated' => false,
                ];
            }
            $listId = 'pl-' . bin2hex(random_bytes(6));
            $list = $this->service->seedPriceList(
                $listId,
                $groupId,
                $websiteId,
                1,
                $productTiers,
                null,
                true,
            );
            $this->invalidateStorefrontCache($websiteId, array_keys($productTiers));

            return $this->resultMeta($list, true, false);
        }

        $current = $resolved['list'];
        $listId = $current->listId;
        if ($expectedVersion !== null && $expectedVersion > 0 && $expectedVersion !== $current->version) {
            throw new B2BConflictException(
                'b2b_price_list_version_conflict',
                \__('价目表已被他人更新，请刷新后重试。'),
                [
                    'list_id' => $listId,
                    'expected_version' => $expectedVersion,
                    'current_version' => $current->version,
                ],
            );
        }

        $merged = $current->skuQtyTiers;
        foreach ($allowedSkus as $sku) {
            unset($merged[$sku]);
        }
        foreach ($productTiers as $sku => $byMinQty) {
            $merged[$sku] = $byMinQty;
        }

        $nextVersion = $current->version + 1;
        if ($merged === []) {
            // PriceList forbids empty SKU set: keep last snapshot, deactivate.
            $deactivated = new PriceList(
                $listId,
                $groupId,
                $websiteId,
                $nextVersion,
                $current->skuQtyTiers,
                null,
                false,
            );
            $lists->put($deactivated);
            $this->invalidateStorefrontCache($websiteId, $allowedSkus);

            return $this->resultMeta($deactivated, false, true);
        }

        $next = new PriceList(
            $listId,
            $groupId,
            $websiteId,
            $nextVersion,
            $merged,
            null,
            true,
        );
        try {
            $lists->put($next);
        } catch (B2BConflictException $conflict) {
            throw new B2BConflictException(
                'b2b_price_list_version_conflict',
                \__('价目表写入冲突，请刷新后重试。'),
                [
                    'list_id' => $listId,
                    'expected_version' => $current->version,
                ],
                0,
                $conflict,
            );
        }

        $this->invalidateStorefrontCache($websiteId, $allowedSkus);

        return $this->resultMeta($next, false, false);
    }

    /**
     * Read website-level tiers for product SKUs (for offers Hook SSR).
     *
     * @return array{
     *   group_id:string,
     *   list_id:string,
     *   version:int,
     *   active:bool,
     *   channel_id:null,
     *   sku_tiers:array<string, array<int,int>>,
     *   product_skus:list<string>,
     *   group_options:list<array{value:string,label:string}>
     * }
     */
    public function loadEditorState(int $websiteId, int $productId, ?string $groupId = null): array
    {
        $groupStore = $this->service->engine()->groups();
        $groupStore->ensureSystemVipLadder($websiteId);
        $options = [];
        foreach ($groupStore->listActiveOptions() as $row) {
            if ((int)($row['website_id'] ?? -1) !== $websiteId) {
                continue;
            }
            $options[] = [
                'value' => (string)$row['value'],
                'label' => (string)($row['label'] ?? $row['value']),
            ];
        }

        $resolvedGroupId = trim((string)$groupId);
        if ($resolvedGroupId === '') {
            $vip0 = SystemVipLadder::groupId(0);
            $resolvedGroupId = $vip0;
            $found = false;
            foreach ($options as $opt) {
                if ($opt['value'] === $vip0) {
                    $found = true;
                    break;
                }
            }
            if (!$found && $options !== []) {
                $resolvedGroupId = $options[0]['value'];
            }
        }

        $productSkus = $productId > 0 ? $this->loadProductSkus($websiteId, $productId) : [];
        $resolved = $this->resolveWebsiteList($resolvedGroupId, $websiteId, $productSkus);
        $list = $resolved['list'];
        $skuTiers = [];
        if ($list !== null && $list->active) {
            foreach ($productSkus as $sku) {
                if (isset($list->skuQtyTiers[$sku])) {
                    $skuTiers[$sku] = $list->skuQtyTiers[$sku];
                }
            }
        }

        return [
            'group_id' => $resolvedGroupId,
            'list_id' => $list?->listId ?? '',
            'version' => $list?->version ?? 0,
            'active' => (bool)($list?->active ?? false),
            'channel_id' => null,
            'sku_tiers' => $skuTiers,
            'product_skus' => $productSkus,
            'group_options' => $options,
            'inherits_default_policy' => $skuTiers === [],
            'default_policy_preview' => $this->defaultPolicyPreview($resolvedGroupId, $websiteId),
        ];
    }

    /**
     * @return list<array{min_qty:int,discount_bps:int,discount_percent:float}>
     */
    private function defaultPolicyPreview(string $groupId, int $websiteId): array
    {
        try {
            $policy = ObjectManager::getInstance(DefaultWholesalePolicy::class);
            if (!$policy instanceof DefaultWholesalePolicy) {
                return [];
            }
            if (!$policy->groupCanInheritTemplate($groupId)) {
                return [];
            }
            $out = [];
            foreach ($policy->tiersForGroup($groupId, $websiteId) as $tier) {
                $bps = (int) $tier['discount_bps'];
                $out[] = [
                    'min_qty' => (int) $tier['min_qty'],
                    'discount_bps' => $bps,
                    'discount_percent' => round($bps / 100, 2),
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, array<int,int>> $productTiers
     * @param array<string,mixed> $input
     */
    private function assertTiersPassGuard(int $websiteId, array $productTiers, array $input): void
    {
        if ($productTiers === []) {
            return;
        }
        $policy = null;
        try {
            $policy = ObjectManager::getInstance(DefaultWholesalePolicy::class);
        } catch (\Throwable) {
        }
        if (!$policy instanceof DefaultWholesalePolicy) {
            $policy = DefaultWholesalePolicy::forTesting();
        }
        $guard = new WholesalePricingGuard();
        $maxBps = $policy->maxDiscountBps($websiteId);
        $minMargin = $policy->minMarginBps($websiteId);
        $retailBySku = is_array($input['retail_by_sku'] ?? null) ? $input['retail_by_sku'] : [];
        $costBySku = is_array($input['cost_by_sku'] ?? null) ? $input['cost_by_sku'] : [];
        foreach ($productTiers as $sku => $byMin) {
            $retail = (int) ($retailBySku[$sku] ?? $input['retail_amount_minor'] ?? -1);
            if ($retail < 0) {
                continue;
            }
            $cost = null;
            if (isset($costBySku[$sku]) && is_numeric($costBySku[$sku])) {
                $cost = (float) $costBySku[$sku];
            }
            foreach ($byMin as $amount) {
                $guard->assertAmountAllowed($retail, (int) $amount, $maxBps, $minMargin, $cost);
            }
        }
    }

    /**
     * @param list<string> $productSkus
     * @return array{list:?PriceList,list_id:string}
     */
    public function resolveWebsiteList(string $groupId, int $websiteId, array $productSkus): array
    {
        $lists = $this->service->engine()->lists();
        $active = $lists->activeForGroup($groupId, $websiteId, null);
        $websiteLevel = [];
        foreach ($active as $list) {
            if ($list->channelId !== null) {
                continue;
            }
            $websiteLevel[] = $list;
        }

        $bestMatch = null;
        $bestVersion = -1;
        foreach ($websiteLevel as $list) {
            foreach ($productSkus as $sku) {
                if (!$list->hasSku($sku)) {
                    continue;
                }
                if ($list->version > $bestVersion) {
                    $bestVersion = $list->version;
                    $bestMatch = $list;
                }
            }
        }
        if ($bestMatch !== null) {
            // Prefer latest revision of that list_id
            $latest = $lists->get($bestMatch->listId);
            return ['list' => $latest ?? $bestMatch, 'list_id' => $bestMatch->listId];
        }

        // Unique website-level active list for group+site (dedupe by list_id latest)
        $byId = [];
        foreach ($websiteLevel as $list) {
            $existing = $byId[$list->listId] ?? null;
            if ($existing === null || $list->version > $existing->version) {
                $byId[$list->listId] = $list;
            }
        }
        if (count($byId) === 1) {
            $only = reset($byId);
            $latest = $lists->get($only->listId);

            return ['list' => $latest ?? $only, 'list_id' => $only->listId];
        }

        return ['list' => null, 'list_id' => ''];
    }

    /**
     * @param array<string,mixed> $input
     * @return mixed
     */
    private function decodeTiersInput(array $input): mixed
    {
        if (isset($input['tiers']) && is_array($input['tiers'])) {
            return $input['tiers'];
        }
        $json = trim((string)($input['tiers_json'] ?? ''));
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $raw
     * @param array<string,true> $allowedMap
     * @return array<string, array<int,int>>
     */
    private function normalizeIncomingTiers(mixed $raw, array $allowedMap): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = trim((string)($row['sku'] ?? ''));
            if ($sku === '' || !isset($allowedMap[$sku])) {
                throw new \InvalidArgumentException(
                    (string)\__('SKU 不属于本商品或不允许写入：%{1}', [$sku !== '' ? $sku : '-']),
                );
            }
            $minQty = (int)($row['min_qty'] ?? 0);
            $amount = (int)($row['amount_minor'] ?? -1);
            if ($minQty < 1) {
                throw new \InvalidArgumentException((string)\__('起订量 min_qty 必须 ≥ 1'));
            }
            if ($amount < 0) {
                throw new \InvalidArgumentException((string)\__('批发价（分）必须 ≥ 0'));
            }
            if (isset($out[$sku][$minQty])) {
                throw new \InvalidArgumentException(
                    (string)\__('同一 SKU 的起订量不可重复：%{1} @ %{2}', [$sku, $minQty]),
                );
            }
            $out[$sku][$minQty] = $amount;
        }
        foreach ($out as $sku => $byMinQty) {
            ksort($out[$sku], SORT_NUMERIC);
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @return list<string> */
    private function loadProductSkus(int $websiteId, int $productId): array
    {
        if ($this->productSkuLoader !== null) {
            $skus = ($this->productSkuLoader)($websiteId, $productId);

            return array_values(array_unique(array_filter(array_map(
                static fn ($s): string => trim((string)$s),
                is_array($skus) ? $skus : [],
            ), static fn (string $s): bool => $s !== '')));
        }

        try {
            if (!class_exists(Offer::class)) {
                return [];
            }
            /** @var Offer $offer */
            $offer = ObjectManager::create(Offer::class, [], false);
            $rows = $offer->forWebsite($websiteId)
                ->reset()
                ->where(Offer::schema_fields_PRODUCT_ID, $productId)
                ->select()
                ->fetchArray();
            $skus = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sku = trim((string)($row[Offer::schema_fields_SKU] ?? ''));
                if ($sku !== '') {
                    $skus[$sku] = true;
                }
            }

            return array_keys($skus);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<string> $skus
     */
    private function invalidateStorefrontCache(int $websiteId, array $skus): void
    {
        try {
            if (!class_exists(\Weline\Product\Service\ProductStorefrontCacheInvalidator::class)) {
                return;
            }
            $invalidator = ObjectManager::getInstance(
                \Weline\Product\Service\ProductStorefrontCacheInvalidator::class,
            );
            if (is_object($invalidator) && method_exists($invalidator, 'clearForCatalogChange')) {
                $invalidator->clearForCatalogChange(
                    'b2b_price_list_tiers:' . $websiteId . ':' . implode(',', array_slice($skus, 0, 8)),
                );
            }
        } catch (\Throwable) {
            // Optional Product module; never block price-list write.
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function resultMeta(PriceList $list, bool $created, bool $deactivated): array
    {
        return [
            'list_id' => $list->listId,
            'version' => $list->version,
            'active' => $list->active,
            'group_id' => $list->groupId,
            'website_id' => $list->websiteId,
            'channel_id' => $list->channelId,
            'sku_tiers' => $list->skuQtyTiers,
            'created' => $created,
            'deactivated' => $deactivated,
        ];
    }
}
