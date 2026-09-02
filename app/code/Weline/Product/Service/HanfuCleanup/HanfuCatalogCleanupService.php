<?php

declare(strict_types=1);

namespace Weline\Product\Service\HanfuCleanup;

use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Service\FileAssetReferenceIndexer;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\InventoryCatalogMaintenanceInterface;
use Weline\Product\Model\Shard\Brand;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\Shard\ProductSupplier;
use Weline\Product\Model\Shard\StoreOffer;
use Weline\Product\Model\Shard\StoreProduct;
use Weline\Product\Model\Shard\Supplier;
use Weline\Product\Model\Shard\SupplierBrand;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\BrandRepository;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\ProductSupplierRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Product\Repository\SupplierRepository;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Guarded three-phase cleanup for the frozen Website 0 Hanfu fixture catalog.
 */
final class HanfuCatalogCleanupService
{
    private const SELECTION_CONTRACT = 'hanfu.cleanup.selection.v1';
    private const QUARANTINE_CONTRACT = 'hanfu.cleanup.quarantine.v1';
    private const REPORT_CONTRACT = 'hanfu.cleanup.report.v1';
    private const VERIFICATION_CONTRACT = 'hanfu.cleanup.verification.v1';

    /** @var list<string> */
    private const DELETION_ORDER = [
        'prices',
        'store_offers',
        'inventory',
        'offer_attributes',
        'offers',
        'category_links',
        'store_products',
        'media',
        'product_attributes',
        'products',
        'identities',
    ];

    /** @var array<string,string> */
    private const ARTIFACT_CONTRACTS = [
        'cleanup-selection.json' => self::SELECTION_CONTRACT,
        'quarantine-manifest.json' => self::QUARANTINE_CONTRACT,
        'cleanup-report.json' => self::REPORT_CONTRACT,
        'verification.json' => self::VERIFICATION_CONTRACT,
    ];

    /** @var (\Closure(int,list<int>):array<string,mixed>)|null */
    private readonly mixed $snapshotLoader;

    /** @var (\Closure(string,int,array<string,mixed>):array<string,mixed>)|null */
    private readonly mixed $deletionStep;

    /** @var (\Closure(int,array<string,mixed>):array<string,mixed>)|null */
    private readonly mixed $postconditionLoader;

    /** @var (\Closure(int,array<string,mixed>):array<string,mixed>)|null */
    private readonly mixed $cacheInvalidator;

    /** @var (\Closure(int,array<string,mixed>):array<string,mixed>)|null */
    private readonly mixed $searchInvalidator;

    private ?HanfuIdentityCleanupService $identityCleanup;
    private ?StorefrontCatalogCacheCoordinator $catalogCache;
    private readonly string $artifactRoot;

