# Hanfu Test Catalog Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在 `website_id=0` 中可审计、可恢复地删除已批准的 29 个测试/演示商品、从属数据与无引用独占文件，同时完整保留分类、品牌、供应商和共享资产。

**Architecture:** 使用冻结选择器生成稳定摘要，清理服务只接受匹配摘要的 apply 请求；数据库依赖按外键反序删除，媒体先通过 FileManager 移入本次运行隔离区，数据库校验成功后才最终删除。脚本提供 dry-run、apply、verify 三段式入口，所有证据写入 `var/hanfu-1688/<run-id>/`。

**Tech Stack:** PHP 8.x、Weline ObjectManager/Repository、Weline FileManager、Weline Inventory、PHPUnit、JSON 运行产物、内置浏览器真实验收。

**Spec:** [汉服 1688 全量商品导入与测试目录清理设计](../specs/2026-09-01-hanfu-1688-full-catalog-design.md)

## Global Constraints

- 目标网站固定为 `website_id=0`；冻结商品 ID 为 `[1,2,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,36,39,45]`，不得扩大选择范围。
- 必须保留全部分类、品牌、供应商、供应商品牌关系和共享媒体；不得删除 `pub/media/catalog/hanfu-entities/**` 与 `pub/media/catalog/hanfu/r2/categories/**`。
- 首页或内容仍引用的文件不得删除；只有数据库、配置和内容引用均为零的独占文件才允许进入隔离区。
- apply 必须复用 dry-run 生成的 `selection_digest`；商品集合、UUID、offer、媒体或保留实体快照发生漂移时必须拒绝执行并重新 dry-run。
- 数据库删除前先隔离本地文件；数据库事务或验证失败时恢复隔离文件。最终文件删除失败只记录待处理清单，不回滚已验证的数据库清理。
- 不删除订单、订单行、审计流水或外部系统记录；若冻结 offer 被这些保留记录引用，清理在写入前失败并列出精确引用。
- 不修改 `generated/`，不覆盖工作区已有 Blog 改动和未跟踪内容。
- 本计划新增 Service/Repository/脚本，并在 `Weline_Inventory` 新增且仅新增 `InventoryCatalogMaintenanceInterface` 作为跨模块窄写契约；不新增 Controller、Model、Event、Hook 或注册入口，因此不触发模块版本号变更。
- 每个实现任务遵循红灯测试 → 最小实现 → 绿灯测试 → 中文提交；真实数据库清理只在全部开发测试和 dry-run 验收通过后执行。
- 每次提交只暂存当前任务列出的文件。

---

## File Responsibility Map

| File | Responsibility |
|---|---|
| `Sample/HanfuCleanup/HanfuTestCatalogSelection.php` | 冻结 ID、规范化快照和稳定摘要 |
| `Sample/HanfuCleanup/HanfuIdentityCleanupService.php` | 只清理无其他网站引用的产品/offer/SKU 身份 |
| `Sample/HanfuCleanup/HanfuCatalogMediaQuarantine.php` | 独占对象判定、隔离、恢复和最终删除 |
| `Sample/HanfuCleanup/HanfuCatalogCleanupService.php` | preview/apply/verify 编排和后置条件 |
| Product repositories listed in Task 2 | 精确 ID 集合的从属行清理 |
| `Inventory/Api/InventoryCatalogMaintenanceInterface.php` | Product 可依赖的库存目录维护窄写契约 |
| `InventoryService.php` | 实现库存预检、受保护引用和精确 offer 清理；不得由 Product 直接依赖 |
| `scripts/cleanup-hanfu-test-catalog.php` | 三阶段 CLI、退出码和报告 |

## Frozen Runtime Contract

### Frozen inventory

| Group | Product IDs | Count |
|---|---|---:|
| 临时/手工测试 | `1,2,25,36,45` | 5 |
| 主题演示 | `5-12` | 8 |
| Web 演示 | `13-24` | 12 |
| 汉服种子/测试 | `26,27,28,39` | 4 |
| Total | 固定并集 | 29 |

### CLI contract

```bash
php app/code/Weline/Product/scripts/cleanup-hanfu-test-catalog.php \
  --website=0 --dry-run --run-id=cleanup-20260902

SELECTION_DIGEST="$(php -r '$d=json_decode(file_get_contents("var/hanfu-1688/cleanup-20260902/cleanup-selection.json"),true,512,JSON_THROW_ON_ERROR); echo $d["selection_digest"];')"
php app/code/Weline/Product/scripts/cleanup-hanfu-test-catalog.php \
  --website=0 --apply="$SELECTION_DIGEST" --run-id=cleanup-20260902

php app/code/Weline/Product/scripts/cleanup-hanfu-test-catalog.php \
  --website=0 --verify="$SELECTION_DIGEST" --run-id=cleanup-20260902
```

Exit codes are fixed: `0` success, `2` invalid arguments, `3` selection drift, `4` protected reference, `5` database failure, `6` file quarantine/finalization failure, `7` postcondition failure.

### Report contract

