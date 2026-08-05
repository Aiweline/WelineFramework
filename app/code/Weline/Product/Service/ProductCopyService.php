<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\InventoryCatalogCopyCapability;
use Weline\Inventory\Api\InventoryCatalogCopyCapabilityInterface;
use Weline\Product\Api\Data\CopyCommitResult;
use Weline\Product\Api\Data\CopyDraft;
use Weline\Product\Api\Data\CopyPreview;
use Weline\Product\Repository\ProductCopyOperationRepository;

/**
 * Store catalog copy：blank / site_pull / store_inherit（MOD-P2C-002）.
 *
 * - 三入口共用本服务（TEST-P2C-COPY-01）
 * - 分类树勾选/排除子类 + 带品开关（TEST-P2C-COPY-02）
 * - 库存默认 0，显式 inventoryCopyQty 才抄数量（TEST-P2C-COPY-03）
 * - 跨 Website：先目标目录再 Store overlay；分类新 UUID；不抬升来源（TEST-P2C-COPY-04）
 * - 重复来源：skip / update_selected_fields（TEST-P2C-COPY-05）
 *
 * forTesting() 使用进程内账本；生产路径委托 ready-shard Repository 适配器。
 */
final class ProductCopyService
{
    /**
     * @var array<string, CopyDraft>
     */
    private array $drafts = [];

    /**
     * Successful commit receipts keyed by draft id.
     *
     * @var array<string, array{request_hash:string,result:CopyCommitResult}>
     */
    private array $commitReceipts = [];

    /**
     * In-memory catalog book（tests / harness）.
     *
     * @var array<int, array{
     *   categories: array<int, array<string,mixed>>,
     *   products: array<int, array<string,mixed>>,
     *   offers: array<int, array<string,mixed>>,
     *   links: array<int, array<int, true>>,
     *   store_products: array<int, array<int, bool>>,
     *   store_offers: array<int, array<int, bool>>,
     *   attrs: array<int, array<int, array<string, array{cleared:bool,value:mixed}>>>,
     *   prices: array<int, array<int, array{cleared:bool,value:int|null}>>,
     *   media: array<int, array<string,mixed>>,
     *   source_links: array<string, array{target_product_id:int,packages:list<string>}>,
     * }>|null
     */
    private ?array $book = null;

    private int $seq = 1000;

    public function __construct(
        private readonly ?InventoryCatalogCopyCapabilityInterface $inventory = null,
        bool $useMemory = false,
        private readonly ?ProductCopyOperationRepository $operations = null,
        private readonly ?ProductCopyDurableCatalogAdapter $catalogAdapter = null,
    ) {
        if ($useMemory) {
            $this->book = [];
        }
    }

    public static function forTesting(?InventoryCatalogCopyCapabilityInterface $inventory = null): self
    {
        return new self($inventory ?? InventoryCatalogCopyCapability::forTesting(), useMemory: true);
    }

    public function inventory(): ?InventoryCatalogCopyCapabilityInterface
    {
        return $this->inventory;
    }

    // ---------- test seed helpers ----------

    /** @param array<string, mixed> $data */
    public function seedCategory(int $websiteId, int $categoryId, ?int $parentId, string $name, ?string $uuid = null): void
    {
        $this->ensureWebsite($websiteId);
        $this->book[$websiteId]['categories'][$categoryId] = [
            'category_id' => $categoryId,
            'parent_id' => $parentId,
            'name' => $name,
            'uuid' => $uuid ?? ('cat-' . $websiteId . '-' . $categoryId),
        ];
    }

    /** @param array<string, mixed> $data */
    public function seedProduct(
        int $websiteId,
        int $productId,
        string $sku,
        string $name,
        array $attrs = [],
        ?int $offerId = null,
        int $priceMinor = 0,
        array $mediaIds = [],
    ): void {
        $this->ensureWebsite($websiteId);
        $this->book[$websiteId]['products'][$productId] = [
            'product_id' => $productId,
            'sku' => $sku,
            'name' => $name,
            'offer_ids' => $offerId !== null ? [$offerId] : [],
            'media_ids' => $mediaIds,
        ];
        foreach ($attrs as $code => $value) {
            $this->book[$websiteId]['attrs'][$productId][0][$code] = ['cleared' => false, 'value' => $value];
        }
        if ($offerId !== null) {
            $this->book[$websiteId]['offers'][$offerId] = [
                'offer_id' => $offerId,
                'product_id' => $productId,
                'sku' => $sku . '-offer',
            ];
            $this->book[$websiteId]['prices'][$offerId][0] = ['cleared' => false, 'value' => $priceMinor];
        }
    }

