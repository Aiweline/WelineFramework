<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Throwable;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\InventoryCatalogCopyCapability;
use Weline\Inventory\Api\InventoryCatalogCopyCapabilityInterface;
use Weline\Product\Api\Data\CopyCommitResult;
use Weline\Product\Api\Data\CopyDraft;
use Weline\Product\Api\Data\CopyPreview;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductCopyOperationRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;

/**
 * Repository-backed catalog copy for ready Website shards.
 *
 * The adapter never provisions shards or runs DDL. A commit owns one target
 * transaction and stores its receipt in ProductCopyOperation before commit.
 */
final class ProductCopyDurableCatalogAdapter
{
    private ?InventoryCatalogCopyCapabilityInterface $resolvedInventory = null;
    private bool $inventoryResolved = false;

    /** @var (\Closure(string, int, int): bool)|null */
    private readonly ?\Closure $copyAuthorization;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DatabaseTransactionRunnerInterface $transactions,
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly CategoryLinkRepository $categoryLinks,
        private readonly AttributeValueRepository $attributes,
        private readonly ProductCategoryAttributeService $categoryAttributes,
        private readonly PriceRepository $prices,
        private readonly MediaRepository $media,
        private readonly StoreProductRepository $storeProducts,
        private readonly StoreOfferRepository $storeOffers,
        ?InventoryCatalogCopyCapabilityInterface $inventory = null,
        private readonly ?ProductGovernanceService $governance = null,
        ?callable $copyAuthorization = null,
    ) {
        $this->resolvedInventory = $inventory;
        $this->inventoryResolved = $inventory !== null;
        $this->copyAuthorization = $copyAuthorization === null
            ? null
            : \Closure::fromCallable($copyAuthorization);
    }

    public function preview(CopyDraft $draft): CopyPreview
    {
        $plan = $this->buildPlan($draft);
        $create = 0;
        $skip = 0;
        $update = 0;
        foreach ($plan['products'] as $row) {
            match ($row['action']) {
                'create' => $create++,
                'skip' => $skip++,
                'update' => $update++,
                default => null,
            };
        }
        $warnings = $plan['warnings'];
        if ($draft->entry !== CopyDraft::ENTRY_BLANK
            && in_array(CopyDraft::PKG_INVENTORY, $draft->fieldPackages, true)
            && $this->inventory() === null
        ) {
            $warnings[] = 'copy_inventory_capability_unavailable';
        }

        return new CopyPreview(
            draftId: $draft->draftId,
            categoryCount: count($plan['categories']),
            productCount: count($plan['products']),
            offerCount: count($plan['offers']),
            linkCount: count($plan['links']),
            createCount: $create,
            skipCount: $skip,
            updateCount: $update,
            inventoryWillCopyQty: in_array(
                CopyDraft::PKG_INVENTORY,
                $draft->fieldPackages,
                true,
            ) && $draft->inventoryCopyQty,
            items: $plan['products'],
            warnings: array_values(array_unique($warnings)),
            targetStoreIds: $draft->selectedTargetStoreIds(),
        );
    }

    public function commit(
        CopyDraft $draft,
        string $requestHash,
        ProductCopyOperationRepository $operations,
    ): CopyCommitResult {
        $inventory = $this->inventory();
        if ($draft->entry !== CopyDraft::ENTRY_BLANK
            && in_array(CopyDraft::PKG_INVENTORY, $draft->fieldPackages, true)
            && $inventory === null
        ) {
            return new CopyCommitResult(
                draftId: $draft->draftId,
                success: false,
                errorCode: 'copy_inventory_capability_unavailable',
                message: (string)__('库存复制能力不可用'),
            );
        }

        try {
            $this->buildPlan($draft);
        } catch (ProductV2ConflictException $exception) {
            return $this->failure($draft->draftId, $exception->errorCode);
        }

        try {
            $claim = $operations->claimCommit($draft->draftId, $requestHash);
        } catch (Throwable) {
            return $this->failure($draft->draftId, 'copy_commit_failed');
        }
        if (($claim['status'] ?? '') === 'replay' && isset($claim['result'])) {
            return $claim['result'];
        }
        if (($claim['status'] ?? '') !== 'claimed') {
            $errorCode = (string)($claim['error_code'] ?? 'copy_commit_failed');
            return $this->failure($draft->draftId, $errorCode);
        }

        $claimToken = (string)$claim['claim_token'];
        $execute = function () use (
            $draft,
            $requestHash,
            $claimToken,
            $operations,
            $inventory,
        ): CopyCommitResult {
            return $this->transactions->run(
                $this->connectionFactory,
                function () use (
                    $draft,
                    $requestHash,
                    $claimToken,
                    $operations,
                    $inventory,
                ): CopyCommitResult {
                    $plan = $this->buildPlan($draft);
                    $result = $this->applyPlan($draft, $requestHash, $plan, $inventory);
                    $operations->complete(
                        $draft->draftId,
                        $claimToken,
                        $requestHash,
                        $result,
                    );
                    return $result;
                },
            );
        };

        try {
            return $inventory !== null
                ? $inventory->transactional($execute)
                : $execute();
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof ProductV2ConflictException
                ? $exception->errorCode
                : 'copy_commit_failed';
            try {
                $operations->fail($draft->draftId, $claimToken, $errorCode);
            } catch (Throwable) {
            }
            return $this->failure($draft->draftId, $errorCode);
        }
    }

    /**
     * @return array{
     *   categories:array<int,array<string,mixed>>,
     *   products:list<array<string,mixed>>,
     *   offers:list<array<string,mixed>>,
     *   offers_by_product:array<int,list<array<string,mixed>>>,
     *   links:list<array{category_id:int,product_id:int}>,
     *   warnings:list<string>
     * }
     */
    private function buildPlan(CopyDraft $draft): array
    {
        // A read proves that the target shard is already provisioned and ready.
        $targetProducts = $this->products->listAll($draft->targetWebsiteId);
        if ($draft->entry === CopyDraft::ENTRY_BLANK) {
            return [
                'categories' => [],
                'products' => [],
                'offers' => [],
                'offers_by_product' => [],
                'links' => [],
                'warnings' => [],
            ];
        }

        $sourceWebsiteId = (int)$draft->sourceWebsiteId;
        $sourceCategories = $this->categories->listAll($sourceWebsiteId);
        $categoryBook = [];
        $children = [];
        foreach ($sourceCategories as $row) {
            $categoryId = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            $categoryBook[$categoryId] = $row;
            $parentId = isset($row[Category::schema_fields_PARENT_ID])
                ? (int)$row[Category::schema_fields_PARENT_ID]
                : 0;
            $children[$parentId][] = $categoryId;
        }

        $excluded = [];
        foreach ($draft->excludedCategoryIds as $categoryId) {
            foreach ($this->descendantsAndSelf((int)$categoryId, $categoryBook, $children) as $id) {
                $excluded[$id] = true;
            }
        }

        $selectedCategories = [];
        $warnings = [];
        foreach ($draft->categoryIds as $categoryId) {
            $categoryId = (int)$categoryId;
            if (!isset($categoryBook[$categoryId])) {
                $warnings[] = 'copy_category_not_found:' . $categoryId;
                continue;
            }
            foreach ($this->descendantsAndSelf($categoryId, $categoryBook, $children) as $id) {
                if (!isset($excluded[$id])) {
                    $selectedCategories[$id] = $categoryBook[$id];
                }
            }
        }
        $selectedCategories = $this->sortCategories($selectedCategories);

        $links = $selectedCategories === []
            ? []
            : $this->categoryLinks->listByCategoryIds(
                $sourceWebsiteId,
                array_keys($selectedCategories),
            );
        $sourceProducts = $this->products->listAll($sourceWebsiteId);
        $sourceProductBook = [];
        foreach ($sourceProducts as $row) {
            $productId = (int)($row[Product::schema_fields_ID] ?? 0);
            if ($productId > 0) {
                $sourceProductBook[$productId] = $row;
            }
        }

        $productIds = [];
        if ($draft->includeProducts) {
            if ($draft->categoryIds === []) {
                $productIds = array_fill_keys(array_keys($sourceProductBook), true);
            } else {
                foreach ($links as $link) {
                    $productIds[(int)$link['product_id']] = true;
                }
            }
        }
        if ($draft->entry === CopyDraft::ENTRY_STORE_INHERIT) {
            foreach (array_keys($productIds) as $productId) {
                if (!$this->storeProducts->isSelected(
                    $sourceWebsiteId,
                    (int)$draft->sourceStoreId,
                    $productId,
                )) {
                    unset($productIds[$productId]);
                }
            }
        }
        $links = array_values(array_filter(
            $links,
            static fn(array $link): bool => isset($productIds[(int)$link['product_id']]),
        ));

        $targetByUuid = [];
        foreach ($targetProducts as $row) {
            $uuid = trim((string)($row[Product::schema_fields_GLOBAL_PRODUCT_UUID] ?? ''));
            if ($uuid !== '') {
                $targetByUuid[$uuid] = $row;
            }
        }

        $productRows = [];
        foreach (array_keys($productIds) as $productId) {
            $source = $sourceProductBook[$productId] ?? null;
            if ($source === null) {
                continue;
            }
            $uuid = trim((string)($source[Product::schema_fields_GLOBAL_PRODUCT_UUID] ?? ''));
            $this->assertCopyAuthorized($uuid, $sourceWebsiteId, $draft->targetWebsiteId);
            $target = $sourceWebsiteId === $draft->targetWebsiteId
                ? $source
                : ($targetByUuid[$uuid] ?? null);
            $action = 'create';
            if ($sourceWebsiteId !== $draft->targetWebsiteId && $target !== null) {
                $action = $draft->duplicatePolicy === CopyDraft::POLICY_UPDATE
                    ? 'update'
                    : 'skip';
            }
            $productRows[] = [
                'source_product_id' => $productId,
                'target_product_id' => $target === null
                    ? null
                    : (int)($target[Product::schema_fields_ID] ?? 0),
                'global_product_uuid' => $uuid,
                'sku' => (string)($source[Product::schema_fields_SKU] ?? ''),
                'action' => $action,
            ];
        }
        usort(
            $productRows,
            static fn(array $left, array $right): int => $left['source_product_id']
                <=> $right['source_product_id'],
        );

        $offers = $this->offers->listByProductIds($sourceWebsiteId, array_keys($productIds));
        $offersByProduct = [];
        foreach ($offers as $offer) {
            $offerId = (int)($offer[Offer::schema_fields_ID] ?? 0);
            $productId = (int)($offer[Offer::schema_fields_PRODUCT_ID] ?? 0);
            if ($offerId <= 0 || !isset($productIds[$productId])) {
                continue;
            }
            if ($draft->entry === CopyDraft::ENTRY_STORE_INHERIT
                && !$this->storeOffers->isSelected(
                    $sourceWebsiteId,
                    (int)$draft->sourceStoreId,
                    $offerId,
                )
            ) {
                continue;
            }
            $offersByProduct[$productId][] = $offer;
        }
        $offers = [];
        foreach ($offersByProduct as $rows) {
            foreach ($rows as $row) {
                $offers[] = $row;
            }
        }

        return [
            'categories' => $selectedCategories,
            'products' => $productRows,
            'offers' => $offers,
            'offers_by_product' => $offersByProduct,
            'links' => $links,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array{
     *   categories:array<int,array<string,mixed>>,
     *   products:list<array<string,mixed>>,
     *   offers:list<array<string,mixed>>,
     *   offers_by_product:array<int,list<array<string,mixed>>>,
     *   links:list<array{category_id:int,product_id:int}>,
     *   warnings:list<string>
     * } $plan
     */
    private function applyPlan(
        CopyDraft $draft,
        string $requestHash,
        array $plan,
        ?InventoryCatalogCopyCapabilityInterface $inventory,
    ): CopyCommitResult {
        $counts = [
            'categories_created' => 0,
            'products_created' => 0,
            'products_skipped' => 0,
            'products_updated' => 0,
            'offers_created' => 0,
            'links_created' => 0,
            'inventory_zeroed' => 0,
            'inventory_copied' => 0,
        ];
        $audit = [];
        if ($draft->entry === CopyDraft::ENTRY_BLANK) {
            $audit[] = ['op' => 'blank', 'target_store_ids' => $draft->selectedTargetStoreIds()];
            return new CopyCommitResult($draft->draftId, true, $counts, $audit);
        }

        [$categoryMap, $categoryAttributeMap] = $this->applyCategories(
            $draft,
            $plan['categories'],
            $counts,
            $audit,
        );
        $productMap = [];
        $productAttributeMap = [];
        $mediaProductMap = [];
        $offerMap = [];
        $offerAttributeMap = [];
        $priceOfferMap = [];

        foreach ($plan['products'] as $productRow) {
            $sourceProductId = (int)$productRow['source_product_id'];
            $action = (string)$productRow['action'];
            $targetProductId = (int)($productRow['target_product_id'] ?? 0);
            if ($draft->targetWebsiteId === (int)$draft->sourceWebsiteId) {
                $targetProductId = $sourceProductId;
            } elseif ($targetProductId <= 0) {
                $created = $this->products->create($draft->targetWebsiteId, [
                    Product::schema_fields_SKU => (string)$productRow['sku'],
                    Product::schema_fields_GLOBAL_PRODUCT_UUID => (string)$productRow[
                        'global_product_uuid'
                    ],
                ]);
                $targetProductId = (int)$created->getId();
                $counts['products_created']++;
            } elseif ($action === 'update'
                && in_array(CopyDraft::PKG_IDENTITY, $draft->fieldPackages, true)
            ) {
                $this->products->updateSku(
                    $draft->targetWebsiteId,
                    $targetProductId,
                    (string)$productRow['sku'],
                );
            }
            if ($targetProductId <= 0) {
                throw new \RuntimeException(__('Product copy 映射失败'));
            }
            $productMap[$sourceProductId] = $targetProductId;
            foreach ($draft->selectedTargetStoreIds() as $targetStoreId) {
                $this->storeProducts->select(
                    $draft->targetWebsiteId,
                    $targetStoreId,
                    $targetProductId,
                    true,
                );
            }

            if ($action === 'skip') {
                $counts['products_skipped']++;
            } elseif ($action === 'update') {
                $counts['products_updated']++;
                $productAttributeMap[$sourceProductId] = $targetProductId;
                $mediaProductMap[$sourceProductId] = $targetProductId;
            } else {
                $productAttributeMap[$sourceProductId] = $targetProductId;
                $mediaProductMap[$sourceProductId] = $targetProductId;
            }

            $audit[] = [
                'op' => 'product_' . $action,
                'source_product_id' => $sourceProductId,
                'target_product_id' => $targetProductId,
            ];

            foreach ($plan['offers_by_product'][$sourceProductId] ?? [] as $sourceOffer) {
                $sourceOfferId = (int)($sourceOffer[Offer::schema_fields_ID] ?? 0);
                $offerUuid = trim((string)($sourceOffer[
                    Offer::schema_fields_GLOBAL_OFFER_UUID
                ] ?? ''));
                $targetOffer = (int)$draft->sourceWebsiteId === $draft->targetWebsiteId
                    ? $this->offers->findById($draft->targetWebsiteId, $sourceOfferId)
                    : $this->offers->findByGlobalUuid($draft->targetWebsiteId, $offerUuid);
                $createdOffer = false;
                if ($targetOffer === null) {
                    $targetOffer = $this->offers->create($draft->targetWebsiteId, [
                        Offer::schema_fields_PRODUCT_ID => $targetProductId,
                        Offer::schema_fields_GLOBAL_OFFER_UUID => $offerUuid,
                    ]);
                    $counts['offers_created']++;
                    $createdOffer = true;
                }
                $targetOfferId = (int)$targetOffer->getId();
                $offerMap[$sourceOfferId] = $targetOfferId;
                foreach ($draft->selectedTargetStoreIds() as $targetStoreId) {
                    $this->storeOffers->select(
                        $draft->targetWebsiteId,
                        $targetStoreId,
                        $targetOfferId,
                        true,
                    );
                }
                if ($action !== 'skip' || $createdOffer) {
                    $offerAttributeMap[$sourceOfferId] = $targetOfferId;
                    $priceOfferMap[$sourceOfferId] = $targetOfferId;
                }
                $this->applyInventory(
                    $draft,
                    $sourceOfferId,
                    $targetOfferId,
                    $requestHash,
                    $inventory,
                    $counts,
                    $audit,
                );
            }
        }

        foreach ($plan['links'] as $link) {
            $targetCategoryId = $categoryMap[(int)$link['category_id']] ?? null;
            $targetProductId = $productMap[(int)$link['product_id']] ?? null;
            if ($targetCategoryId === null || $targetProductId === null) {
                continue;
            }
            $existing = $this->categoryLinks->find(
                $draft->targetWebsiteId,
                $targetCategoryId,
                $targetProductId,
            );
            $this->categoryLinks->link(
                $draft->targetWebsiteId,
                $targetCategoryId,
                $targetProductId,
            );
            if ($existing === null) {
                $counts['links_created']++;
            }
        }

        if (in_array(CopyDraft::PKG_ATTRS, $draft->fieldPackages, true)) {
            $this->copyAttributes($draft, 'category', $categoryAttributeMap);
            $this->copyAttributes($draft, 'product', $productAttributeMap);
            $this->copyAttributes($draft, 'offer', $offerAttributeMap);
        }
        if (in_array(CopyDraft::PKG_PRICE, $draft->fieldPackages, true)) {
            $this->copyPrices($draft, $priceOfferMap);
        }
        if (in_array(CopyDraft::PKG_MEDIA, $draft->fieldPackages, true)) {
            $this->copyMedia($draft, $mediaProductMap);
        }

        if ((int)$draft->sourceWebsiteId !== $draft->targetWebsiteId) {
            $audit[] = [
                'op' => 'cross_website_isolation',
                'source_website_id' => $draft->sourceWebsiteId,
                'source_overlay_lifted' => false,
            ];
        }
        if ($counts['categories_created'] > 0
            || $counts['links_created'] > 0
            || $counts['products_created'] > 0
            || $counts['products_updated'] > 0
            || $counts['offers_created'] > 0
        ) {
            ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)
                ->notifyCatalogChanged($draft->targetWebsiteId, 'copy_commit', [
                    'counts' => $counts,
                ]);
        }

        return new CopyCommitResult($draft->draftId, true, $counts, $audit);
    }

    /**
     * @param array<int, array<string, mixed>> $sourceCategories
     * @param array<string, int> $counts
     * @param list<array<string, mixed>> $audit
     * @return array{0:array<int,int>,1:array<int,int>}
     */
    private function applyCategories(
        CopyDraft $draft,
        array $sourceCategories,
        array &$counts,
        array &$audit,
    ): array {
        $map = [];
        $attributeMap = [];
        foreach ($sourceCategories as $sourceCategoryId => $row) {
            if ((int)$draft->sourceWebsiteId === $draft->targetWebsiteId) {
                $map[$sourceCategoryId] = $sourceCategoryId;
                $attributeMap[$sourceCategoryId] = $sourceCategoryId;
                continue;
            }
            $uuid = $this->categoryCopyUuid(
                (int)$draft->sourceWebsiteId,
                $sourceCategoryId,
                trim((string)($row[Category::schema_fields_GLOBAL_CATEGORY_UUID] ?? '')),
                $draft->targetWebsiteId,
            );
            $parentSourceId = isset($row[Category::schema_fields_PARENT_ID])
                ? (int)$row[Category::schema_fields_PARENT_ID]
                : 0;
            $structure = [
                Category::schema_fields_PARENT_ID => $parentSourceId > 0
                    ? ($map[$parentSourceId] ?? null)
                    : null,
                Category::schema_fields_PATH => '',
                Category::schema_fields_STATUS => (string)($row[
                    Category::schema_fields_STATUS
                ] ?? 'active'),
            ];
            $target = $this->categories->findByGlobalUuid($draft->targetWebsiteId, $uuid);
            $op = 'category_reuse';
            if ($target === null) {
                $target = $this->categories->create(
                    $draft->targetWebsiteId,
                    array_merge($structure, [
                        Category::schema_fields_GLOBAL_CATEGORY_UUID => $uuid,
                    ]),
                );
                $counts['categories_created']++;
                $op = 'category_create';
            } elseif ($draft->duplicatePolicy === CopyDraft::POLICY_UPDATE) {
                $target = $this->categories->updateStructure(
                    $draft->targetWebsiteId,
                    (int)$target->getId(),
                    $structure,
                );
                $op = 'category_update';
            }
            $targetCategoryId = (int)$target->getId();
            $map[$sourceCategoryId] = $targetCategoryId;
            if ($op !== 'category_reuse') {
                $attributeMap[$sourceCategoryId] = $targetCategoryId;
            }
            $audit[] = [
                'op' => $op,
                'source_category_id' => $sourceCategoryId,
                'target_category_id' => $targetCategoryId,
                'global_category_uuid' => $uuid,
            ];
        }
        return [$map, $attributeMap];
    }

    /** @param array<int, int> $entityMap */
    private function copyAttributes(CopyDraft $draft, string $entityType, array $entityMap): void
    {
        if ($entityMap === []) {
            return;
        }
        if ($entityType === ProductCategoryAttributeService::ENTITY_TYPE) {
            $this->categoryAttributes->copyExplicitAttributes(
                (int)$draft->sourceWebsiteId,
                $draft->targetWebsiteId,
                $entityMap,
                $this->sourceStoreIds($draft),
                fn(int $sourceStoreId): array => $this->targetStoreIdsForSourceRow($draft, $sourceStoreId),
            );

            return;
        }
        $rows = $this->attributes->listExplicitRows(
            (int)$draft->sourceWebsiteId,
            $entityType,
            array_keys($entityMap),
            $this->sourceStoreIds($draft),
        );
        foreach ($rows as $row) {
            $targetEntityId = $entityMap[(int)$row['entity_id']] ?? null;
            if ($targetEntityId === null) {
                continue;
            }
            foreach ($this->targetStoreIdsForSourceRow($draft, (int)$row['store_id']) as $targetStoreId) {
                if ($row['cleared']) {
                    $this->attributes->writeCleared(
                        $draft->targetWebsiteId,
                        $targetStoreId,
                        $entityType,
                        $targetEntityId,
                        (string)$row['attribute_code'],
                        (string)$row['locale'],
                        (bool)$row['is_required'],
                    );
                } else {
                    $this->attributes->writeExplicit(
                        $draft->targetWebsiteId,
                        $targetStoreId,
                        $entityType,
                        $targetEntityId,
                        (string)$row['attribute_code'],
                        (string)$row['locale'],
                        $row['value'],
                        (bool)$row['is_required'],
                    );
                }
            }
        }
    }

    /** @param array<int, int> $offerMap */
    private function copyPrices(CopyDraft $draft, array $offerMap): void
    {
        if ($offerMap === []) {
            return;
        }
        $rows = $this->prices->listExplicitRows(
            (int)$draft->sourceWebsiteId,
            array_keys($offerMap),
            $this->sourceStoreIds($draft),
        );
        foreach ($rows as $row) {
            $targetOfferId = $offerMap[(int)$row['offer_id']] ?? null;
            if ($targetOfferId === null) {
                continue;
            }
            foreach ($this->targetStoreIdsForSourceRow($draft, (int)$row['store_id']) as $targetStoreId) {
                if ($row['cleared']) {
                    $this->prices->writeCleared(
                        $draft->targetWebsiteId,
                        $targetStoreId,
                        $targetOfferId,
                        (string)$row['currency'],
                    );
                } else {
                    $this->prices->writeExplicit(
                        $draft->targetWebsiteId,
                        $targetStoreId,
                        $targetOfferId,
                        (string)$row['currency'],
                        (int)$row['amount_minor'],
                    );
                }
            }
        }
    }

    /** @param array<int, int> $productMap */
    private function copyMedia(CopyDraft $draft, array $productMap): void
    {
        if ($productMap === []
            || (int)$draft->sourceWebsiteId === $draft->targetWebsiteId
        ) {
            return;
        }
        $sourceRows = $this->media->listByProductIds(
            (int)$draft->sourceWebsiteId,
            array_keys($productMap),
        );
        $targetRowsByProduct = [];
        foreach (array_values(array_unique($productMap)) as $targetProductId) {
            foreach ($this->media->listByProductIds(
                $draft->targetWebsiteId,
                [$targetProductId],
            ) as $row) {
                $targetRowsByProduct[$targetProductId][
                    (string)($row[Media::schema_fields_BLOB_KEY] ?? '')
                ] = true;
            }
        }
        foreach ($sourceRows as $row) {
            $sourceProductId = (int)($row[Media::schema_fields_PRODUCT_ID] ?? 0);
            $targetProductId = $productMap[$sourceProductId] ?? null;
            $blobKey = trim((string)($row[Media::schema_fields_BLOB_KEY] ?? ''));
            if ($targetProductId === null
                || $blobKey === ''
                || isset($targetRowsByProduct[$targetProductId][$blobKey])
            ) {
                continue;
            }
            $existingBlob = $this->media->findByBlobKey($draft->targetWebsiteId, $blobKey);
            if ($existingBlob === null) {
                $this->media->create($draft->targetWebsiteId, [
                    Media::schema_fields_PRODUCT_ID => $targetProductId,
                    Media::schema_fields_PATH => (string)($row[Media::schema_fields_PATH] ?? ''),
                    Media::schema_fields_BLOB_KEY => $blobKey,
                    Media::schema_fields_POSITION => (int)($row[
                        Media::schema_fields_POSITION
                    ] ?? 0),
                ]);
            } else {
                $this->media->shareCopy(
                    $draft->targetWebsiteId,
                    (int)$existingBlob->getId(),
                    $targetProductId,
                    (int)($row[Media::schema_fields_POSITION] ?? 0),
                );
            }
            $targetRowsByProduct[$targetProductId][$blobKey] = true;
        }
    }

    /**
     * @param array<string, int> $counts
     * @param list<array<string, mixed>> $audit
     */
    private function applyInventory(
        CopyDraft $draft,
        int $sourceOfferId,
        int $targetOfferId,
        string $requestHash,
        ?InventoryCatalogCopyCapabilityInterface $inventory,
        array &$counts,
        array &$audit,
    ): void {
        if (!in_array(CopyDraft::PKG_INVENTORY, $draft->fieldPackages, true)) {
            return;
        }
        if ($inventory === null) {
            throw new \RuntimeException(__('库存复制能力不可用'));
        }
        $quantity = 0;
        if ($draft->inventoryCopyQty) {
            $sourceStoreId = $draft->entry === CopyDraft::ENTRY_STORE_INHERIT
                ? (int)$draft->sourceStoreId
                : 0;
            $quantity = $inventory->getAvailability(
                (int)$draft->sourceWebsiteId,
                $sourceStoreId,
                $sourceOfferId,
            )->onHandMinor;
        }
        foreach ($draft->selectedTargetStoreIds() as $targetStoreId) {
            if ($draft->inventoryCopyQty) {
                $counts['inventory_copied']++;
            } else {
                $counts['inventory_zeroed']++;
            }
            $idempotencyKey = 'copy:' . $draft->draftId
                . ':store:' . $targetStoreId
                . ':offer:' . $targetOfferId;
            $inventory->ensureStock(
                $draft->targetWebsiteId,
                $targetStoreId,
                $targetOfferId,
            );
            $inventory->setOnHand(
                $draft->targetWebsiteId,
                $targetStoreId,
                $targetOfferId,
                $quantity,
                $idempotencyKey,
                hash('sha256', $requestHash . ':' . $idempotencyKey),
            );
            $audit[] = [
                'op' => 'inventory',
                'store_id' => $targetStoreId,
                'offer_id' => $targetOfferId,
                'on_hand_minor' => $quantity,
                'copied' => $draft->inventoryCopyQty,
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $categoryBook
     * @param array<int, list<int>> $children
     * @return list<int>
     */
    private function descendantsAndSelf(int $categoryId, array $categoryBook, array $children): array
    {
        $selected = [];
        $stack = [$categoryId];
        while ($stack !== []) {
            $id = (int)array_pop($stack);
            if (isset($selected[$id]) || !isset($categoryBook[$id])) {
                continue;
            }
            $selected[$id] = $id;
            foreach (array_reverse($children[$id] ?? []) as $childId) {
                $stack[] = $childId;
            }
        }
        return array_values($selected);
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, array<string, mixed>>
     */
    private function sortCategories(array $categories): array
    {
        $depth = function (int $categoryId) use ($categories, &$depth): int {
            $parentId = isset($categories[$categoryId][Category::schema_fields_PARENT_ID])
                ? (int)$categories[$categoryId][Category::schema_fields_PARENT_ID]
                : 0;
            return $parentId > 0 && isset($categories[$parentId])
                ? 1 + $depth($parentId)
                : 0;
        };
        $ids = array_keys($categories);
        usort(
            $ids,
            fn(int $left, int $right): int => [$depth($left), $left]
                <=> [$depth($right), $right],
        );
        $sorted = [];
        foreach ($ids as $id) {
            $sorted[$id] = $categories[$id];
        }
        return $sorted;
    }

    /** @return list<int> */
    private function sourceStoreIds(CopyDraft $draft): array
    {
        if ($draft->entry !== CopyDraft::ENTRY_STORE_INHERIT) {
            return [0];
        }
        return [0, (int)$draft->sourceStoreId];
    }

    /** @return list<int> */
    private function assertCopyAuthorized(
        string $productUuid,
        int $sourceWebsiteId,
        int $targetWebsiteId,
    ): void {
        if ($sourceWebsiteId === $targetWebsiteId) {
            return;
        }
        if ($productUuid === '') {
            throw new ProductV2ConflictException(
                'product_copy_identity_missing',
                (string)__('待复制商品缺少全局身份'),
            );
        }

        $allowed = $this->copyAuthorization !== null
            ? (bool)($this->copyAuthorization)($productUuid, $sourceWebsiteId, $targetWebsiteId)
            : ($this->governance ?? ObjectManager::getInstance(ProductGovernanceService::class))
                ->canCopy($productUuid, $targetWebsiteId);
        if (!$allowed) {
            throw new ProductV2ConflictException(
                'product_copy_not_authorized',
                (string)__('目标 Website 未获得该商品的复制授权'),
                [
                    'global_product_uuid' => $productUuid,
                    'source_website_id' => $sourceWebsiteId,
                    'target_website_id' => $targetWebsiteId,
                ],
            );
        }
    }

    private function targetStoreIdsForSourceRow(CopyDraft $draft, int $sourceStoreId): array
    {
        if ($sourceStoreId === 0) {
            return (int)$draft->sourceWebsiteId === $draft->targetWebsiteId
                ? []
                : [0];
        }
        if ($draft->entry !== CopyDraft::ENTRY_STORE_INHERIT
            || $sourceStoreId !== (int)$draft->sourceStoreId
        ) {
            return [];
        }
        return $draft->selectedTargetStoreIds();
    }

    private function categoryCopyUuid(
        int $sourceWebsiteId,
        int $sourceCategoryId,
        string $sourceUuid,
        int $targetWebsiteId,
    ): string {
        $seed = implode('|', [
            'weline-product-copy-category-v1',
            $sourceWebsiteId,
            $sourceUuid !== '' ? $sourceUuid : ('id:' . $sourceCategoryId),
            $targetWebsiteId,
        ]);
        $bytes = hex2bin(substr(hash('sha256', $seed), 0, 32));
        if ($bytes === false) {
            throw new \LogicException(__('Category copy UUID 生成失败'));
        }
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function inventory(): ?InventoryCatalogCopyCapabilityInterface
    {
        if ($this->inventoryResolved) {
            return $this->resolvedInventory;
        }
        $this->inventoryResolved = true;
        if (!class_exists(InventoryCatalogCopyCapability::class)) {
            return null;
        }
        try {
            $this->resolvedInventory = ObjectManager::getInstance(
                InventoryCatalogCopyCapability::class,
            );
        } catch (Throwable) {
            $this->resolvedInventory = null;
        }
        return $this->resolvedInventory;
    }

    private function failure(string $draftId, string $errorCode): CopyCommitResult
    {
        $message = match ($errorCode) {
            'copy_idempotency_conflict' => (string)__('同一 draft 的 request_hash 不一致'),
            'copy_commit_in_progress' => (string)__('复制提交正在处理中'),
            'copy_draft_not_open' => (string)__('draft 不可提交'),
            'product_copy_not_authorized' => (string)__('目标 Website 未获得该商品的复制授权'),
            'product_copy_identity_missing' => (string)__('待复制商品缺少全局身份'),
            default => (string)__('复制提交失败'),
        };
        return new CopyCommitResult(
            draftId: $draftId,
            success: false,
            errorCode: $errorCode,
            message: $message,
        );
    }
}