`cleanup-selection.json` contains `contract=hanfu.cleanup.selection.v1`, normalized product rows, product/offer UUIDs, media rows, dependent-row counts, protected references, preservation snapshot and `selection_digest`. `cleanup-report.json` contains `contract=hanfu.cleanup.report.v1`, mode, digest, per-table deleted counts, quarantine actions, preserved counts, postconditions, timestamps and terminal status.

---

### Task 1: Freeze the 29-product selection and stable digest

**Files:**

- Create: `app/code/Weline/Product/Sample/HanfuCleanup/HanfuTestCatalogSelection.php`
- Create: `app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuTestCatalogSelectionTest.php`

**Interfaces:**

```php
final class HanfuTestCatalogSelection
{
    /** @return list<int> */
    public function productIds(): array;

    /** @param array<string,mixed> $snapshot */
    public function digest(array $snapshot): string;

    /** @param array<string,mixed> $snapshot */
    public function canonicalize(array $snapshot): array;
}
```

- [ ] **Step 1: Write the failing selection tests**

```php
public function testProductIdsAreTheApprovedFrozenSet(): void
{
    $selection = new HanfuTestCatalogSelection();

    self::assertSame(
        [1, 2, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 36, 39, 45],
        $selection->productIds(),
    );
}

public function testDigestIgnoresAssociativeKeyOrderButNotCatalogDrift(): void
{
    $selection = new HanfuTestCatalogSelection();
    $left = ['website_id' => 0, 'products' => [['product_id' => 1, 'sku' => 'R43-A']]];
    $same = ['products' => [['sku' => 'R43-A', 'product_id' => 1]], 'website_id' => 0];
    $changed = ['website_id' => 0, 'products' => [['product_id' => 1, 'sku' => 'R43-B']]];

    self::assertSame($selection->digest($left), $selection->digest($same));
    self::assertNotSame($selection->digest($left), $selection->digest($changed));
}
```

- [ ] **Step 2: Run the focused test and confirm red**

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuTestCatalogSelectionTest.php
```

Expected: failure because `HanfuTestCatalogSelection` does not exist.

- [ ] **Step 3: Implement canonical recursive key sorting and SHA-256**

The digest input includes `website_id`, selected product fields, sorted offers, media rows, dependent counts, protected references and preservation snapshot. Implement recursive associative-key sorting and stable list ordering:

```php
public function digest(array $snapshot): string
{
    $canonical = $this->canonicalize($snapshot);
    return hash(
        'sha256',
        json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );
}

