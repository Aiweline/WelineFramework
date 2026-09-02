<?php

declare(strict_types=1);

namespace Weline\Product\Service\HanfuCleanup;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\OfferIdentityRegistry;
use Weline\Product\Model\ProductIdentityRegistry;
use Weline\Product\Model\ProductShardKey;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\SkuRegistry;

/**
 * Removes only explicitly selected global identities after every website shard
 * and the SKU reference counter prove that the identity is no longer used.
 */
final class HanfuIdentityCleanupService
{
    /** @var (\Closure(): ProductIdentityRegistry)|null */
    private readonly mixed $productRegistryFactory;

    /** @var (\Closure(): OfferIdentityRegistry)|null */
    private readonly mixed $offerRegistryFactory;

    /** @var (\Closure(): SkuRegistry)|null */
    private readonly mixed $skuRegistryFactory;

    /** @var (\Closure(): ProductShardRegistry)|null */
    private readonly mixed $shardRegistryFactory;

    /** @var (\Closure(): Product)|null */
    private readonly mixed $shardProductFactory;

    /** @var (\Closure(): Offer)|null */
    private readonly mixed $shardOfferFactory;

    /**
     * @param (\Closure(): ProductIdentityRegistry)|null $productRegistryFactory
     * @param (\Closure(): OfferIdentityRegistry)|null $offerRegistryFactory
     * @param (\Closure(): SkuRegistry)|null $skuRegistryFactory
     * @param (\Closure(): ProductShardRegistry)|null $shardRegistryFactory
     * @param (\Closure(): Product)|null $shardProductFactory
     * @param (\Closure(): Offer)|null $shardOfferFactory
     */
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DatabaseTransactionRunnerInterface $transactions,
        ?callable $productRegistryFactory = null,
        ?callable $offerRegistryFactory = null,
        ?callable $skuRegistryFactory = null,
        ?callable $shardRegistryFactory = null,
        ?callable $shardProductFactory = null,
        ?callable $shardOfferFactory = null,
    ) {
        $this->productRegistryFactory = $productRegistryFactory;
        $this->offerRegistryFactory = $offerRegistryFactory;
        $this->skuRegistryFactory = $skuRegistryFactory;
        $this->shardRegistryFactory = $shardRegistryFactory;
        $this->shardProductFactory = $shardProductFactory;
        $this->shardOfferFactory = $shardOfferFactory;
    }

    /**
     * @param list<string> $productUuids
     * @param list<string> $offerUuids
     * @return array{
     *   product_registry:int,
     *   offer_registry:int,
     *   sku_registry:int,
     *   protected_references:list<array<string,mixed>>
     * }
     */
    public function preview(array $productUuids, array $offerUuids): array
    {
        $plan = $this->buildPlan(
            $this->normalizeUuids($productUuids),
            $this->normalizeUuids($offerUuids),
        );
        return $plan['preview'];
    }

    /**
     * @param list<string> $productUuids
     * @param list<string> $offerUuids
     * @return array{product_registry:int,offer_registry:int,sku_registry:int}
     */
    public function purgeUnreferenced(array $productUuids, array $offerUuids): array
    {
        $productUuids = $this->normalizeUuids($productUuids);
        $offerUuids = $this->normalizeUuids($offerUuids);
        if ($productUuids === [] && $offerUuids === []) {
            return $this->emptyCounts();
        }

        return $this->transactions->run(
            $this->connectionFactory,
            function () use ($productUuids, $offerUuids): array {
                $plan = $this->buildPlan($productUuids, $offerUuids);
                $preview = $plan['preview'];

                if ($plan['sku_registry_ids'] !== []) {
                    $this->newSkuRegistry()
                        ->clear()
                        ->where(SkuRegistry::schema_fields_ID, $plan['sku_registry_ids'], 'IN')
                        ->delete()
                        ->fetch();
                }
                if ($plan['offer_uuids'] !== []) {
                    $this->newOfferRegistry()
                        ->clear()
                        ->where(OfferIdentityRegistry::schema_fields_UUID, $plan['offer_uuids'], 'IN')
                        ->delete()
                        ->fetch();
                }
                if ($plan['product_uuids'] !== []) {
                    $this->newProductRegistry()
                        ->clear()
                        ->where(ProductIdentityRegistry::schema_fields_UUID, $plan['product_uuids'], 'IN')
                        ->delete()
                        ->fetch();
                }

                return [
                    'product_registry' => $preview['product_registry'],
                    'offer_registry' => $preview['offer_registry'],
                    'sku_registry' => $preview['sku_registry'],
                ];
            },
        );
    }

    /**
     * @param list<string> $productUuids
     * @param list<string> $offerUuids
     * @return array{
     *   preview:array{product_registry:int,offer_registry:int,sku_registry:int,protected_references:list<array<string,mixed>>},
     *   product_uuids:list<string>,
     *   offer_uuids:list<string>,
     *   sku_registry_ids:list<int>
     * }
     */
    private function buildPlan(array $productUuids, array $offerUuids): array
    {
        if ($productUuids === [] && $offerUuids === []) {
            return [
                'preview' => $this->emptyPreview(),
                'product_uuids' => [],
                'offer_uuids' => [],
                'sku_registry_ids' => [],
            ];
        }

        $productRows = $this->selectRows(
            $this->newProductRegistry(),
            ProductIdentityRegistry::schema_fields_UUID,
            $productUuids,
            [ProductIdentityRegistry::schema_fields_ID, ProductIdentityRegistry::schema_fields_UUID],
        );
        $offerRows = $this->selectRows(
            $this->newOfferRegistry(),
            OfferIdentityRegistry::schema_fields_UUID,
            $offerUuids,
            [
                OfferIdentityRegistry::schema_fields_ID,
                OfferIdentityRegistry::schema_fields_UUID,
                OfferIdentityRegistry::schema_fields_PRODUCT_UUID,
                OfferIdentityRegistry::schema_fields_SKU,
            ],
        );
        $skuRows = $this->candidateSkuRows($productUuids, $offerUuids);

        $protectedProducts = [];
        $protectedOffers = [];
        $protectedReferences = [];

        $candidateSkuToOffer = [];
        foreach ($offerRows as $row) {
            $uuid = trim((string)($row[OfferIdentityRegistry::schema_fields_UUID] ?? ''));
            $sku = trim((string)($row[OfferIdentityRegistry::schema_fields_SKU] ?? ''));
            if ($uuid !== '' && $sku !== '') {
                $candidateSkuToOffer[$sku] = $uuid;
            }
        }
        foreach ($skuRows as $row) {
            $uuid = trim((string)($row[SkuRegistry::schema_fields_GLOBAL_OFFER_UUID] ?? ''));
            $sku = trim((string)($row[SkuRegistry::schema_fields_SKU] ?? ''));
            if (in_array($uuid, $offerUuids, true) && $sku !== '') {
                $candidateSkuToOffer[$sku] = $uuid;
            }
        }

        foreach ($this->registeredWebsiteIds() as $websiteId) {
            if ($productUuids !== []) {
                $rows = $this->newShardProduct()
                    ->forWebsite($websiteId)
                    ->clear()
                    ->fields([Product::schema_fields_GLOBAL_PRODUCT_UUID])
                    ->where(Product::schema_fields_GLOBAL_PRODUCT_UUID, $productUuids, 'IN')
                    ->select()
                    ->fetchArray();
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $uuid = trim((string)($row[Product::schema_fields_GLOBAL_PRODUCT_UUID] ?? ''));
                    if ($uuid === '') {
                        continue;
                    }
                    $protectedProducts[$uuid] = true;
                    $this->addProtectedReference($protectedReferences, [
                        'reference_type' => 'product_shard',
                        'uuid' => $uuid,
                        'website_id' => $websiteId,
                    ]);
                }
            }

            if ($offerUuids !== []) {
                $rows = $this->newShardOffer()
                    ->forWebsite($websiteId)
                    ->clear()
                    ->fields([Offer::schema_fields_GLOBAL_OFFER_UUID, Offer::schema_fields_SKU])
                    ->where(Offer::schema_fields_GLOBAL_OFFER_UUID, $offerUuids, 'IN')
                    ->select()
                    ->fetchArray();
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $uuid = trim((string)($row[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? ''));
                    if ($uuid === '') {
                        continue;
                    }
                    $protectedOffers[$uuid] = true;
                    $this->addProtectedReference($protectedReferences, [
                        'reference_type' => 'offer_shard',
                        'uuid' => $uuid,
                        'website_id' => $websiteId,
                        'sku' => trim((string)($row[Offer::schema_fields_SKU] ?? '')),
                    ]);
                }
            }

            if ($candidateSkuToOffer !== []) {
                $rows = $this->newShardOffer()
                    ->forWebsite($websiteId)
                    ->clear()
                    ->fields([Offer::schema_fields_GLOBAL_OFFER_UUID, Offer::schema_fields_SKU])
                    ->where(Offer::schema_fields_SKU, array_keys($candidateSkuToOffer), 'IN')
                    ->select()
                    ->fetchArray();
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $sku = trim((string)($row[Offer::schema_fields_SKU] ?? ''));
                    $candidateUuid = $candidateSkuToOffer[$sku] ?? '';
                    if ($candidateUuid === '') {
                        continue;
                    }
                    $protectedOffers[$candidateUuid] = true;
                    $this->addProtectedReference($protectedReferences, [
                        'reference_type' => 'offer_shard_sku',
                        'uuid' => $candidateUuid,
                        'website_id' => $websiteId,
                        'sku' => $sku,
                    ]);
                }
            }
        }

        foreach ($skuRows as $row) {
            $offerUuid = trim((string)($row[SkuRegistry::schema_fields_GLOBAL_OFFER_UUID] ?? ''));
            $refCount = (int)($row[SkuRegistry::schema_fields_REF_COUNT] ?? 0);
            if (!in_array($offerUuid, $offerUuids, true) || $refCount <= 0) {
                continue;
            }
            $protectedOffers[$offerUuid] = true;
            $this->addProtectedReference($protectedReferences, [
                'reference_type' => 'sku_registry_ref_count',
                'uuid' => $offerUuid,
                'product_uuid' => trim((string)($row[SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID] ?? '')),
                'sku' => trim((string)($row[SkuRegistry::schema_fields_SKU] ?? '')),
                'ref_count' => $refCount,
            ]);
        }

        $safeOfferUuids = array_values(array_filter(
            $offerUuids,
            static fn(string $uuid): bool => !isset($protectedOffers[$uuid]),
        ));
        $safeOfferSet = array_fill_keys($safeOfferUuids, true);
        $skuRegistryIds = [];
        foreach ($skuRows as $row) {
            $offerUuid = trim((string)($row[SkuRegistry::schema_fields_GLOBAL_OFFER_UUID] ?? ''));
            if (!isset($safeOfferSet[$offerUuid])
                || (int)($row[SkuRegistry::schema_fields_REF_COUNT] ?? 0) > 0
            ) {
                continue;
            }
            $id = (int)($row[SkuRegistry::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $skuRegistryIds[$id] = true;
            }
        }

        $safeSkuIds = $skuRegistryIds;
        $dependentOfferRows = $this->selectRows(
            $this->newOfferRegistry(),
            OfferIdentityRegistry::schema_fields_PRODUCT_UUID,
            $productUuids,
            [
                OfferIdentityRegistry::schema_fields_UUID,
                OfferIdentityRegistry::schema_fields_PRODUCT_UUID,
                OfferIdentityRegistry::schema_fields_SKU,
            ],
        );
        foreach ($dependentOfferRows as $row) {
            $productUuid = trim((string)($row[OfferIdentityRegistry::schema_fields_PRODUCT_UUID] ?? ''));
            $offerUuid = trim((string)($row[OfferIdentityRegistry::schema_fields_UUID] ?? ''));
            if ($productUuid === '' || isset($safeOfferSet[$offerUuid])) {
                continue;
            }
            $protectedProducts[$productUuid] = true;
            $this->addProtectedReference($protectedReferences, [
                'reference_type' => 'dependent_offer_identity',
                'uuid' => $productUuid,
                'offer_uuid' => $offerUuid,
                'sku' => trim((string)($row[OfferIdentityRegistry::schema_fields_SKU] ?? '')),
            ]);
        }
        foreach ($skuRows as $row) {
            $productUuid = trim((string)($row[SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID] ?? ''));
            $id = (int)($row[SkuRegistry::schema_fields_ID] ?? 0);
            if ($productUuid === '' || !in_array($productUuid, $productUuids, true) || isset($safeSkuIds[$id])) {
                continue;
            }
            $protectedProducts[$productUuid] = true;
            $this->addProtectedReference($protectedReferences, [
                'reference_type' => 'dependent_sku_registry',
                'uuid' => $productUuid,
                'offer_uuid' => trim((string)($row[SkuRegistry::schema_fields_GLOBAL_OFFER_UUID] ?? '')),
                'sku' => trim((string)($row[SkuRegistry::schema_fields_SKU] ?? '')),
                'ref_count' => (int)($row[SkuRegistry::schema_fields_REF_COUNT] ?? 0),
            ]);
        }

        $safeProductSet = array_fill_keys(array_values(array_filter(
            $productUuids,
            static fn(string $uuid): bool => !isset($protectedProducts[$uuid]),
        )), true);
        $productDeleteUuids = [];
        foreach ($productRows as $row) {
            $uuid = trim((string)($row[ProductIdentityRegistry::schema_fields_UUID] ?? ''));
            if ($uuid !== '' && isset($safeProductSet[$uuid])) {
                $productDeleteUuids[] = $uuid;
            }
        }
        $offerDeleteUuids = [];
        foreach ($offerRows as $row) {
            $uuid = trim((string)($row[OfferIdentityRegistry::schema_fields_UUID] ?? ''));
            if ($uuid !== '' && isset($safeOfferSet[$uuid])) {
                $offerDeleteUuids[] = $uuid;
            }
        }

        $protectedReferences = array_values($protectedReferences);
        $priority = [
            'product_shard' => 0,
            'offer_shard' => 1,
            'offer_shard_sku' => 2,
            'sku_registry_ref_count' => 3,
            'dependent_offer_identity' => 4,
            'dependent_sku_registry' => 5,
        ];
        usort($protectedReferences, static fn(array $left, array $right): int => [
            $priority[(string)($left['reference_type'] ?? '')] ?? 99,
            (string)($left['uuid'] ?? ''),
            (int)($left['website_id'] ?? -1),
            (string)($left['sku'] ?? ''),
        ] <=> [
            $priority[(string)($right['reference_type'] ?? '')] ?? 99,
            (string)($right['uuid'] ?? ''),
            (int)($right['website_id'] ?? -1),
            (string)($right['sku'] ?? ''),
        ]);

        $skuRegistryIds = array_map('intval', array_keys($skuRegistryIds));
        sort($skuRegistryIds, SORT_NUMERIC);
        sort($productDeleteUuids, SORT_STRING);
        sort($offerDeleteUuids, SORT_STRING);

        return [
            'preview' => [
                'product_registry' => count($productDeleteUuids),
                'offer_registry' => count($offerDeleteUuids),
                'sku_registry' => count($skuRegistryIds),
                'protected_references' => $protectedReferences,
            ],
            'product_uuids' => $productDeleteUuids,
            'offer_uuids' => $offerDeleteUuids,
            'sku_registry_ids' => $skuRegistryIds,
        ];
    }

    /** @param list<string> $productUuids @param list<string> $offerUuids @return list<array<string,mixed>> */
    private function candidateSkuRows(array $productUuids, array $offerUuids): array
    {
        $rows = [];
        foreach ([
            [SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID, $productUuids],
            [SkuRegistry::schema_fields_GLOBAL_OFFER_UUID, $offerUuids],
        ] as [$field, $values]) {
            if ($values === []) {
                continue;
            }
            foreach ($this->selectRows(
                $this->newSkuRegistry(),
                $field,
                $values,
                [
                    SkuRegistry::schema_fields_ID,
                    SkuRegistry::schema_fields_SKU,
                    SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID,
                    SkuRegistry::schema_fields_GLOBAL_OFFER_UUID,
                    SkuRegistry::schema_fields_REF_COUNT,
                ],
            ) as $row) {
                $id = (int)($row[SkuRegistry::schema_fields_ID] ?? 0);
                if ($id > 0) {
                    $rows[$id] = $row;
                }
            }
        }
        ksort($rows, SORT_NUMERIC);
        return array_values($rows);
    }

    /** @return list<int> */
    private function registeredWebsiteIds(): array
    {
        $rows = $this->newShardRegistry()
            ->clear()
            ->fields([
                ProductShardRegistry::schema_fields_WEBSITE_ID,
                ProductShardRegistry::schema_fields_SHARD_KEY,
            ])
            ->order(ProductShardRegistry::schema_fields_WEBSITE_ID)
            ->select()
            ->fetchArray();
        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $websiteId = (int)($row[ProductShardRegistry::schema_fields_WEBSITE_ID] ?? -1);
            $parsed = ProductShardKey::parse(
                (string)($row[ProductShardRegistry::schema_fields_SHARD_KEY] ?? ''),
            );
            if ($websiteId !== $parsed) {
                throw new \RuntimeException('hanfu_cleanup_shard_registry_mismatch');
            }
            $ids[$websiteId] = true;
        }
        if ($ids === []) {
            $ids[0] = true;
        }
        $ids = array_map('intval', array_keys($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @param ProductIdentityRegistry|OfferIdentityRegistry|SkuRegistry $model
     * @param list<string> $values
     * @param list<string> $fields
     * @return list<array<string,mixed>>
     */
    private function selectRows(object $model, string $whereField, array $values, array $fields): array
    {
        if ($values === []) {
            return [];
        }
        $rows = $model->clear()
            ->fields($fields)
            ->where($whereField, $values, 'IN')
            ->select()
            ->fetchArray();
        return is_array($rows) ? array_values($rows) : [];
    }

    /** @param list<mixed> $values @return list<string> */
    private function normalizeUuids(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                continue;
            }
            $uuid = trim((string)$value);
            if ($uuid !== '') {
                $normalized[$uuid] = true;
            }
        }
        $normalized = array_keys($normalized);
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @param array<string,array<string,mixed>> $references @param array<string,mixed> $reference */
    private function addProtectedReference(array &$references, array $reference): void
    {
        $key = implode('|', [
            (string)($reference['reference_type'] ?? ''),
            (string)($reference['uuid'] ?? ''),
            (string)($reference['website_id'] ?? ''),
            (string)($reference['offer_uuid'] ?? ''),
            (string)($reference['sku'] ?? ''),
        ]);
        $references[$key] = $reference;
    }

    /** @return array{product_registry:int,offer_registry:int,sku_registry:int} */
    private function emptyCounts(): array
    {
        return ['product_registry' => 0, 'offer_registry' => 0, 'sku_registry' => 0];
    }

    /** @return array{product_registry:int,offer_registry:int,sku_registry:int,protected_references:list<array<string,mixed>>} */
    private function emptyPreview(): array
    {
        return $this->emptyCounts() + ['protected_references' => []];
    }

    private function newProductRegistry(): ProductIdentityRegistry
    {
        $model = $this->productRegistryFactory !== null
            ? ($this->productRegistryFactory)()
            : ObjectManager::make(ProductIdentityRegistry::class);
        if (!$model instanceof ProductIdentityRegistry) {
            throw new \RuntimeException('hanfu_cleanup_product_registry_factory_invalid');
        }
        return $model;
    }

    private function newOfferRegistry(): OfferIdentityRegistry
    {
        $model = $this->offerRegistryFactory !== null
            ? ($this->offerRegistryFactory)()
            : ObjectManager::make(OfferIdentityRegistry::class);
        if (!$model instanceof OfferIdentityRegistry) {
            throw new \RuntimeException('hanfu_cleanup_offer_registry_factory_invalid');
        }
        return $model;
    }

    private function newSkuRegistry(): SkuRegistry
    {
        $model = $this->skuRegistryFactory !== null
            ? ($this->skuRegistryFactory)()
            : ObjectManager::make(SkuRegistry::class);
        if (!$model instanceof SkuRegistry) {
            throw new \RuntimeException('hanfu_cleanup_sku_registry_factory_invalid');
        }
        return $model;
    }

    private function newShardRegistry(): ProductShardRegistry
    {
        $model = $this->shardRegistryFactory !== null
            ? ($this->shardRegistryFactory)()
            : ObjectManager::make(ProductShardRegistry::class);
        if (!$model instanceof ProductShardRegistry) {
            throw new \RuntimeException('hanfu_cleanup_shard_registry_factory_invalid');
        }
        return $model;
    }

    private function newShardProduct(): Product
    {
        $model = $this->shardProductFactory !== null
            ? ($this->shardProductFactory)()
            : ObjectManager::make(Product::class);
        if (!$model instanceof Product) {
            throw new \RuntimeException('hanfu_cleanup_shard_product_factory_invalid');
        }
        return $model;
    }

    private function newShardOffer(): Offer
    {
        $model = $this->shardOfferFactory !== null
            ? ($this->shardOfferFactory)()
            : ObjectManager::make(Offer::class);
        if (!$model instanceof Offer) {
            throw new \RuntimeException('hanfu_cleanup_shard_offer_factory_invalid');
        }
        return $model;
    }
}