    /**
     * The callable arguments are narrow test seams. Production execution uses
     * the registered repositories and services when they are omitted.
     *
     * @param (callable(int,list<int>):array<string,mixed>)|null $snapshotLoader
     * @param (callable(string,int,array<string,mixed>):array<string,mixed>)|null $deletionStep
     * @param (callable(int,array<string,mixed>):array<string,mixed>)|null $postconditionLoader
     * @param (callable(int,array<string,mixed>):array<string,mixed>)|null $cacheInvalidator
     * @param (callable(int,array<string,mixed>):array<string,mixed>)|null $searchInvalidator
     */
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DatabaseTransactionRunnerInterface $transactions,
        private readonly InventoryCatalogMaintenanceInterface $inventory,
        private readonly HanfuTestCatalogSelection $selection,
        private readonly HanfuCatalogMediaQuarantine $mediaQuarantine,
        ?HanfuIdentityCleanupService $identityCleanup = null,
        ?StorefrontCatalogCacheCoordinator $catalogCache = null,
        ?callable $snapshotLoader = null,
        ?callable $deletionStep = null,
        ?callable $postconditionLoader = null,
        ?callable $cacheInvalidator = null,
        ?callable $searchInvalidator = null,
        ?string $artifactRoot = null,
    ) {
        $this->identityCleanup = $identityCleanup;
        $this->catalogCache = $catalogCache;
        $this->snapshotLoader = $snapshotLoader;
        $this->deletionStep = $deletionStep;
        $this->postconditionLoader = $postconditionLoader;
        $this->cacheInvalidator = $cacheInvalidator;
        $this->searchInvalidator = $searchInvalidator;
        $root = rtrim(trim($artifactRoot ?? dirname(__DIR__, 6) . '/var/hanfu-1688'), '/');
        if ($root === '' || !str_starts_with($root, '/') || str_contains($root, "\0")) {
            throw new \InvalidArgumentException('hanfu_cleanup_artifact_root_invalid');
        }
        $this->artifactRoot = $root;
    }

    /** @return array<string,mixed> */
    public function preview(int $websiteId, string $runId): array
    {
        $this->assertTargetWebsite($websiteId);
        $runId = $this->normalizeRunId($runId);
        $snapshot = $this->buildSnapshot($websiteId, $runId);
        $snapshot['selection_digest'] = $this->selection->digest($snapshot);
        $this->writeRunArtifact(
            $runId,
            'quarantine-manifest.json',
            (array)$snapshot['quarantine_manifest'],
        );
        $this->writeRunArtifact($runId, 'cleanup-selection.json', $snapshot);
        return $snapshot;
    }

    /** @return array<string,mixed> */
    public function apply(int $websiteId, string $runId, string $selectionDigest): array
    {
        $this->assertTargetWebsite($websiteId);
        $runId = $this->normalizeRunId($runId);
        $selectionDigest = $this->normalizeDigest($selectionDigest);
        $stored = $this->readRunArtifact($runId, 'cleanup-selection.json');
        if (!hash_equals((string)($stored['selection_digest'] ?? ''), $selectionDigest)) {
            throw new \RuntimeException('hanfu_cleanup_digest_mismatch');
        }

        $fresh = $this->buildSnapshot($websiteId, $runId);
        $freshDigest = $this->selection->digest($fresh);
        if (!hash_equals($selectionDigest, $freshDigest)) {
            throw new \RuntimeException('hanfu_cleanup_selection_drift');
        }
        if (($fresh['protected_references'] ?? []) !== []) {
            throw new \RuntimeException('hanfu_cleanup_protected_references');
        }

        /** @var array<string,mixed> $planned */
        $planned = $fresh['quarantine_manifest'];
        $quarantined = $this->mediaQuarantine->quarantine($planned);
        $quarantined['contract'] = self::QUARANTINE_CONTRACT;
        $this->writeRunArtifact($runId, 'quarantine-manifest.json', $quarantined);

        try {
            $deleted = $this->transactions->run(
                $this->connectionFactory,
                fn(): array => $this->deleteDependenciesInDeclaredOrder($websiteId, $fresh),
            );
        } catch (\Throwable $exception) {
            try {
                $restored = $this->mediaQuarantine->restore($quarantined);
                $restored['contract'] = self::QUARANTINE_CONTRACT;
                $this->writeRunArtifact($runId, 'quarantine-manifest.json', $restored);
            } catch (\Throwable $restoreException) {
                throw new \RuntimeException(
                    'hanfu_cleanup_media_rollback_failed',
                    0,
                    $exception,
                );
            }
            throw $exception;
        }

        $context = [
            'run_id' => $runId,
            'selection_digest' => $selectionDigest,
            'product_ids' => $fresh['product_ids'],
        ];
        $cache = $this->invalidateCatalogCache($websiteId, $context);
        $search = $this->invalidateSearch($websiteId, $context);
        $report = [
            'contract' => self::REPORT_CONTRACT,
            'status' => 'database_clean_files_quarantined',
            'run_id' => $runId,
            'website_id' => $websiteId,
            'selection_digest' => $selectionDigest,
            'deleted' => $deleted,
            'quarantine' => [
                'status' => (string)($quarantined['status'] ?? ''),
                'planned' => count($quarantined['moves'] ?? []),
                'completed' => count($quarantined['completed'] ?? []),
                'preserved' => count($quarantined['preserved'] ?? []),
            ],
            'cache' => $cache,
            'search' => $search,
        ];
        $this->writeRunArtifact($runId, 'cleanup-report.json', $report);
        return $report;
    }

    /** @return array<string,mixed> */
    public function verify(int $websiteId, string $runId, string $selectionDigest): array
    {
        $this->assertTargetWebsite($websiteId);
        $runId = $this->normalizeRunId($runId);
        $selectionDigest = $this->normalizeDigest($selectionDigest);
        $selection = $this->readRunArtifact($runId, 'cleanup-selection.json');
        if (!hash_equals((string)($selection['selection_digest'] ?? ''), $selectionDigest)) {
            throw new \RuntimeException('hanfu_cleanup_digest_mismatch');
        }

        $postconditions = $this->postconditions($websiteId, $selection);
        if (!$postconditions['passed']) {
            throw new \RuntimeException('hanfu_cleanup_postcondition_failed');
        }

        $manifest = $this->readRunArtifact($runId, 'quarantine-manifest.json');
        try {
            $files = $this->mediaQuarantine->finalize($manifest);
            $files['contract'] = self::QUARANTINE_CONTRACT;
            $this->writeRunArtifact($runId, 'quarantine-manifest.json', $files);
            $verification = [
                'contract' => self::VERIFICATION_CONTRACT,
                'status' => 'verified',
                'run_id' => $runId,
                'website_id' => $websiteId,
                'selection_digest' => $selectionDigest,
                'postconditions' => $postconditions,
                'files' => [
                    'status' => (string)($files['status'] ?? ''),
                    'finalized' => count($files['finalized'] ?? []),
                    'preserved' => count($files['preserved'] ?? []),
                ],
            ];
        } catch (\Throwable $exception) {
            $verification = [
                'contract' => self::VERIFICATION_CONTRACT,
                'status' => 'database_clean_files_pending',
                'run_id' => $runId,
                'website_id' => $websiteId,
                'selection_digest' => $selectionDigest,
                'postconditions' => $postconditions,
                'file_error' => $exception->getMessage(),
                'recoverable_manifest' => 'quarantine-manifest.json',
            ];
        }
        $this->writeRunArtifact($runId, 'verification.json', $verification);
        return $verification;
    }

    /** @return array<string,mixed> */
    private function buildSnapshot(int $websiteId, string $runId): array
    {
        $productIds = $this->selection->productIds();
        $raw = $this->snapshotLoader !== null
            ? ($this->snapshotLoader)($websiteId, $productIds)
            : $this->runtimeSnapshot($websiteId, $productIds);
        if (!is_array($raw)) {
            throw new \RuntimeException('hanfu_cleanup_snapshot_invalid');
        }

        $products = $this->normalizeRows($raw['products'] ?? [], Product::schema_fields_ID);
        $loadedIds = array_map(
            static fn(array $row): int => (int)($row[Product::schema_fields_ID] ?? 0),
            $products,
        );
        if ($loadedIds !== $productIds) {
            throw new \RuntimeException('hanfu_cleanup_selection_incomplete');
        }
        $offers = $this->normalizeRows($raw['offers'] ?? [], Offer::schema_fields_ID);
        $media = $this->normalizeRows($raw['media'] ?? [], Media::schema_fields_ID);
        $offerIds = $this->positiveIds(array_column($offers, Offer::schema_fields_ID));
        $mediaIds = $this->positiveIds(array_column($media, Media::schema_fields_ID));
        $productUuids = $this->nonEmptyStrings(array_column(
            $products,
            Product::schema_fields_GLOBAL_PRODUCT_UUID,
        ));
        $offerUuids = $this->nonEmptyStrings(array_column(
            $offers,
            Offer::schema_fields_GLOBAL_OFFER_UUID,
        ));

        $inventoryPreview = $this->inventory->previewCatalogPurge($websiteId, $offerIds);
        $identityPreview = $this->identityPreview($productUuids, $offerUuids);
        $protected = [];
        foreach ([
            $raw['protected_references'] ?? [],
            $inventoryPreview['protected_references'] ?? [],
        ] as $references) {
            if (!is_array($references)) {
                throw new \RuntimeException('hanfu_cleanup_protected_reference_invalid');
            }
            foreach ($references as $reference) {
                if (is_array($reference)) {
                    $protected[] = $reference;
                }
            }
        }
        $protected = $this->selection->canonicalize($protected);

        $referenceIndex = $raw['reference_index'] ?? [];
        if (!is_array($referenceIndex)) {
            throw new \RuntimeException('hanfu_cleanup_reference_index_invalid');
        }
        $quarantine = $this->mediaQuarantine->plan($runId, $media, $referenceIndex);
        $quarantine['contract'] = self::QUARANTINE_CONTRACT;

        $snapshot = $raw;
        unset($snapshot['selection_digest']);
        $snapshot['contract'] = self::SELECTION_CONTRACT;
        $snapshot['website_id'] = $websiteId;
        $snapshot['run_id'] = $runId;
        $snapshot['product_ids'] = $productIds;
        $snapshot['offer_ids'] = $offerIds;
        $snapshot['media_ids'] = $mediaIds;
        $snapshot['product_uuids'] = $productUuids;
        $snapshot['offer_uuids'] = $offerUuids;
        $snapshot['products'] = $products;
        $snapshot['offers'] = $offers;
        $snapshot['media'] = $media;
        $snapshot['preservation_snapshot'] = $this->normalizePreservation(
            $raw['preservation_snapshot'] ?? [],
        );
        $snapshot['inventory_preview'] = $inventoryPreview;
        $snapshot['identity_preview'] = $identityPreview;
        $snapshot['protected_references'] = $protected;
        $snapshot['quarantine_manifest'] = $quarantine;
        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function runtimeSnapshot(int $websiteId, array $productIds): array
    {
        $products = array_values(array_filter(
            $this->repository(ProductRepository::class)->listAll($websiteId),
            static fn(array $row): bool => in_array(
                (int)($row[Product::schema_fields_ID] ?? 0),
                $productIds,
                true,
            ),
        ));
        $offers = $this->repository(OfferRepository::class)
            ->listByProductIds($websiteId, $productIds);
        $offerIds = $this->positiveIds(array_column($offers, Offer::schema_fields_ID));
        $storeIds = $this->storeIds($websiteId);
        $mediaRepository = $this->repository(MediaRepository::class);
        $media = $mediaRepository->listByProductIds($websiteId, $productIds);

        $allProducts = $this->repository(ProductRepository::class)->listAll($websiteId);
        $allProductIds = $this->positiveIds(array_column($allProducts, Product::schema_fields_ID));
        $allMedia = $mediaRepository->listByProductIds($websiteId, $allProductIds);
        $assetMediaCounts = [];
        foreach ($allMedia as $row) {
            $assetId = strtolower(trim((string)($row[Media::schema_fields_ASSET_ID] ?? '')));
            if ($assetId !== '') {
                $assetMediaCounts[$assetId] = ($assetMediaCounts[$assetId] ?? 0) + 1;
            }
        }
        foreach ($media as &$row) {
            $row = $this->normalizeRuntimeMedia($row, $assetMediaCounts);
        }
        unset($row);

        $externalProductIds = array_values(array_diff($allProductIds, $productIds));
        $allOffers = $this->repository(OfferRepository::class)
            ->listByProductIds($websiteId, $externalProductIds);
        $allOfferIds = $this->positiveIds(array_column($allOffers, Offer::schema_fields_ID));
        $attributes = $this->repository(AttributeValueRepository::class);
        $referenceRows = array_merge(
            $attributes->listExplicitRows($websiteId, 'product', $externalProductIds, $storeIds),
            $attributes->listExplicitRows($websiteId, 'offer', $allOfferIds, $storeIds),
        );
        $entityRows = array_merge(
            $this->repository(CategoryRepository::class)->listAll($websiteId),
            $this->repository(BrandRepository::class)->listAll($websiteId),
            $this->repository(SupplierRepository::class)->listAll($websiteId),
        );
        $referenceIndex = [];
        foreach ($media as $row) {
            $blobKey = trim((string)($row[Media::schema_fields_BLOB_KEY] ?? ''));
            $objectKey = (string)($row['object_key'] ?? '');
            if ($blobKey === '') {
                continue;
            }
            $counts = $this->mediaReferenceCounts(
                $websiteId,
                $blobKey,
                $objectKey,
                $referenceRows,
                $entityRows,
                array_values(array_filter([
                    $objectKey,
                    trim((string)($row[Media::schema_fields_ASSET_ID] ?? '')) !== ''
                        ? 'asset://' . trim((string)$row[Media::schema_fields_ASSET_ID])
                        : '',
                    trim((string)($row[Media::schema_fields_ASSET_ID] ?? '')),
                ])),
            );
            if (trim((string)($row[Media::schema_fields_ASSET_ID] ?? '')) !== '') {
                $counts['media_count'] = (int)($row['asset_media_count'] ?? $counts['media_count']);
                $counts['file_asset_references'] = (int)($row['file_asset_references'] ?? 0);
            }
            $referenceIndex[$blobKey] = $counts;
        }

        $prices = $this->repository(PriceRepository::class)
            ->listExplicitRows($websiteId, $offerIds, $storeIds);
        $storeOffers = $this->directRows(
            StoreOffer::class,
            $websiteId,
            StoreOffer::schema_fields_OFFER_ID,
            $offerIds,
        );
        $offerAttributes = $attributes->listExplicitRows(
            $websiteId,
            'offer',
            $offerIds,
            $storeIds,
        );
        $categoryLinks = $this->repository(CategoryLinkRepository::class)
            ->listByProductIds($websiteId, $productIds, $storeIds);
        $storeProducts = $this->directRows(
            StoreProduct::class,
            $websiteId,
            StoreProduct::schema_fields_PRODUCT_ID,
            $productIds,
        );
        $productAttributes = $attributes->listExplicitRows(
            $websiteId,
            'product',
            $productIds,
            $storeIds,
        );
        $productSupplierRows = [];
        $productSuppliers = $this->repository(ProductSupplierRepository::class);
        foreach ($productIds as $productId) {
            array_push($productSupplierRows, ...$productSuppliers->listByProduct($websiteId, $productId));
        }

        return [
            'products' => $products,
            'offers' => $offers,
            'media' => $media,
            'reference_index' => $referenceIndex,
            'prices' => $prices,
            'store_offers' => $storeOffers,
            'offer_attributes' => $offerAttributes,
            'category_links' => $categoryLinks,
            'store_products' => $storeProducts,
            'product_attributes' => $productAttributes,
            'product_supplier_rows' => $productSupplierRows,
            'dependent_counts' => [
                'prices' => count($prices),
                'store_offers' => count($storeOffers),
                'offer_attributes' => count($offerAttributes),
                'category_links' => count($categoryLinks),
                'store_products' => count($storeProducts),
                'media' => count($media),
                'product_attributes' => count($productAttributes),
                'product_supplier_links' => count($productSupplierRows),
            ],
            'preservation_snapshot' => $this->runtimePreservationSnapshot($websiteId),
            'protected_references' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function deleteDependenciesInDeclaredOrder(int $websiteId, array $snapshot): array
    {
        $deleted = [];
        foreach (self::DELETION_ORDER as $step) {
            $deleted[$step] = $this->deletionStep !== null
                ? ($this->deletionStep)($step, $websiteId, $snapshot)
                : $this->purgeStep($step, $websiteId, $snapshot);
        }
        return $deleted;
    }

    /** @return array<string,mixed> */
    private function purgeStep(string $step, int $websiteId, array $snapshot): array
    {
        /** @var list<int> $productIds */
        $productIds = $snapshot['product_ids'];
        /** @var list<int> $offerIds */
        $offerIds = $snapshot['offer_ids'];
        return match ($step) {
            'prices' => ['deleted' => $this->repository(PriceRepository::class)
                ->purgeOfferIds($websiteId, $offerIds)],
            'store_offers' => ['deleted' => $this->repository(StoreOfferRepository::class)
                ->purgeOfferIds($websiteId, $offerIds)],
            'inventory' => $this->inventory->purgeCatalogOffers($websiteId, $offerIds),
            'offer_attributes' => ['deleted' => $this->purgeAttributes(
                $websiteId,
                'offer',
                $offerIds,
                (int)($snapshot['dependent_counts']['offer_attributes'] ?? 0),
            )],
            'offers' => $this->repository(OfferRepository::class)
                ->deleteByProductIds($websiteId, $productIds),
            'category_links' => ['deleted' => $this->repository(CategoryLinkRepository::class)
                ->purgeProductIds($websiteId, $productIds)],
            'store_products' => ['deleted' => $this->repository(StoreProductRepository::class)
                ->purgeProductIds($websiteId, $productIds)],
            'media' => ['deleted' => $this->purgeMedia($websiteId, $snapshot['media_ids'])],
            'product_attributes' => [
                'deleted' => $this->purgeAttributes(
                    $websiteId,
                    'product',
                    $productIds,
                    (int)($snapshot['dependent_counts']['product_attributes'] ?? 0),
                ),
                'product_supplier_links' => $this->purgeProductSupplierLinks(
                    $websiteId,
                    $productIds,
                ),
            ],
            'products' => ['deleted' => $this->repository(ProductRepository::class)
                ->deleteByIds($websiteId, $productIds)],
            'identities' => $this->identityService()->purgeUnreferenced(
                $snapshot['product_uuids'],
                $snapshot['offer_uuids'],
            ),
            default => throw new \LogicException('hanfu_cleanup_deletion_step_invalid'),
        };
    }

    private function purgeAttributes(
        int $websiteId,
        string $entityType,
        array $entityIds,
        int $expectedRows,
    ): int {
        $repository = $this->repository(AttributeValueRepository::class);
        foreach ($entityIds as $entityId) {
            $repository->purgeEntity($websiteId, $entityType, (int)$entityId);
        }
        return max(0, $expectedRows);
    }

    private function purgeMedia(int $websiteId, array $mediaIds): int
    {
        $repository = $this->repository(MediaRepository::class);
        $count = 0;
        foreach ($this->positiveIds($mediaIds) as $mediaId) {
            $repository->remove($websiteId, $mediaId);
            $count++;
        }
        return $count;
    }

    private function purgeProductSupplierLinks(int $websiteId, array $productIds): int
    {
        $productIds = $this->positiveIds($productIds);
        if ($productIds === []) {
            return 0;
        }
        $rows = $this->directRows(
            ProductSupplier::class,
            $websiteId,
            ProductSupplier::schema_fields_PRODUCT_ID,
            $productIds,
        );
        if ($rows === []) {
            return 0;
        }
        $model = ObjectManager::create(ProductSupplier::class, [], false)->forWebsite($websiteId);
        $model->clear()
            ->where(ProductSupplier::schema_fields_PRODUCT_ID, $productIds, 'IN')
            ->delete()
            ->fetch();
        return count($rows);
    }

    /** @return array<string,mixed> */
    private function postconditions(int $websiteId, array $selection): array
    {
        $live = $this->postconditionLoader !== null
            ? ($this->postconditionLoader)($websiteId, $selection)
            : $this->runtimePostconditionSnapshot($websiteId, $selection);
        if (!is_array($live)) {
            throw new \RuntimeException('hanfu_cleanup_postcondition_invalid');
        }

        $preservation = $this->normalizePreservation($live['preservation_snapshot'] ?? []);
        $expectedPreservation = $this->normalizePreservation(
            $selection['preservation_snapshot'] ?? [],
        );
        $preservationUnchanged = hash_equals(
            $this->selection->digest($expectedPreservation),
            $this->selection->digest($preservation),
        );
        $remaining = [];
        foreach ($live as $name => $value) {
            if ($name === 'preservation_snapshot') {
                continue;
            }
            if ((is_array($value) && $value !== [])
                || (is_int($value) && $value !== 0)
                || (is_string($value) && trim($value) !== '' && $value !== '0')
            ) {
                $remaining[$name] = $value;
            }
        }
        return [
            'passed' => $remaining === [] && $preservationUnchanged,
            'remaining' => $remaining,
            'preservation_unchanged' => $preservationUnchanged,
            'expected_preservation' => $expectedPreservation,
            'actual_preservation' => $preservation,
        ];
    }

    /** @return array<string,mixed> */
    private function runtimePostconditionSnapshot(int $websiteId, array $selection): array
    {
        $productIds = $this->positiveIds($selection['product_ids'] ?? []);
        $offerIds = $this->positiveIds($selection['offer_ids'] ?? []);
        $mediaIds = $this->positiveIds($selection['media_ids'] ?? []);
        $storeIds = $this->storeIds($websiteId);
        $products = array_values(array_filter(
            $this->repository(ProductRepository::class)->listAll($websiteId),
            static fn(array $row): bool => in_array(
                (int)($row[Product::schema_fields_ID] ?? 0),
                $productIds,
                true,
            ),
        ));
        $offers = $this->repository(OfferRepository::class)
            ->listByProductIds($websiteId, $productIds);
        $media = $this->repository(MediaRepository::class)
            ->listByProductIds($websiteId, $productIds);
        $attributes = $this->repository(AttributeValueRepository::class);
        $inventory = $this->inventory->previewCatalogPurge($websiteId, $offerIds);

        return [
            'remaining_product_ids' => $this->positiveIds(array_column($products, Product::schema_fields_ID)),
            'remaining_offer_ids' => $this->positiveIds(array_column($offers, Offer::schema_fields_ID)),
            'remaining_media_ids' => array_values(array_intersect(
                $mediaIds,
                $this->positiveIds(array_column($media, Media::schema_fields_ID)),
            )),
            'remaining_prices' => count($this->repository(PriceRepository::class)
                ->listExplicitRows($websiteId, $offerIds, $storeIds)),
            'remaining_store_offers' => count($this->directRows(
                StoreOffer::class,
                $websiteId,
                StoreOffer::schema_fields_OFFER_ID,
                $offerIds,
            )),
            'remaining_offer_attributes' => count($attributes->listExplicitRows(
                $websiteId,
                'offer',
                $offerIds,
                $storeIds,
            )),
            'remaining_category_links' => count($this->repository(CategoryLinkRepository::class)
                ->listByProductIds($websiteId, $productIds, $storeIds)),
            'remaining_store_products' => count($this->directRows(
                StoreProduct::class,
                $websiteId,
                StoreProduct::schema_fields_PRODUCT_ID,
                $productIds,
            )),
            'remaining_product_attributes' => count($attributes->listExplicitRows(
                $websiteId,
                'product',
                $productIds,
                $storeIds,
            )),
            'remaining_product_supplier_links' => count($this->directRows(
                ProductSupplier::class,
                $websiteId,
                ProductSupplier::schema_fields_PRODUCT_ID,
                $productIds,
            )),
            'inventory_stock_items' => (int)($inventory['stock_items'] ?? 0),
            'inventory_reservations' => (int)($inventory['reservations'] ?? 0),
            'preservation_snapshot' => $this->runtimePreservationSnapshot($websiteId),
        ];
    }

    /** @return array<string,list<int|string>> */
    private function runtimePreservationSnapshot(int $websiteId): array
    {
        $categories = $this->repository(CategoryRepository::class)->listAll($websiteId);
        $brands = $this->repository(BrandRepository::class)->listAll($websiteId);
        $suppliers = $this->repository(SupplierRepository::class)->listAll($websiteId);
        $supplierBrandRows = $this->directRows(
            SupplierBrand::class,
            $websiteId,
            SupplierBrand::schema_fields_ID,
            null,
        );
        return $this->normalizePreservation([
            'category_ids' => array_column($categories, Category::schema_fields_ID),
            'brand_ids' => array_column($brands, Brand::schema_fields_ID),
            'supplier_ids' => array_column($suppliers, Supplier::schema_fields_ID),
            'supplier_brand_ids' => array_column($supplierBrandRows, SupplierBrand::schema_fields_ID),
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int> $assetMediaCounts
     * @return array<string,mixed>
     */
    private function normalizeRuntimeMedia(array $row, array $assetMediaCounts): array
    {
        $path = trim((string)($row[Media::schema_fields_PATH] ?? ''));
        $assetId = strtolower(trim((string)($row[Media::schema_fields_ASSET_ID] ?? '')));
        $row['disk_code'] = StorageDiskCode::BUILTIN_LOCAL_MEDIA;
        $row['object_key'] = $this->mediaObjectKey($path);
        $row['media_storage_kind'] = 'legacy';
        $row['asset_media_count'] = 0;
        $row['file_asset_references'] = 0;

        if ($assetId !== '') {
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $assetId) !== 1) {
                throw new \RuntimeException('hanfu_cleanup_media_asset_id_invalid');
            }
            $row['asset_media_count'] = (int)($assetMediaCounts[$assetId] ?? 0);
            try {
                $asset = $this->repository(FileAssetManagerInterface::class)->get($assetId);
            } catch (\RuntimeException) {
                $row['media_storage_kind'] = 'missing';
                $row['object_key'] = 'missing-assets/' . $assetId;
                return $row;
            }
            $diskCode = trim($asset->getDiskCode());
            $objectKey = trim($asset->getObjectKey());
            if ($diskCode === '' || $objectKey === '') {
                throw new \RuntimeException('hanfu_cleanup_media_asset_descriptor_invalid');
            }
            $row['disk_code'] = $diskCode;
            $row['object_key'] = $objectKey;
            $row['asset_sha256'] = strtolower(trim((string)$asset->getData(FileAsset::schema_fields_SHA256)));
            $row['asset_revision'] = (int)$asset->getData(FileAsset::schema_fields_ASSET_REVISION);
            if ($asset->isDeleted()) {
                $row['media_storage_kind'] = 'missing';
                return $row;
            }
            $row['media_storage_kind'] = 'managed';
            $row['file_asset_references'] = $this->repository(FileAssetReferenceIndexer::class)
                ->isReferenced($assetId) ? 1 : 0;
            return $row;
        }

        $scheme = strtolower((string)parse_url($path, PHP_URL_SCHEME));
        if (filter_var($path, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true)
        ) {
            $row['object_key'] = $path;
            $row['media_storage_kind'] = 'external';
        }
        return $row;
    }

    /** @return array<string,int> */
    private function mediaReferenceCounts(
        int $websiteId,
        string $blobKey,
        string $objectKey,
        array $attributeRows,
        array $preservedEntityRows,
        array $needles = [],
    ): array {
        $needles[] = $objectKey;
        $needles = array_values(array_unique(array_filter(array_map(
            static fn(mixed $needle): string => trim((string)$needle),
            $needles,
        ))));
        $counts = [
            'media_count' => $this->repository(MediaRepository::class)
                ->countByBlobKey($websiteId, $blobKey),
            'homepage_references' => str_starts_with($objectKey, 'catalog/hanfu/r2/homepage/') ? 1 : 0,
            'content_references' => 0,
            'config_references' => 0,
            'preserved_entity_references' => 0,
        ];
        foreach ($attributeRows as $row) {
            $encoded = json_encode($row['value'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded) || !$this->containsAny($encoded, $needles)) {
                continue;
            }
            $code = strtolower((string)($row['attribute_code'] ?? ''));
            if (str_contains($code, 'homepage') || str_contains($code, 'hero') || str_contains($code, 'banner')) {
                $counts['homepage_references']++;
            } elseif (str_contains($code, 'config') || str_contains($code, 'matrix')) {
                $counts['config_references']++;
            } else {
                $counts['content_references']++;
            }
        }
        foreach ($preservedEntityRows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded) && $this->containsAny($encoded, $needles)) {
                $counts['preserved_entity_references']++;
            }
        }
        return $counts;
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function identityPreview(array $productUuids, array $offerUuids): array
    {
        if ($this->identityCleanup === null && $this->snapshotLoader !== null) {
            return [
                'product_registry' => 0,
                'offer_registry' => 0,
                'sku_registry' => 0,
                'protected_references' => [],
            ];
        }
        return $this->identityService()->preview($productUuids, $offerUuids);
    }

    private function identityService(): HanfuIdentityCleanupService
    {
        return $this->identityCleanup ??=
            ObjectManager::getInstance(HanfuIdentityCleanupService::class);
    }

    /** @return array<string,mixed> */
    private function invalidateCatalogCache(int $websiteId, array $context): array
    {
        if ($this->cacheInvalidator !== null) {
            return ($this->cacheInvalidator)($websiteId, $context);
        }
        $this->catalogCache ??= ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);
        $this->catalogCache->notifyCatalogChanged(
            $websiteId,
            'hanfu_test_catalog_cleanup',
            $context,
        );
        return ['status' => 'invalidated'];
    }

    /** @return array<string,mixed> */
    private function invalidateSearch(int $websiteId, array $context): array
    {
        if ($this->searchInvalidator !== null) {
            return ($this->searchInvalidator)($websiteId, $context);
        }
        $class = 'Weline\\Search\\Service\\SearchIndexBuilder';
        if (!class_exists($class)) {
            return ['status' => 'not_installed'];
        }
        $builder = ObjectManager::getInstance($class);
        /** @var array<string,mixed> $result */
        $result = $builder->rebuildWebsite($websiteId);
        return ['status' => 'rebuilt', 'result' => $result];
    }

    /** @return list<int> */
    private function storeIds(int $websiteId): array
    {
        $ids = [0];
        $stores = ObjectManager::getInstance(StoreCatalogInterface::class)->byWebsite($websiteId);
        foreach ($stores as $store) {
            $ids[] = $store->id;
        }
        return $this->nonNegativeIds($ids);
    }

    /** @return list<array<string,mixed>> */
    private function directRows(
        string $modelClass,
        int $websiteId,
        string $field,
        ?array $ids,
    ): array {
        $model = ObjectManager::create($modelClass, [], false)->forWebsite($websiteId);
        $query = $model->clear();
        if ($ids !== null) {
            $ids = $this->positiveIds($ids);
            if ($ids === []) {
                return [];
            }
            $query->where($field, $ids, 'IN');
        }
        $rows = $query->select()->fetchArray();
        return is_array($rows) ? array_values($rows) : [];
    }

    private function mediaObjectKey(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        foreach (['/pub/media/', 'pub/media/', '/media/', 'media/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }
        return ltrim($path, '/');
    }

    /** @return list<array<string,mixed>> */
    private function normalizeRows(mixed $rows, string $idField): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException('hanfu_cleanup_snapshot_rows_invalid');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('hanfu_cleanup_snapshot_rows_invalid');
            }
        }
        usort(
            $rows,
            static fn(array $left, array $right): int =>
                (int)($left[$idField] ?? 0) <=> (int)($right[$idField] ?? 0),
        );
        return $rows;
    }

    /** @return array<string,list<int|string>> */
    private function normalizePreservation(mixed $snapshot): array
    {
        if (!is_array($snapshot)) {
            throw new \RuntimeException('hanfu_cleanup_preservation_invalid');
        }
        $normalized = [];
        foreach (['category_ids', 'brand_ids', 'supplier_ids', 'supplier_brand_ids'] as $key) {
            $values = $snapshot[$key] ?? [];
            if (!is_array($values)) {
                throw new \RuntimeException('hanfu_cleanup_preservation_invalid');
            }
            $values = array_values(array_unique(array_filter(
                $values,
                static fn(mixed $value): bool => (is_int($value) && $value > 0)
                    || (is_string($value) && trim($value) !== ''),
            ), SORT_REGULAR));
            usort($values, static fn(mixed $left, mixed $right): int =>
                (string)$left <=> (string)$right);
            $normalized[$key] = $values;
        }
        return $normalized;
    }

    /** @return list<int> */
    private function positiveIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0,
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return list<int> */
    private function nonNegativeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id >= 0,
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return list<string> */
    private function nonEmptyStrings(array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $values,
        ), static fn(string $value): bool => $value !== '')));
        sort($values, SORT_STRING);
        return $values;
    }

    private function repository(string $class): object
    {
        return ObjectManager::getInstance($class);
    }

    /** @return string absolute artifact path */
    private function writeRunArtifact(string $runId, string $name, array $document): string
    {
        $directory = $this->runDirectory($runId);
        $expectedContract = self::ARTIFACT_CONTRACTS[$name] ?? null;
        if ($expectedContract === null
            || !hash_equals($expectedContract, (string)($document['contract'] ?? ''))
        ) {
            throw new \InvalidArgumentException('hanfu_cleanup_artifact_contract_invalid');
        }
        $this->ensureArtifactDirectory($directory);
        $path = $directory . '/' . $name;
        if (is_link($path)) {
            throw new \RuntimeException('hanfu_cleanup_artifact_symlink_rejected');
        }
        $json = json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) . "\n";
        $temporary = $directory . '/.' . $name . '.' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('hanfu_cleanup_artifact_open_failed');
        }
        try {
            if (!chmod($temporary, 0600)) {
                throw new \RuntimeException('hanfu_cleanup_artifact_permission_failed');
            }
            $offset = 0;
            $length = strlen($json);
            while ($offset < $length) {
                $written = fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('hanfu_cleanup_artifact_write_failed');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new \RuntimeException('hanfu_cleanup_artifact_flush_failed');
            }
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($temporary);
            throw $exception;
        }
        fclose($handle);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('hanfu_cleanup_artifact_rename_failed');
        }
        chmod($path, 0600);
        return $path;
    }

    /** @return array<string,mixed> */
    private function readRunArtifact(string $runId, string $name): array
    {
        $directory = $this->runDirectory($runId);
        $expectedContract = self::ARTIFACT_CONTRACTS[$name] ?? null;
        if ($expectedContract === null || is_link($directory)) {
            throw new \InvalidArgumentException('hanfu_cleanup_artifact_name_invalid');
        }
        $path = $directory . '/' . $name;
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('hanfu_cleanup_artifact_missing');
        }
        $realDirectory = realpath($directory);
        $realPath = realpath($path);
        if ($realDirectory === false
            || $realPath === false
            || !str_starts_with($realPath, $realDirectory . '/')
        ) {
            throw new \RuntimeException('hanfu_cleanup_artifact_path_invalid');
        }
        $raw = file_get_contents($realPath);
        if (!is_string($raw)) {
            throw new \RuntimeException('hanfu_cleanup_artifact_read_failed');
        }
        $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($document)
            || !hash_equals($expectedContract, (string)($document['contract'] ?? ''))
        ) {
            throw new \RuntimeException('hanfu_cleanup_artifact_contract_invalid');
        }
        return $document;
    }

    private function ensureArtifactDirectory(string $directory): void
    {
        if (is_link($this->artifactRoot) || is_link($directory)) {
            throw new \RuntimeException('hanfu_cleanup_artifact_symlink_rejected');
        }
        if (!is_dir($this->artifactRoot)
            && !mkdir($this->artifactRoot, 0700, true)
            && !is_dir($this->artifactRoot)
        ) {
            throw new \RuntimeException('hanfu_cleanup_artifact_directory_failed');
        }
        chmod($this->artifactRoot, 0700);
        if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
            throw new \RuntimeException('hanfu_cleanup_artifact_directory_failed');
        }
        chmod($directory, 0700);
    }

    private function runDirectory(string $runId): string
    {
        return $this->artifactRoot . '/' . $this->normalizeRunId($runId);
    }

    private function assertTargetWebsite(int $websiteId): void
    {
        if ($websiteId !== 0) {
            throw new \InvalidArgumentException('hanfu_cleanup_website_invalid');
        }
    }

    private function normalizeRunId(string $runId): string
    {
        $runId = trim($runId);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $runId) !== 1) {
            throw new \InvalidArgumentException('hanfu_cleanup_run_id_invalid');
        }
        return $runId;
    }

    private function normalizeDigest(string $digest): string
    {
        $digest = strtolower(trim($digest));
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \InvalidArgumentException('hanfu_cleanup_digest_invalid');
        }
        return $digest;
    }
}