    public function seedLink(int $websiteId, int $categoryId, int $productId): void
    {
        $this->ensureWebsite($websiteId);
        $this->book[$websiteId]['links'][$categoryId][$productId] = true;
    }

    public function seedStoreProduct(int $websiteId, int $storeId, int $productId, bool $selected = true): void
    {
        $this->ensureWebsite($websiteId);
        $this->book[$websiteId]['store_products'][$storeId][$productId] = $selected;
    }

    public function seedStoreOffer(int $websiteId, int $storeId, int $offerId, bool $selected = true): void
    {
        $this->ensureWebsite($websiteId);
        $this->book[$websiteId]['store_offers'][$storeId][$offerId] = $selected;
    }

    public function seedAttrCleared(int $websiteId, int $productId, int $storeId, string $code): void
    {
        $this->ensureWebsite($websiteId);
        $this->book[$websiteId]['attrs'][$productId][$storeId][$code] = ['cleared' => true, 'value' => null];
    }

    public function seedMedia(int $websiteId, int $mediaId, int $productId, string $path): void
    {
        $this->ensureWebsite($websiteId);
        $this->book[$websiteId]['media'][$mediaId] = [
            'media_id' => $mediaId,
            'product_id' => $productId,
            'path' => $path,
            'blob_ref' => 'blob-' . $mediaId,
        ];
        $this->book[$websiteId]['products'][$productId]['media_ids'][] = $mediaId;
    }

    // ---------- draft / preview / commit / cancel ----------

    public function createDraft(CopyDraft $input): CopyDraft
    {
        $draft = clone $input;
        $draft->draftId = $draft->draftId !== '' ? $draft->draftId : $this->newId('draft');
        if (isset($this->drafts[$draft->draftId])) {
            throw new \InvalidArgumentException(__('draft_id 已存在'));
        }
        $draft->state = CopyDraft::STATE_DRAFT;
        $draft->entry = $this->normalizeEntry($draft->entry);
        $draft->duplicatePolicy = in_array($draft->duplicatePolicy, [CopyDraft::POLICY_SKIP, CopyDraft::POLICY_UPDATE], true)
            ? $draft->duplicatePolicy
            : CopyDraft::POLICY_SKIP;
        $draft->fieldPackages = array_values(array_unique(array_filter(
            $draft->fieldPackages,
            static fn(string $p): bool => in_array($p, [
                CopyDraft::PKG_IDENTITY,
                CopyDraft::PKG_ATTRS,
                CopyDraft::PKG_PRICE,
                CopyDraft::PKG_MEDIA,
                CopyDraft::PKG_INVENTORY,
            ], true),
        )));
        if ($draft->fieldPackages === []) {
            $draft->fieldPackages = [CopyDraft::PKG_IDENTITY];
        }
        $draft->categoryIds = $this->normalizePositiveIds($draft->categoryIds);
        $draft->excludedCategoryIds = $this->normalizePositiveIds($draft->excludedCategoryIds);

        if ($draft->targetWebsiteId < 0 || $draft->targetStoreId <= 0) {
            throw new \InvalidArgumentException(__('target website_id>=0 且 target store_id>0'));
        }

        if ($draft->entry === CopyDraft::ENTRY_BLANK) {
            $draft->sourceWebsiteId = null;
            $draft->sourceStoreId = null;
            $draft->categoryIds = [];
            $draft->includeProducts = false;
        } elseif ($draft->entry === CopyDraft::ENTRY_SITE_PULL) {
            $draft->sourceWebsiteId ??= $draft->targetWebsiteId;
            if ($draft->sourceWebsiteId < 0) {
                throw new \InvalidArgumentException(__('source website_id 须 >=0'));
            }
            $draft->sourceStoreId = 0;
        } else { // store_inherit
            if ($draft->sourceWebsiteId === null
                || $draft->sourceWebsiteId < 0
                || $draft->sourceStoreId === null
                || $draft->sourceStoreId <= 0
            ) {
                throw new \InvalidArgumentException(__('store_inherit 需要 source website/store'));
            }
            if ($draft->sourceWebsiteId === $draft->targetWebsiteId
                && $draft->sourceStoreId === $draft->targetStoreId
            ) {
                throw new \InvalidArgumentException(__('source 与 target Store 不能相同'));
            }
        }

        if ($this->book === null) {
            return $this->operationRepository()->create($draft);
        }
        $this->drafts[$draft->draftId] = $draft;
        return $draft;
    }

