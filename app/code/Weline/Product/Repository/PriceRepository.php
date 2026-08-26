<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ResolvedScopeValue;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\Price;
use Weline\Product\Service\CatalogConflictException;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\ProductShardProvisioner;

/**
 * Price Store overlay；cleared → 当前币种不可售；删除覆盖行恢复父价。
 */
final class PriceRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): Price)|null */
    private readonly mixed $modelFactory;

    /**
     * @param (\Closure(int): Price)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        private readonly CatalogOverlayResolver $resolver = new CatalogOverlayResolver(),
        ?callable $modelFactory = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
    }

    public function read(int $websiteId, int $storeId, int $offerId, string $currency): ResolvedScopeValue
    {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        $rows = $this->loadRows($websiteId, $offerId, $currency);
        return $this->resolver->resolvePrice($rows, $storeId);
    }

    public function writeExplicit(
        int $websiteId,
        int $storeId,
        int $offerId,
        string $currency,
        int $amountMinor,
    ): void {
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('product_price_amount_negative');
        }
        $this->upsert($websiteId, $storeId, $offerId, $currency, [
            Price::schema_fields_AMOUNT_MINOR => $amountMinor,
            Price::schema_fields_CLEARED => 0,
            'scope_state' => 'explicit',
        ]);
    }

    public function writeCleared(int $websiteId, int $storeId, int $offerId, string $currency): void
    {
        $this->upsert($websiteId, $storeId, $offerId, $currency, [
            Price::schema_fields_AMOUNT_MINOR => 0,
            Price::schema_fields_CLEARED => 1,
            'scope_state' => 'cleared',
        ]);
    }

    public function deleteOverlay(int $websiteId, int $storeId, int $offerId, string $currency): void
    {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        $model = $this->findRow($websiteId, $storeId, $offerId, $currency);
        if ($model !== null) {
            $model->delete();
        }
    }

    /**
     * @throws CatalogConflictException price_cleared_at_scope
     */
    public function assertSellable(int $websiteId, int $storeId, int $offerId, string $currency): int
    {
        $resolved = $this->read($websiteId, $storeId, $offerId, $currency);
        if ($resolved->isCleared()) {
            throw new CatalogConflictException(
                'price_cleared_at_scope',
                __('Price 在 Scope 上已 cleared，当前币种不可售：%{1}', [$currency]),
                [
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                    'offer_id' => $offerId,
                    'currency' => $currency,
                ],
            );
        }
        if ($resolved->isUnresolved()) {
            throw new CatalogConflictException(
                'price_missing',
                __('Price 未配置：offer=%{1} currency=%{2}', [$offerId, $currency]),
                [
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                    'offer_id' => $offerId,
                    'currency' => $currency,
                ],
            );
        }
        return (int)$resolved->value;
    }

    /**
     * Return explicit Website/Store price rows without applying fallback.
     *
     * @param list<int> $offerIds
     * @param list<int> $storeIds
     * @return list<array{store_id:int,offer_id:int,currency:string,amount_minor:int,cleared:bool}>
     */
    public function listExplicitRows(int $websiteId, array $offerIds, array $storeIds): array
    {
        $this->assertWebsite($websiteId);
        $offerIds = array_values(array_unique(array_filter(
            array_map('intval', $offerIds),
            static fn(int $id): bool => $id > 0,
        )));
        $storeIds = array_values(array_unique(array_filter(
            array_map('intval', $storeIds),
            static fn(int $id): bool => $id >= 0,
        )));
        if ($offerIds === [] || $storeIds === []) {
            return [];
        }
        $raw = $this->newModel($websiteId)
            ->clear()
            ->where(Price::schema_fields_OFFER_ID, $offerIds, 'IN')
            ->where(Price::schema_fields_STORE_ID, $storeIds, 'IN')
            ->select()
            ->fetchArray();
        $rows = [];
        foreach ($raw as $item) {
            $cleared = (string)($item['scope_state'] ?? '') === 'cleared'
                || (int)($item[Price::schema_fields_CLEARED] ?? 0) === 1;
            $rows[] = [
                'store_id' => (int)($item[Price::schema_fields_STORE_ID] ?? 0),
                'offer_id' => (int)($item[Price::schema_fields_OFFER_ID] ?? 0),
                'currency' => (string)($item[Price::schema_fields_CURRENCY] ?? ''),
                'amount_minor' => $cleared
                    ? null
                    : (int)($item[Price::schema_fields_AMOUNT_MINOR] ?? 0),
                'scope_state' => $cleared ? 'cleared' : (string)($item['scope_state'] ?? 'explicit'),
                'cleared' => $cleared,
                'version' => (int)($item['version'] ?? 1),
            ];
        }
        usort(
            $rows,
            static fn(array $left, array $right): int => [
                $left['offer_id'],
                $left['store_id'],
                $left['currency'],
            ] <=> [
                $right['offer_id'],
                $right['store_id'],
                $right['currency'],
            ],
        );
        return $rows;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function upsert(
        int $websiteId,
        int $storeId,
        int $offerId,
        string $currency,
        array $fields,
    ): void {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        $currency = strtoupper(trim($currency));
        $existing = $this->findRow($websiteId, $storeId, $offerId, $currency);
        if ($existing !== null) {
            foreach ($fields as $k => $v) {
                $existing->setData($k, $v);
            }
            $existing->setData('version', (int)$existing->getData('version') + 1);
            $existing->save();
            return;
        }
        $model = $this->newModel($websiteId);
        $model->clear()->setData(array_merge([
            Price::schema_fields_STORE_ID => $storeId,
            Price::schema_fields_OFFER_ID => $offerId,
            Price::schema_fields_CURRENCY => $currency,
            'version' => 1,
        ], $fields))->save();
    }

    private function findRow(int $websiteId, int $storeId, int $offerId, string $currency): ?Price
    {
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Price::schema_fields_STORE_ID, $storeId)
            ->where(Price::schema_fields_OFFER_ID, $offerId)
            ->where(Price::schema_fields_CURRENCY, strtoupper(trim($currency)))
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /**
     * @return list<array{store_id:int, cleared:bool, value?:mixed}>
     */
    private function loadRows(int $websiteId, int $offerId, string $currency): array
    {
        $model = $this->newModel($websiteId);
        $raw = $model->clear()
            ->where(Price::schema_fields_OFFER_ID, $offerId)
            ->where(Price::schema_fields_CURRENCY, strtoupper(trim($currency)))
            ->select()
            ->fetchArray();
        $rows = [];
        foreach ($raw as $item) {
            $cleared = (string)($item['scope_state'] ?? '') === 'cleared'
                || (int)($item[Price::schema_fields_CLEARED] ?? 0) === 1;
            $rows[] = [
                'store_id' => (int)($item[Price::schema_fields_STORE_ID] ?? 0),
                'cleared' => $cleared,
                'scope_state' => $cleared ? 'cleared' : (string)($item['scope_state'] ?? 'explicit'),
                'value' => $cleared
                    ? null
                    : (isset($item[Price::schema_fields_AMOUNT_MINOR])
                        ? (int)$item[Price::schema_fields_AMOUNT_MINOR]
                        : null),
            ];
        }
        return $rows;
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var Price $model */
        $model = ObjectManager::create(Price::class, [], false);
        return $model->forWebsite($websiteId);
    }
}