public function canonicalize(array $value): array
{
    if (array_is_list($value)) {
        $normalized = array_map(
            fn(mixed $item): mixed => is_array($item) ? $this->canonicalize($item) : $item,
            $value,
        );
        usort($normalized, static fn(mixed $a, mixed $b): int =>
            json_encode($a, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            <=> json_encode($b, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        return $normalized;
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = is_array($item) ? $this->canonicalize($item) : $item;
    }
    return $value;
}
```

- [ ] **Step 4: Run the focused test and confirm green**

Expected: `OK (2 tests)`.

- [ ] **Step 5: Commit**

```bash
git add app/code/Weline/Product/Sample/HanfuCleanup/HanfuTestCatalogSelection.php \
  app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuTestCatalogSelectionTest.php
git commit -m "test: 冻结汉服测试商品清理选择"
```

---

### Task 2: Add bounded purge primitives for product-shard dependencies

**Files:**

- Modify: `app/code/Weline/Product/Repository/ProductRepository.php`
- Modify: `app/code/Weline/Product/Repository/OfferRepository.php`
- Modify: `app/code/Weline/Product/Repository/PriceRepository.php`
- Modify: `app/code/Weline/Product/Repository/StoreOfferRepository.php`
- Modify: `app/code/Weline/Product/Repository/StoreProductRepository.php`
- Modify: `app/code/Weline/Product/Repository/CategoryLinkRepository.php`
- Modify: `app/code/Weline/Product/Repository/MediaRepository.php`
- Create: `app/code/Weline/Product/Test/Unit/Repository/HanfuCatalogPurgeRepositoryTest.php`

**Interfaces:**

```php
// ProductRepository
public function deleteByIds(int $websiteId, array $productIds): int;

// OfferRepository
/** @return array{deleted:int,offer_ids:list<int>,offer_uuids:list<string>} */
public function deleteByProductIds(int $websiteId, array $productIds): array;

// PriceRepository
public function purgeOfferIds(int $websiteId, array $offerIds): int;

// StoreOfferRepository
public function purgeOfferIds(int $websiteId, array $offerIds): int;

// StoreProductRepository
public function purgeProductIds(int $websiteId, array $productIds): int;

// CategoryLinkRepository
public function purgeProductIds(int $websiteId, array $productIds): int;

// MediaRepository
public function countByBlobKey(int $websiteId, string $blobKey): int;
```

- [ ] **Step 1: Write failing repository tests**

Cover empty ID arrays as no-ops, ID normalization, `website_id < 0` rejection, exact `IN` predicates, returned row counts, offer IDs captured before deletion, and cache invalidation after store/category removals. Add a media case proving `countByBlobKey` distinguishes shared count `2` from exclusive count `1`.

```php
public function testOfferPurgeReturnsIdentityBeforeDeletingOnlySelectedProducts(): void
{
    $repository = $this->offerRepository([
        ['offer_id' => 91, 'product_id' => 1, 'global_offer_uuid' => 'uuid-91'],
        ['offer_id' => 92, 'product_id' => 2, 'global_offer_uuid' => 'uuid-92'],
        ['offer_id' => 93, 'product_id' => 99, 'global_offer_uuid' => 'uuid-93'],
    ]);

    $result = $repository->deleteByProductIds(0, [2, 1, 2, -1]);

    self::assertSame(2, $result['deleted']);
    self::assertSame([91, 92], $result['offer_ids']);
    self::assertSame(['uuid-91', 'uuid-92'], $result['offer_uuids']);
    self::assertSame([93], $this->remainingOfferIds());
}

public function testSharedBlobCountIsNotExclusive(): void
{
    $repository = $this->mediaRepository([
        ['media_id' => 1, 'blob_key' => 'shared-key'],
        ['media_id' => 2, 'blob_key' => 'shared-key'],
    ]);

    self::assertSame(2, $repository->countByBlobKey(0, 'shared-key'));
}
```

- [ ] **Step 2: Run the focused test and confirm red**

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Repository/HanfuCatalogPurgeRepositoryTest.php
```

Expected: failures for the seven missing methods.

- [ ] **Step 3: Implement the minimum repository methods**

Use each repository's existing website-shard model factory. Normalize with `array_map('intval')`, remove non-positive IDs, sort and deduplicate. Do not accept SQL fragments from callers. `OfferRepository::deleteByProductIds()` loads rows first, then deletes them. `MediaRepository::remove()` remains the only media-row deletion path so blob ownership/ref-count invariants stay intact.

```php
public function deleteByProductIds(int $websiteId, array $productIds): array
{
    $this->assertWebsite($websiteId);
    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn(int $id): bool => $id > 0,
    )));
    sort($productIds, SORT_NUMERIC);
    if ($productIds === []) {
        return ['deleted' => 0, 'offer_ids' => [], 'offer_uuids' => []];
    }
    $rows = $this->listByProductIds($websiteId, $productIds);
    $offerIds = array_map(static fn(array $row): int => (int)$row['offer_id'], $rows);
    $offerUuids = array_values(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['global_offer_uuid'] ?? '')),
        $rows,
    )));
    $this->newModel($websiteId)->clear()
        ->where(Offer::schema_fields_PRODUCT_ID, $productIds, 'IN')
        ->delete()
        ->fetch();
    return ['deleted' => count($rows), 'offer_ids' => $offerIds, 'offer_uuids' => $offerUuids];
}

public function countByBlobKey(int $websiteId, string $blobKey): int
{
    $this->assertWebsite($websiteId);
    $blobKey = trim($blobKey);
    if ($blobKey === '') {
        return 0;
    }
    return count($this->newModel($websiteId)->clear()
        ->where(Media::schema_fields_BLOB_KEY, $blobKey)
        ->select()
        ->fetchArray());
}
```

The other bounded methods use these exact fields: `Product::schema_fields_ID`, `Price::schema_fields_OFFER_ID`, `StoreOffer::schema_fields_OFFER_ID`, `StoreProduct::schema_fields_PRODUCT_ID` and `CategoryLink::schema_fields_PRODUCT_ID`. Each counts selected rows before deletion and returns that count.

- [ ] **Step 4: Run focused and existing repository tests**

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Repository/HanfuCatalogPurgeRepositoryTest.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Service/ProductAdminReadServiceCategoryCatalogContractTest.php
```

Expected: both commands exit `0`.

- [ ] **Step 5: Commit**

```bash
git add app/code/Weline/Product/Repository/ProductRepository.php \
  app/code/Weline/Product/Repository/OfferRepository.php \
  app/code/Weline/Product/Repository/PriceRepository.php \
  app/code/Weline/Product/Repository/StoreOfferRepository.php \
  app/code/Weline/Product/Repository/StoreProductRepository.php \
  app/code/Weline/Product/Repository/CategoryLinkRepository.php \
  app/code/Weline/Product/Repository/MediaRepository.php \
  app/code/Weline/Product/Test/Unit/Repository/HanfuCatalogPurgeRepositoryTest.php
git commit -m "feat: 增加测试商品依赖清理仓储能力"
```

---

### Task 3: Guard and purge inventory plus unreferenced identities

**Files:**

- Create: `app/code/Weline/Inventory/Api/InventoryCatalogMaintenanceInterface.php`
- Modify: `app/code/Weline/Inventory/Service/InventoryService.php`
- Create: `app/code/Weline/Inventory/Test/Unit/Service/InventoryCatalogPurgeTest.php`
- Create: `app/code/Weline/Product/Sample/HanfuCleanup/HanfuIdentityCleanupService.php`
- Create: `app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuIdentityCleanupServiceTest.php`

**Interfaces:**

```php
// Weline\Inventory\Api\InventoryCatalogMaintenanceInterface
interface InventoryCatalogMaintenanceInterface
{
/** @return array{stock_items:int,reservations:int,ledger_events:int,protected_references:list<array<string,mixed>>} */
public function previewCatalogPurge(int $websiteId, array $offerIds): array;

/** @return array{stock_items:int,reservations:int,ledger_events:int} */
public function purgeCatalogOffers(int $websiteId, array $offerIds): array;
}

// InventoryService implements InventoryCatalogMaintenanceInterface
private function deleteStockItems(int $websiteId, array $offerIds): int;
private function deleteReservations(int $websiteId, array $offerIds): int;

// HanfuIdentityCleanupService
/** @return array{product_registry:int,offer_registry:int,sku_registry:int,protected_references:list<array<string,mixed>>} */
public function preview(array $productUuids, array $offerUuids): array;

/** @return array{product_registry:int,offer_registry:int,sku_registry:int} */
public function purgeUnreferenced(array $productUuids, array $offerUuids): array;
private function deleteExactRegistryRows(array $productUuids, array $offerUuids): array;
```

- [ ] **Step 1: Write failing inventory tests**

Prove preview reports order/audit references without writing, apply refuses when protected references exist, and purge only targets exact offer IDs. A fixture with offer `9002` outside the input remains unchanged.

```php
public function testProtectedOrderReferencePreventsInventoryPurge(): void
{
    $service = $this->inventoryWithOrderReference(0, 9001, 'ORDER-1');

    $preview = $service->previewCatalogPurge(0, [9001]);
    self::assertSame('ORDER-1', $preview['protected_references'][0]['reference_id']);

    $this->expectExceptionMessage('hanfu_cleanup_protected_reference');
    $service->purgeCatalogOffers(0, [9001]);
}
```

- [ ] **Step 2: Write failing identity tests**

Prove a UUID referenced by another website remains protected, while a UUID referenced only by a frozen local product/offer can be removed from product identity, offer identity and SKU registries.

```php
public function testCrossWebsiteIdentityIsPreserved(): void
{
    $service = $this->identityServiceWithReferences([
        ['website_id' => 0, 'product_uuid' => 'product-a'],
        ['website_id' => 2, 'product_uuid' => 'product-a'],
    ]);

    $preview = $service->preview(['product-a'], []);
    self::assertSame('product-a', $preview['protected_references'][0]['uuid']);
    self::assertSame(0, $service->purgeUnreferenced(['product-a'], [])['product_registry']);
}
```

- [ ] **Step 3: Run both tests and confirm red**

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Inventory/Test/Unit/bootstrap.php app/code/Weline/Inventory/Test/Unit/Service/InventoryCatalogPurgeTest.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuIdentityCleanupServiceTest.php
```

- [ ] **Step 4: Implement guarded purge behavior**

The preview is authoritative. `InventoryService` implements `InventoryCatalogMaintenanceInterface`; `HanfuCatalogCleanupService` constructor-hints only the interface. `purgeCatalogOffers()` re-runs protected-reference queries immediately before deletion and throws `hanfu_cleanup_protected_reference` if any result exists. Identity deletion happens only after all website shards show zero remaining product/offer references. Do not delete order or audit rows.

```php
public function purgeCatalogOffers(int $websiteId, array $offerIds): array
{
    $preview = $this->previewCatalogPurge($websiteId, $offerIds);
    if ($preview['protected_references'] !== []) {
        throw new \RuntimeException('hanfu_cleanup_protected_reference');
    }
    return $this->transactions->run($this->connectionFactory, function () use ($websiteId, $offerIds): array {
        return [
            'stock_items' => $this->deleteStockItems($websiteId, $offerIds),
            'reservations' => $this->deleteReservations($websiteId, $offerIds),
            'ledger_events' => 0,
        ];
    });
}

public function purgeUnreferenced(array $productUuids, array $offerUuids): array
{
    $preview = $this->preview($productUuids, $offerUuids);
    $protected = array_fill_keys(array_column($preview['protected_references'], 'uuid'), true);
    return $this->deleteExactRegistryRows(
        array_values(array_filter($productUuids, static fn(string $uuid): bool => !isset($protected[$uuid]))),
        array_values(array_filter($offerUuids, static fn(string $uuid): bool => !isset($protected[$uuid]))),
    );
}
```

Any inventory ledger event is reported as a protected audit reference by `previewCatalogPurge()`; the purge method never deletes it.

- [ ] **Step 5: Run focused and existing identity tests**

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Inventory/Test/Unit/bootstrap.php app/code/Weline/Inventory/Test/Unit/Service/InventoryCatalogPurgeTest.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuIdentityCleanupServiceTest.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Model/ProductShardRegistryTest.php
```

Expected: all commands exit `0`.

- [ ] **Step 6: Commit**

```bash
git add app/code/Weline/Inventory/Api/InventoryCatalogMaintenanceInterface.php \
  app/code/Weline/Inventory/Service/InventoryService.php \
  app/code/Weline/Inventory/Test/Unit/Service/InventoryCatalogPurgeTest.php \
  app/code/Weline/Product/Sample/HanfuCleanup/HanfuIdentityCleanupService.php \
  app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuIdentityCleanupServiceTest.php
git commit -m "feat: 清理测试商品库存与孤立身份"
```

---

### Task 4: Quarantine only exclusive media objects

**Files:**

- Create: `app/code/Weline/Product/Sample/HanfuCleanup/HanfuCatalogMediaQuarantine.php`
- Create: `app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuCatalogMediaQuarantineTest.php`

**Interfaces:**

```php
final class HanfuCatalogMediaQuarantine
{
    /** @param list<array<string,mixed>> $mediaRows */
    public function plan(string $runId, array $mediaRows, array $referenceIndex): array;

    /** @param array<string,mixed> $manifest */
    public function quarantine(array $manifest): array;

    /** @param array<string,mixed> $manifest */
    public function restore(array $manifest): array;

    /** @param array<string,mixed> $manifest */
    public function finalize(array $manifest): array;
}
```

- [ ] **Step 1: Write failing quarantine tests**

Cover shared `blob_key` preservation; protected `catalog/hanfu-entities/` and `catalog/hanfu/r2/categories/` prefixes; homepage/content/config references; an exclusive local object moved to `hanfu-1688/<run-id>/quarantine/<sha256>/<basename>`; reverse-order restore after a move failure; finalization limited to successfully quarantined keys; and rejection of traversal, absolute paths, unknown disks, symlink escape and mismatched digest.

```php
public function testOnlyExclusiveUnreferencedObjectIsQuarantined(): void
{
    $manifest = $this->quarantine->plan('cleanup-20260902', [
        ['media_id' => 1, 'disk_code' => 'media', 'object_key' => 'catalog/hanfu/test/a.jpg', 'blob_key' => 'a'],
        ['media_id' => 2, 'disk_code' => 'media', 'object_key' => 'catalog/hanfu/r2/categories/a.jpg', 'blob_key' => 'b'],
    ], [
        'a' => ['media_count' => 1, 'content_references' => 0],
        'b' => ['media_count' => 1, 'content_references' => 0],
    ]);

    self::assertSame(['catalog/hanfu/test/a.jpg'], array_column($manifest['moves'], 'from'));
    self::assertSame(
        ['catalog/hanfu/r2/categories/a.jpg'],
        array_column($manifest['preserved'], 'object_key'),
    );
}
```

- [ ] **Step 2: Run the focused test and confirm red**

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuCatalogMediaQuarantineTest.php
```

- [ ] **Step 3: Implement with the existing FileManager API**

Use `FileAssetLibraryInterface::describe()` before every move, `moveObject()` for quarantine/restore and `deleteObject()` for finalization. Create `FileAccessContext` with the maintenance actor and record disk, original key, quarantine key, content hash and revision. Never call `deleteDirectory()`.

```php
public function quarantine(array $manifest): array
{
    $completed = [];
    try {
        foreach ($manifest['moves'] as $move) {
            $asset = $this->files->describe($move['disk_code'], $move['from'], $this->access);
            if ($asset === null || !hash_equals($move['sha256'], (string)$asset['sha256'])) {
                throw new \RuntimeException('hanfu_cleanup_media_digest_drift');
            }
            $this->files->moveObject($move['disk_code'], $move['from'], $move['to'], $this->access);
            $completed[] = $move;
        }
        $manifest['completed'] = $completed;
        $manifest['status'] = 'quarantined';
        return $manifest;
    } catch (\Throwable $exception) {
        foreach (array_reverse($completed) as $move) {
            $this->files->moveObject($move['disk_code'], $move['to'], $move['from'], $this->access);
        }
        throw $exception;
    }
}
```

- [ ] **Step 4: Run focused tests**

Expected: all quarantine cases pass and unit tests touch no real files.

- [ ] **Step 5: Commit**

```bash
git add app/code/Weline/Product/Sample/HanfuCleanup/HanfuCatalogMediaQuarantine.php \
  app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuCatalogMediaQuarantineTest.php
git commit -m "feat: 隔离测试商品独占媒体"
```

---

### Task 5: Orchestrate dry-run, apply and verify

**Files:**

- Create: `app/code/Weline/Product/Sample/HanfuCleanup/HanfuCatalogCleanupService.php`
- Create: `app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuCatalogCleanupServiceTest.php`

**Interfaces:**

```php
final class HanfuCatalogCleanupService
{
    /** @return array<string,mixed> */
    public function preview(int $websiteId, string $runId): array;

    /** @return array<string,mixed> */
    public function apply(int $websiteId, string $runId, string $selectionDigest): array;

    /** @return array<string,mixed> */
    public function verify(int $websiteId, string $runId, string $selectionDigest): array;

    private function writeRunArtifact(string $runId, string $name, array $document): string;
    private function readRunArtifact(string $runId, string $name): array;
    private function assertTargetWebsite(int $websiteId): void;
    private function loadExactProducts(int $websiteId, array $productIds): array;
    private function buildSnapshot(int $websiteId, array $products): array;
    private function deleteDependenciesInDeclaredOrder(int $websiteId, array $snapshot): array;
    private function postconditions(int $websiteId, array $selection): array;
    private function report(string $runId, string $selectionDigest, array $deleted, array $quarantined): array;
}
```

- [ ] **Step 1: Write the failing service tests**

Assert preview selects exactly 29 existing IDs and fails with `hanfu_cleanup_selection_incomplete` if one is absent; captures dynamic baseline IDs for categories, brands, suppliers and supplier-brand links; apply rejects digest drift before file movement; protected references reject writes; quarantine precedes the database transaction; deletion order is prices/store-offers/inventory/offer attributes/offers/category links/store-products/media/product attributes/products/unreferenced identities; database failure restores quarantine; verification requires zero frozen rows and unchanged preservation snapshot; cache/search invalidation runs once.

```php
public function testDigestDriftPreventsEveryMutation(): void
{
    $service = $this->serviceWithFrozenCatalog();
    $selection = $service->preview(0, 'cleanup-20260902');
    $this->catalog->renameProduct(45, 'drifted-name');

    $this->expectExceptionMessage('hanfu_cleanup_selection_drift');
    try {
        $service->apply(0, 'cleanup-20260902', $selection['selection_digest']);
    } finally {
        self::assertSame([], $this->fileLibrary->moves);
        self::assertSame([], $this->database->deleteStatements);
    }
}

public function testDatabaseFailureRestoresQuarantine(): void
{
    $service = $this->serviceThatFailsAfterQuarantine();
    $selection = $service->preview(0, 'cleanup-20260902');

    try {
        $service->apply(0, 'cleanup-20260902', $selection['selection_digest']);
        self::fail('database failure was not surfaced');
    } catch (\RuntimeException $exception) {
        self::assertSame('fixture_database_failure', $exception->getMessage());
    }
    self::assertSame($this->fileLibrary->movedOut, $this->fileLibrary->restored);
}
```

- [ ] **Step 2: Run the focused test and confirm red**

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuCatalogCleanupServiceTest.php
```

- [ ] **Step 3: Implement preview**

Use `ProductRepository::listAll(0)` and exact ID membership. Load offers through `OfferRepository::listByProductIds()`, media through `MediaRepository::listByProductIds()`, EAV rows through `AttributeValueRepository` and store/category dependencies through their repositories. Sort all rows before digesting. Write no database or media state.

```php
public function preview(int $websiteId, string $runId): array
{
    $this->assertTargetWebsite($websiteId);
    $productIds = $this->selection->productIds();
    $products = $this->loadExactProducts($websiteId, $productIds);
    if (array_column($products, 'product_id') !== $productIds) {
        throw new \RuntimeException('hanfu_cleanup_selection_incomplete');
    }
    $snapshot = $this->buildSnapshot($websiteId, $products);
    $snapshot['selection_digest'] = $this->selection->digest($snapshot);
    $this->writeRunArtifact($runId, 'cleanup-selection.json', $snapshot);
    return $snapshot;
}
```

- [ ] **Step 4: Implement apply**

Recompute and compare with `hash_equals()`. Quarantine planned file objects, then run repository deletes inside the website connection transaction. On exception, roll back and restore. After commit, invalidate `StorefrontCatalogCacheCoordinator` and search projection once.

```php
public function apply(int $websiteId, string $runId, string $selectionDigest): array
{
    $fresh = $this->buildSnapshot($websiteId, $this->loadExactProducts($websiteId, $this->selection->productIds()));
    if (!hash_equals($selectionDigest, $this->selection->digest($fresh))) {
        throw new \RuntimeException('hanfu_cleanup_selection_drift');
    }
    $quarantined = $this->mediaQuarantine->quarantine($fresh['quarantine_manifest']);
    $this->writeRunArtifact($runId, 'quarantine-manifest.json', $quarantined);
    try {
        $deleted = $this->transactions->run(
            $this->connectionFactory,
            fn(): array => $this->deleteDependenciesInDeclaredOrder($websiteId, $fresh),
        );
    } catch (\Throwable $exception) {
        $this->mediaQuarantine->restore($quarantined);
        throw $exception;
    }
    $this->catalogCache->notifyCatalogChanged($websiteId, 'hanfu_test_catalog_cleanup', ['product_ids' => $this->selection->productIds()]);
    return $this->report($runId, $selectionDigest, $deleted, $quarantined);
}
```

- [ ] **Step 5: Implement verify**

Compare live preservation IDs with the dry-run snapshot. Finalize quarantine only when every database postcondition passes. If finalization fails, emit `status=database_clean_files_pending` and retain a recoverable manifest.

```php
public function verify(int $websiteId, string $runId, string $selectionDigest): array
{
    $selection = $this->readRunArtifact($runId, 'cleanup-selection.json');
    if (!hash_equals($selectionDigest, (string)$selection['selection_digest'])) {
        throw new \RuntimeException('hanfu_cleanup_digest_mismatch');
    }
    $postconditions = $this->postconditions($websiteId, $selection);
    if (!$postconditions['passed']) {
        throw new \RuntimeException('hanfu_cleanup_postcondition_failed');
    }
    $files = $this->mediaQuarantine->finalize($this->readRunArtifact($runId, 'quarantine-manifest.json'));
    return ['status' => 'verified', 'postconditions' => $postconditions, 'files' => $files];
}
```

`writeRunArtifact()` accepts only `cleanup-selection.json`, `quarantine-manifest.json`, `cleanup-report.json` and `verification.json`, writes a `0600` temporary sibling and atomically renames it. `readRunArtifact()` rejects traversal and verifies the stored contract before returning the array.

- [ ] **Step 6: Run focused and adjacent tests**

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Repository/HanfuCatalogPurgeRepositoryTest.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Service/ProductAdminReadServiceCategoryCatalogContractTest.php
```

Expected: all commands exit `0`.

- [ ] **Step 7: Commit**

```bash
git add app/code/Weline/Product/Sample/HanfuCleanup/HanfuCatalogCleanupService.php \
  app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup/HanfuCatalogCleanupServiceTest.php
git commit -m "feat: 编排汉服测试目录安全清理"
```

---

### Task 6: Add the guarded CLI and report writer

**Files:**

- Create: `app/code/Weline/Product/scripts/cleanup-hanfu-test-catalog.php`
- Create: `app/code/Weline/Product/Test/Unit/Script/HanfuTestCatalogCleanupScriptContractTest.php`

**Interfaces:**

The script accepts exactly one of `--dry-run`, `--apply=<digest>` or `--verify=<digest>`, requires `--website=0` and a run ID matching `^[a-z0-9][a-z0-9-]{2,63}$`, writes JSON through a temporary sibling plus atomic rename and never prints credentials.

- [ ] **Step 1: Write the failing script behavior test**

Execute the real PHP script as a subprocess. Assert `--help` returns the three supported modes and exit-code map as JSON, a non-zero website exits `2` with `website_id_zero_required`, conflicting modes exit `2`, stdout contains no partial success record, and stderr contains no credential-shaped data. Service orchestration and atomic report writes are asserted through the real `HanfuCatalogCleanupService` tests, not by reading script source.

```php
public function testScriptRejectsNonZeroWebsiteBeforeBootstrappingApplication(): void
{
    $process = $this->runScript(['--website=1', '--dry-run', '--run-id=cleanup-test']);

    self::assertSame(2, $process->exitCode);
    self::assertSame('', $process->stdout);
    self::assertStringContainsString('website_id_zero_required', $process->stderr);
}
```

- [ ] **Step 2: Run the focused test and confirm red**

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Script/HanfuTestCatalogCleanupScriptContractTest.php
```

- [ ] **Step 3: Implement the CLI**

Print one JSON object to stdout with `contract`, `run_id`, `mode`, `status`, `selection_digest` and `report_path`. Send diagnostic text to stderr. Runtime reports use `0600` permissions.

```php
$mode = match (true) {
    array_key_exists('dry-run', $options) => 'dry-run',
    array_key_exists('apply', $options) => 'apply',
    array_key_exists('verify', $options) => 'verify',
    default => throw new \InvalidArgumentException('hanfu_cleanup_mode_required'),
};
if (count(array_intersect(['dry-run', 'apply', 'verify'], array_keys($options))) !== 1) {
    throw new \InvalidArgumentException('hanfu_cleanup_mode_conflict');
}
$result = match ($mode) {
    'dry-run' => $service->preview(0, $runId),
    'apply' => $service->apply(0, $runId, (string)$options['apply']),
    'verify' => $service->verify(0, $runId, (string)$options['verify']),
};
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
```

- [ ] **Step 4: Run lint and focused tests**

```bash
php -l app/code/Weline/Product/scripts/cleanup-hanfu-test-catalog.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Script/HanfuTestCatalogCleanupScriptContractTest.php
```

Expected: lint has no syntax errors and PHPUnit exits `0`.

- [ ] **Step 5: Commit**

```bash
git add app/code/Weline/Product/scripts/cleanup-hanfu-test-catalog.php \
  app/code/Weline/Product/Test/Unit/Script/HanfuTestCatalogCleanupScriptContractTest.php
git commit -m "feat: 增加汉服测试目录清理命令"
```

---

### Task 7: Prove dry-run against the configured runtime

**Files:**

- Runtime artifact only: `var/hanfu-1688/cleanup-20260902/cleanup-selection.json`
- Runtime artifact only: `var/hanfu-1688/cleanup-20260902/quarantine-manifest.json`

**Interfaces:**

- Consumes: `HanfuCatalogCleanupService::preview(0, 'cleanup-20260902')` and the configured runtime repositories.
- Produces: immutable `hanfu.cleanup.selection.v1` selection and quarantine manifests with the apply digest.

- [ ] **Step 1: Run all scoped tests**

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Sample/HanfuCleanup
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Repository/HanfuCatalogPurgeRepositoryTest.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Script/HanfuTestCatalogCleanupScriptContractTest.php
php vendor/bin/phpunit --bootstrap app/code/Weline/Inventory/Test/Unit/bootstrap.php app/code/Weline/Inventory/Test/Unit/Service/InventoryCatalogPurgeTest.php
```

Expected: every command exits `0`.

- [ ] **Step 2: Run the real dry-run**

```bash
php app/code/Weline/Product/scripts/cleanup-hanfu-test-catalog.php \
  --website=0 --dry-run --run-id=cleanup-20260902
```

Expected JSON: `status=ready`, `selected_product_count=29`, `protected_reference_count=0`, a 64-character digest and no database/file mutation.

- [ ] **Step 3: Independently verify the artifact**

Use existing repositories read-only to prove all 29 IDs still exist after dry-run. Record category, brand, supplier and supplier-brand ID lists, not only counts. Verify protected directories are absent from the quarantine manifest.

- [ ] **Step 4: Stop on mismatch**

Do not broaden the selector to fit drift. Repair the concrete code/data defect, rerun its focused test and create a new dry-run/run ID.

---

### Task 8: Execute the approved cleanup and verify the real backend

**Files:**

- Runtime artifact only: `var/hanfu-1688/cleanup-20260902/cleanup-report.json`
- Runtime artifact only: `var/hanfu-1688/cleanup-20260902/verification.json`

**Interfaces:**

- Consumes: the exact `selection_digest` and manifests accepted in Task 7.
- Produces: `hanfu.cleanup.report.v1` with `status=verified` and browser evidence required by the import plan.

- [ ] **Step 1: Apply with the exact dry-run digest**

```bash
SELECTION_DIGEST="$(php -r '$d=json_decode(file_get_contents("var/hanfu-1688/cleanup-20260902/cleanup-selection.json"),true,512,JSON_THROW_ON_ERROR); echo $d["selection_digest"];')"
php app/code/Weline/Product/scripts/cleanup-hanfu-test-catalog.php \
  --website=0 --apply="$SELECTION_DIGEST" --run-id=cleanup-20260902
```

Expected: `status=database_clean` or `status=database_clean_files_pending`; never continue to import on the latter status.

- [ ] **Step 2: Run explicit verification**

```bash
php app/code/Weline/Product/scripts/cleanup-hanfu-test-catalog.php \
  --website=0 --verify="$SELECTION_DIGEST" --run-id=cleanup-20260902
```

Expected: `remaining_product_count=0`, dependent counts `0`, preserved ID snapshots unchanged, quarantine finalized and `status=verified`.

- [ ] **Step 3: Run the configured application**

Use the configured Weline runtime. Exercise product and supplier lists through the real application, not only direct table queries.

- [ ] **Step 4: Verify in the signed-in in-app browser**

Load `browser:control-in-app-browser`. Open the local backend product catalog for `website_id=0`, confirm zero rows before import, then confirm categories, brands and suppliers remain accessible. Capture browser evidence in `verification.json`. Do not use Chrome for this acceptance path.

- [ ] **Step 5: Record delivery URLs**

Record exact product, category, brand and supplier backend URLs from project delivery guidance. A browser error, redirect loop or stale cached product is a failed acceptance.

---

### Task 9: Align documentation and run closeout checks

**Files:**

- Modify: `app/code/Weline/Product/doc/需求.md`
- Modify: `app/code/Weline/Product/doc/开发日志.md`
- Modify: `app/code/Weline/Inventory/doc/需求.md`
- Modify: `app/code/Weline/Inventory/doc/开发日志.md`

**Interfaces:**

- Consumes: the implemented CLI contracts and observed cleanup/verification artifacts.
- Produces: durable operator requirements and execution history used for future maintenance.

- [ ] **Step 1: Document the maintenance contract**

Describe frozen IDs, dry-run digest, protected references, dependency order, quarantine/finalization states, runtime artifact paths and commands. State that this is scoped to `website_id=0` and is not a generic truncation tool.

- [ ] **Step 2: Run documentation and diff checks**

```bash
rg -n "hanfu.cleanup.selection.v1|hanfu.cleanup.report.v1|selection_digest" \
  app/code/Weline/Product/doc app/code/Weline/Inventory/doc
git diff --check
```

Expected: all contract terms are documented and `git diff --check` emits no output.

- [ ] **Step 3: Commit**

```bash
git add app/code/Weline/Product/doc/需求.md \
  app/code/Weline/Product/doc/开发日志.md \
  app/code/Weline/Inventory/doc/需求.md \
  app/code/Weline/Inventory/doc/开发日志.md
git commit -m "docs: 记录汉服测试目录清理契约"
```

- [ ] **Step 4: Final cleanup-plan acceptance**

Confirm no `generated/` changes, no unrelated Blog/untracked files, no generic delete-all path and no credential access. Run Weline task-plan review and require `closeout_allowed=true` before reporting cleanup complete.

---

## Spec Coverage Matrix

| Design section | Plan coverage |
|---|---|
| 1 confirmed decisions | Frozen Runtime Contract, Tasks 7-8 |
| 2.1 cleanup scope | Global Constraints, Tasks 1 and 5 |
| 2.3 non-goals | Global Constraints, Tasks 3, 6 and 9 |
| 3 invariants | Tasks 2-5 and 8 |
| 4 solution choice | Three-phase CLI and quarantine architecture |
| 5.1 cleanup entry | Tasks 5-6 |
| 6 data flow | Tasks 5, 7 and 8 |
| 7 runtime artifacts | Frozen Runtime Contract, Tasks 6-8 |
| 8 error and recovery | Tasks 3-5 |
| 9 tests and acceptance | Every task, especially Tasks 7-8 |
| 10 delivery and safety | Global Constraints, Tasks 8-9 |