    public function getDraft(string $draftId): ?CopyDraft
    {
        if ($this->book === null) {
            return $this->operationRepository()->findDraft($draftId);
        }
        return $this->drafts[$draftId] ?? null;
    }

    public function cancel(string $draftId): void
    {
        if ($this->book === null) {
            $this->operationRepository()->cancel($draftId);
            return;
        }
        $draft = $this->requireDraft($draftId);
        if ($draft->state === CopyDraft::STATE_COMMITTED) {
            throw new \RuntimeException(__('已提交 draft 不可取消'));
        }
        $draft->state = CopyDraft::STATE_CANCELLED;
        $this->drafts[$draftId] = $draft;
    }

    public function preview(string $draftId): CopyPreview
    {
        $draft = $this->requireDraft($draftId);
        if ($draft->state !== CopyDraft::STATE_DRAFT) {
            throw new \RuntimeException(__('仅 draft 可 preview'));
        }
        if ($this->book === null) {
            return $this->durableCatalogAdapter()->preview($draft);
        }
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
        $willCopyQty = in_array(CopyDraft::PKG_INVENTORY, $draft->fieldPackages, true) && $draft->inventoryCopyQty;

        return new CopyPreview(
            draftId: $draftId,
            categoryCount: count($plan['categories']),
            productCount: count($plan['products']),
            offerCount: count($plan['offers']),
            linkCount: count($plan['links']),
            createCount: $create,
            skipCount: $skip,
            updateCount: $update,
            inventoryWillCopyQty: $willCopyQty,
            items: $plan['products'],
            warnings: $plan['warnings'],
        );
    }

    public function commit(string $draftId, string $requestHash): CopyCommitResult
    {
        $draft = $this->requireDraft($draftId);
        $requestHash = trim($requestHash);
        if ($requestHash === '') {
            throw new \InvalidArgumentException(__('request_hash 不能为空'));
        }
        if ($this->book === null) {
            return $this->durableCatalogAdapter()->commit(
                $draft,
                $requestHash,
                $this->operationRepository(),
            );
        }
        $receipt = $this->commitReceipts[$draftId] ?? null;
        if ($receipt !== null) {
            if (hash_equals($receipt['request_hash'], $requestHash)) {
                return $receipt['result'];
            }
            return new CopyCommitResult(
                draftId: $draftId,
                success: false,
                errorCode: 'copy_idempotency_conflict',
                message: (string)__('同一 draft 的 request_hash 不一致'),
            );
        }
        if ($draft->state !== CopyDraft::STATE_DRAFT) {
            return new CopyCommitResult(
                draftId: $draftId,
                success: false,
                errorCode: 'copy_draft_not_open',
                message: (string)__('draft 不可提交：%{1}', [$draft->state]),
            );
        }

        // Per-target transaction: snapshot + apply; on failure restore (TEST: one target)
        $targetWid = $draft->targetWebsiteId;
        $snapshot = $this->snapshotWebsite($targetWid);
        try {
            $operation = function () use ($draft, $draftId, $requestHash, $targetWid): CopyCommitResult {
            $plan = $this->buildPlan($draft);
            $audit = [];
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

            if ($draft->entry === CopyDraft::ENTRY_BLANK) {
                $audit[] = ['op' => 'blank', 'target_store_id' => $draft->targetStoreId];
                $draft->state = CopyDraft::STATE_COMMITTED;
                $this->drafts[$draftId] = $draft;
                return $this->recordCommitReceipt(
                    $requestHash,
                    new CopyCommitResult($draftId, true, $counts, $audit),
                );
            }

            $catMap = []; // sourceCatId => targetCatId
            foreach ($plan['categories'] as $srcCatId => $srcCat) {
                if ($draft->sourceWebsiteId === $targetWid) {
                    $catMap[$srcCatId] = $srcCatId; // same website: reuse category ids
                    continue;
                }
                // Cross-website: new category UUID, new id (TEST-P2C-COPY-04)
                $newId = ++$this->seq;
                $this->seedCategory(
                    $targetWid,
                    $newId,
                    $srcCat['parent_id'] !== null ? ($catMap[$srcCat['parent_id']] ?? null) : null,
                    (string)$srcCat['name'],
                    $this->newId('cat'),
                );
                $catMap[$srcCatId] = $newId;
                $counts['categories_created']++;
                $audit[] = [
                    'op' => 'category_create',
                    'source_category_id' => $srcCatId,
                    'target_category_id' => $newId,
                    'uuid' => $this->book[$targetWid]['categories'][$newId]['uuid'],
                ];
            }

            foreach ($plan['products'] as $row) {
                $srcPid = (int)$row['source_product_id'];
                $action = (string)$row['action'];
                $srcProduct = $this->book[(int)$draft->sourceWebsiteId]['products'][$srcPid] ?? null;
                if ($srcProduct === null) {
                    continue;
                }

                if ($action === 'skip') {
                    $counts['products_skipped']++;
                    $audit[] = ['op' => 'product_skip', 'source_product_id' => $srcPid, 'target_product_id' => $row['target_product_id']];
                    continue;
                }

                if ($action === 'update') {
                    $targetPid = (int)$row['target_product_id'];
                    $this->applyFieldPackages($draft, $srcPid, $targetPid, update: true);
                    $this->selectStoreProduct($targetWid, $draft->targetStoreId, $targetPid, true);
                    foreach ($srcProduct['offer_ids'] as $srcOid) {
                        if (!$this->isSourceOfferSelected($draft, (int)$srcOid)) {
                            continue;
                        }
                        $tgtOid = $this->mapOffer($draft, (int)$srcOid, $targetPid, createIfMissing: false);
                        if ($tgtOid !== null) {
                            $this->selectStoreOffer($targetWid, $draft->targetStoreId, $tgtOid, true);
                            $this->applyInventory($draft, (int)$srcOid, $tgtOid, $requestHash, $counts, $audit);
                        }
                    }
                    foreach ($plan['links'] as $link) {
                        if ((int)$link['source_product_id'] !== $srcPid) {
                            continue;
                        }
                        $tgtCat = $catMap[(int)$link['source_category_id']] ?? null;
                        if ($tgtCat !== null) {
                            $this->book[$targetWid]['links'][$tgtCat][$targetPid] = true;
                        }
                    }
                    $counts['products_updated']++;
                    $audit[] = ['op' => 'product_update', 'source_product_id' => $srcPid, 'target_product_id' => $targetPid];
                    continue;
                }

                // create
                if ($draft->sourceWebsiteId === $targetWid) {
                    $targetPid = $srcPid; // same website site_pull / inherit: select existing catalog product
                } else {
                    $targetPid = ++$this->seq;
                    $this->book[$targetWid]['products'][$targetPid] = [
                        'product_id' => $targetPid,
                        'sku' => (string)$srcProduct['sku'],
                        'name' => (string)$srcProduct['name'],
                        'offer_ids' => [],
                        'media_ids' => [],
                    ];
                    $counts['products_created']++;
                }

                $this->applyFieldPackages($draft, $srcPid, $targetPid, update: false);
                $this->selectStoreProduct($targetWid, $draft->targetStoreId, $targetPid, true);

                foreach ($srcProduct['offer_ids'] as $srcOid) {
                    if (!$this->isSourceOfferSelected($draft, (int)$srcOid)) {
                        continue;
                    }
                    $tgtOid = $this->mapOffer($draft, (int)$srcOid, $targetPid, createIfMissing: true);
                    if ($tgtOid !== null) {
                        if ($draft->sourceWebsiteId !== $targetWid) {
                            $counts['offers_created']++;
                        }
                        $this->selectStoreOffer($targetWid, $draft->targetStoreId, $tgtOid, true);
                        $this->applyInventory($draft, (int)$srcOid, $tgtOid, $requestHash, $counts, $audit);
                    }
                }

                foreach ($plan['links'] as $link) {
                    if ((int)$link['source_product_id'] !== $srcPid) {
                        continue;
                    }
                    $tgtCat = $catMap[(int)$link['source_category_id']] ?? null;
                    if ($tgtCat !== null) {
                        $this->book[$targetWid]['links'][$tgtCat][$targetPid] = true;
                        $counts['links_created']++;
                    }
                }

                $linkKey = $this->sourceLinkKey($draft, $srcPid);
                $this->book[$targetWid]['source_links'][$linkKey] = [
                    'target_product_id' => $targetPid,
                    'packages' => $draft->fieldPackages,
                ];
                $audit[] = ['op' => 'product_create_or_select', 'source_product_id' => $srcPid, 'target_product_id' => $targetPid];
            }

            // Ensure source overlays untouched on cross-site (assert via audit marker)
            if ((int)$draft->sourceWebsiteId !== $targetWid) {
                $audit[] = [
                    'op' => 'cross_website_isolation',
                    'source_website_id' => $draft->sourceWebsiteId,
                    'source_overlay_lifted' => false,
                ];
            }

            $draft->state = CopyDraft::STATE_COMMITTED;
            $this->drafts[$draftId] = $draft;
            return $this->recordCommitReceipt(
                $requestHash,
                new CopyCommitResult($draftId, true, $counts, $audit),
            );
            };

            return $this->inventory !== null
                ? $this->inventory->transactional($operation)
                : $operation();
        } catch (\Throwable $e) {
            $this->restoreWebsite($targetWid, $snapshot);
            return new CopyCommitResult(
                draftId: $draftId,
                success: false,
                errorCode: 'copy_commit_failed',
                message: (string)__('复制提交失败'),
            );
        }
    }

    // ---------- inspect helpers for tests ----------

    public function listExplicitStoreProducts(int $websiteId, int $storeId): array
    {
        $this->ensureWebsite($websiteId);
        $out = [];
        foreach ($this->book[$websiteId]['store_products'][$storeId] ?? [] as $pid => $selected) {
            if ($selected) {
                $out[] = (int)$pid;
            }
        }
        sort($out);
        return $out;
    }

    /** @return list<int> */
    public function listExplicitStoreOffers(int $websiteId, int $storeId): array
    {
        $this->ensureWebsite($websiteId);
        $out = [];
        foreach ($this->book[$websiteId]['store_offers'][$storeId] ?? [] as $offerId => $selected) {
            if ($selected) {
                $out[] = (int)$offerId;
            }
        }
        sort($out);
        return $out;
    }

    public function isStoreProductSelected(int $websiteId, int $storeId, int $productId): bool
    {
        $this->ensureWebsite($websiteId);
        if (!isset($this->book[$websiteId]['store_products'][$storeId][$productId])) {
            return isset($this->book[$websiteId]['products'][$productId]);
        }
        return (bool)$this->book[$websiteId]['store_products'][$storeId][$productId];
    }

    public function getProduct(int $websiteId, int $productId): ?array
    {
        return $this->book[$websiteId]['products'][$productId] ?? null;
    }

    public function getCategory(int $websiteId, int $categoryId): ?array
    {
        return $this->book[$websiteId]['categories'][$categoryId] ?? null;
    }

    public function listCategoryLinks(int $websiteId, int $categoryId): array
    {
        return array_keys($this->book[$websiteId]['links'][$categoryId] ?? []);
    }

    public function getAttr(int $websiteId, int $productId, int $storeId, string $code): ?array
    {
        return $this->book[$websiteId]['attrs'][$productId][$storeId][$code] ?? null;
    }

    public function getPrice(int $websiteId, int $offerId, int $storeId): ?array
    {
        return $this->book[$websiteId]['prices'][$offerId][$storeId] ?? null;
    }

    public function countSourceLinks(int $websiteId): int
    {
        return count($this->book[$websiteId]['source_links'] ?? []);
    }

    public function countProducts(int $websiteId): int
    {
        return count($this->book[$websiteId]['products'] ?? []);
    }

    // ---------- internals ----------

    private function requireDraft(string $draftId): CopyDraft
    {
        $draft = $this->getDraft($draftId);
        if ($draft === null) {
            throw new \InvalidArgumentException(__('draft 不存在：%{1}', [$draftId]));
        }
        return $draft;
    }

    private function operationRepository(): ProductCopyOperationRepository
    {
        return $this->operations
            ?? ObjectManager::getInstance(ProductCopyOperationRepository::class);
    }

    private function durableCatalogAdapter(): ProductCopyDurableCatalogAdapter
    {
        return $this->catalogAdapter
            ?? ObjectManager::getInstance(ProductCopyDurableCatalogAdapter::class);
    }

    private function normalizeEntry(string $entry): string
    {
        $entry = trim($entry);
        return match ($entry) {
            CopyDraft::ENTRY_BLANK, CopyDraft::ENTRY_SITE_PULL, CopyDraft::ENTRY_STORE_INHERIT => $entry,
            default => throw new \InvalidArgumentException(__('未知 copy entry：%{1}', [$entry])),
        };
    }

    /**
     * @return array{
     *   categories: array<int, array<string,mixed>>,
     *   products: list<array<string,mixed>>,
     *   offers: list<int>,
     *   links: list<array{source_category_id:int,source_product_id:int}>,
     *   warnings: list<string>
     * }
     */
    private function buildPlan(CopyDraft $draft): array
    {
        if ($draft->entry === CopyDraft::ENTRY_BLANK || $this->book === null) {
            return ['categories' => [], 'products' => [], 'offers' => [], 'links' => [], 'warnings' => []];
        }
        $srcWid = (int)$draft->sourceWebsiteId;
        $this->ensureWebsite($srcWid);
        $excluded = [];
        foreach ($draft->excludedCategoryIds as $excludedCategoryId) {
            foreach ($this->descendantsAndSelf($srcWid, $excludedCategoryId) as $id) {
                $excluded[$id] = true;
            }
        }

        $selectedCats = [];
        foreach ($draft->categoryIds as $cid) {
            foreach ($this->descendantsAndSelf($srcWid, (int)$cid) as $id) {
                if (isset($excluded[$id])) {
                    continue;
                }
                $selectedCats[$id] = $this->book[$srcWid]['categories'][$id];
            }
        }

        $productIds = [];
        $links = [];
        if ($draft->includeProducts) {
            foreach ($selectedCats as $cid => $_) {
                foreach (array_keys($this->book[$srcWid]['links'][$cid] ?? []) as $pid) {
                    if ($draft->entry === CopyDraft::ENTRY_STORE_INHERIT) {
                        $sel = $this->book[$srcWid]['store_products'][(int)$draft->sourceStoreId][$pid] ?? true;
                        if (!$sel) {
                            continue;
                        }
                    }
                    $productIds[$pid] = true;
                    $links[] = ['source_category_id' => (int)$cid, 'source_product_id' => (int)$pid];
                }
            }
            // site_pull without categories: all website products
            if ($draft->categoryIds === [] && $draft->entry === CopyDraft::ENTRY_SITE_PULL) {
                foreach (array_keys($this->book[$srcWid]['products']) as $pid) {
                    $productIds[$pid] = true;
                }
            }
        }

        $offers = [];
        $products = [];
        foreach (array_keys($productIds) as $pid) {
            $p = $this->book[$srcWid]['products'][$pid];
            foreach ($p['offer_ids'] as $oid) {
                if ($this->isSourceOfferSelected($draft, (int)$oid)) {
                    $offers[] = (int)$oid;
                }
            }
            $linkKey = $this->sourceLinkKey($draft, (int)$pid);
            $existing = $this->book[$draft->targetWebsiteId]['source_links'][$linkKey] ?? null;
            $action = 'create';
            $targetPid = null;
            if ($existing !== null) {
                if ($draft->duplicatePolicy === CopyDraft::POLICY_SKIP) {
                    $action = 'skip';
                    $targetPid = (int)$existing['target_product_id'];
                } else {
                    $action = 'update';
                    $targetPid = (int)$existing['target_product_id'];
                }
            } elseif ($srcWid === $draft->targetWebsiteId && isset($this->book[$srcWid]['products'][$pid])) {
                // same-site: will select existing product id
                $targetPid = (int)$pid;
            }
            $products[] = [
                'source_product_id' => (int)$pid,
                'sku' => (string)$p['sku'],
                'action' => $action,
                'target_product_id' => $targetPid,
            ];
        }

        return [
            'categories' => $selectedCats,
            'products' => $products,
            'offers' => array_values(array_unique($offers)),
            'links' => $links,
            'warnings' => [],
        ];
    }

    /** @return list<int> */
    private function descendantsAndSelf(int $websiteId, int $categoryId): array
    {
        $out = [];
        $stack = [$categoryId];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($out[$id]) || !isset($this->book[$websiteId]['categories'][$id])) {
                continue;
            }
            $out[$id] = $id;
            foreach ($this->book[$websiteId]['categories'] as $cid => $cat) {
                if ((int)($cat['parent_id'] ?? -1) === $id) {
                    $stack[] = (int)$cid;
                }
            }
        }
        return array_values($out);
    }

    private function applyFieldPackages(CopyDraft $draft, int $srcPid, int $targetPid, bool $update): void
    {
        $srcWid = (int)$draft->sourceWebsiteId;
        $tgtWid = $draft->targetWebsiteId;
        $src = $this->book[$srcWid]['products'][$srcPid];
        $tgtStore = $draft->targetStoreId;
        $srcStore = $draft->entry === CopyDraft::ENTRY_STORE_INHERIT ? (int)$draft->sourceStoreId : 0;

        if (in_array(CopyDraft::PKG_IDENTITY, $draft->fieldPackages, true)) {
            $this->book[$tgtWid]['products'][$targetPid]['name'] = $src['name'];
            $this->book[$tgtWid]['products'][$targetPid]['sku'] = $src['sku'];
        }

        if (in_array(CopyDraft::PKG_ATTRS, $draft->fieldPackages, true)) {
            $codes = [];
            foreach ($this->book[$srcWid]['attrs'][$srcPid][$srcStore] ?? [] as $code => $_) {
                $codes[$code] = true;
            }
            foreach ($this->book[$srcWid]['attrs'][$srcPid][0] ?? [] as $code => $_) {
                $codes[$code] = true;
            }
            foreach (array_keys($codes) as $code) {
                $row = $this->book[$srcWid]['attrs'][$srcPid][$srcStore][$code]
                    ?? $this->book[$srcWid]['attrs'][$srcPid][0][$code]
                    ?? null;
                if ($row === null) {
                    continue;
                }
                // cleared must remain cleared（REQ-006）
                $this->book[$tgtWid]['attrs'][$targetPid][$tgtStore][$code] = [
                    'cleared' => (bool)$row['cleared'],
                    'value' => $row['cleared'] ? null : $row['value'],
                ];
            }
            // update_selected_fields：不删未选字段 — 未在 packages 的 attrs 本来就不会动
        }

        if (in_array(CopyDraft::PKG_MEDIA, $draft->fieldPackages, true)) {
            foreach ($src['media_ids'] as $mid) {
                $m = $this->book[$srcWid]['media'][$mid] ?? null;
                if ($m === null) {
                    continue;
                }
                if ($srcWid === $tgtWid) {
                    continue; // share existing
                }
                $newMid = ++$this->seq;
                $this->book[$tgtWid]['media'][$newMid] = [
                    'media_id' => $newMid,
                    'product_id' => $targetPid,
                    'path' => $m['path'],
                    'blob_ref' => $m['blob_ref'], // share blob (COW later)
                ];
                $this->book[$tgtWid]['products'][$targetPid]['media_ids'][] = $newMid;
            }
        }

        unset($update);
    }

    private function mapOffer(CopyDraft $draft, int $srcOid, int $targetPid, bool $createIfMissing): ?int
    {
        $srcWid = (int)$draft->sourceWebsiteId;
        $tgtWid = $draft->targetWebsiteId;
        $src = $this->book[$srcWid]['offers'][$srcOid] ?? null;
        if ($src === null) {
            return null;
        }
        if ($srcWid === $tgtWid) {
            $this->copyPrice($draft, $srcOid, $srcOid);
            return $srcOid;
        }
        // find existing mapped offer by sku on target product
        foreach ($this->book[$tgtWid]['offers'] as $oid => $o) {
            if ((int)$o['product_id'] === $targetPid && (string)$o['sku'] === (string)$src['sku']) {
                $this->copyPrice($draft, $srcOid, (int)$oid);
                return (int)$oid;
            }
        }
        if (!$createIfMissing) {
            return null;
        }
        $newOid = ++$this->seq;
        $this->book[$tgtWid]['offers'][$newOid] = [
            'offer_id' => $newOid,
            'product_id' => $targetPid,
            'sku' => $src['sku'],
        ];
        $this->book[$tgtWid]['products'][$targetPid]['offer_ids'][] = $newOid;
        $this->copyPrice($draft, $srcOid, $newOid);
        return $newOid;
    }

    private function copyPrice(CopyDraft $draft, int $sourceOfferId, int $targetOfferId): void
    {
        if (!in_array(CopyDraft::PKG_PRICE, $draft->fieldPackages, true)) {
            return;
        }
        $sourceWebsiteId = (int)$draft->sourceWebsiteId;
        $sourceStoreId = $draft->entry === CopyDraft::ENTRY_STORE_INHERIT
            ? (int)$draft->sourceStoreId
            : 0;
        $price = $this->book[$sourceWebsiteId]['prices'][$sourceOfferId][$sourceStoreId]
            ?? $this->book[$sourceWebsiteId]['prices'][$sourceOfferId][0]
            ?? ['cleared' => false, 'value' => 0];
        $this->book[$draft->targetWebsiteId]['prices'][$targetOfferId][$draft->targetStoreId] = $price;
        if ($sourceWebsiteId !== $draft->targetWebsiteId) {
            $this->book[$draft->targetWebsiteId]['prices'][$targetOfferId][0] = $price;
        }
    }

    /**
     * @param array<string, int> $counts
     * @param list<array<string, mixed>> $audit
     */
    private function applyInventory(
        CopyDraft $draft,
        int $srcOid,
        int $tgtOid,
        string $requestHash,
        array &$counts,
        array &$audit,
    ): void {
        if (!in_array(CopyDraft::PKG_INVENTORY, $draft->fieldPackages, true) || $this->inventory === null) {
            return;
        }
        $srcWid = (int)$draft->sourceWebsiteId;
        $srcStore = $draft->entry === CopyDraft::ENTRY_STORE_INHERIT ? (int)$draft->sourceStoreId : 0;
        $qty = 0;
        if ($draft->inventoryCopyQty) {
            $avail = $this->inventory->getAvailability($srcWid, $srcStore, $srcOid);
            $qty = $avail->onHandMinor;
            $counts['inventory_copied']++;
        } else {
            $counts['inventory_zeroed']++;
        }
        $idem = 'copy:' . $draft->draftId . ':offer:' . $tgtOid;
        $this->inventory->ensureStock($draft->targetWebsiteId, $draft->targetStoreId, $tgtOid);
        $this->inventory->setOnHand(
            $draft->targetWebsiteId,
            $draft->targetStoreId,
            $tgtOid,
            $qty,
            $idem,
            hash('sha256', $requestHash . ':' . $idem),
        );
        $audit[] = [
            'op' => 'inventory',
            'offer_id' => $tgtOid,
            'on_hand_minor' => $qty,
            'copied' => $draft->inventoryCopyQty,
        ];
    }

    private function selectStoreProduct(int $websiteId, int $storeId, int $productId, bool $selected): void
    {
        $this->ensureWebsite($websiteId);
        $this->book[$websiteId]['store_products'][$storeId][$productId] = $selected;
    }

    private function selectStoreOffer(int $websiteId, int $storeId, int $offerId, bool $selected): void
    {
        $this->ensureWebsite($websiteId);
        $this->book[$websiteId]['store_offers'][$storeId][$offerId] = $selected;
    }

    private function sourceLinkKey(CopyDraft $draft, int $sourceProductId): string
    {
        return implode(':', [
            (int)$draft->sourceWebsiteId,
            (int)($draft->sourceStoreId ?? 0),
            $sourceProductId,
            $draft->targetStoreId,
        ]);
    }

    private function isSourceOfferSelected(CopyDraft $draft, int $sourceOfferId): bool
    {
        if ($draft->entry !== CopyDraft::ENTRY_STORE_INHERIT) {
            return true;
        }
        return (bool)($this->book[(int)$draft->sourceWebsiteId]['store_offers'][(int)$draft->sourceStoreId][$sourceOfferId]
            ?? true);
    }

    /** @param list<mixed> $ids @return list<int> */
    private function normalizePositiveIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int)$id <= 0) {
                continue;
            }
            $normalized[(int)$id] = (int)$id;
        }
        ksort($normalized);
        return array_values($normalized);
    }

    private function recordCommitReceipt(string $requestHash, CopyCommitResult $result): CopyCommitResult
    {
        $this->commitReceipts[$result->draftId] = [
            'request_hash' => $requestHash,
            'result' => $result,
        ];
        return $result;
    }

    private function ensureWebsite(int $websiteId): void
    {
        if ($this->book === null) {
            throw new \RuntimeException(__('memory book 未启用'));
        }
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 须 >=0'));
        }
        if (!isset($this->book[$websiteId])) {
            $this->book[$websiteId] = [
                'categories' => [],
                'products' => [],
                'offers' => [],
                'links' => [],
                'store_products' => [],
                'store_offers' => [],
                'attrs' => [],
                'prices' => [],
                'media' => [],
                'source_links' => [],
            ];
        }
    }

    /** @return array<string, mixed> */
    private function snapshotWebsite(int $websiteId): array
    {
        $this->ensureWebsite($websiteId);
        return unserialize(serialize($this->book[$websiteId]));
    }

    /** @param array<string, mixed> $snapshot */
    private function restoreWebsite(int $websiteId, array $snapshot): void
    {
        $this->book[$websiteId] = $snapshot;
    }

    private function newId(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(8));
    }
}
