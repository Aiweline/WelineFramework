<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Api\InventoryCatalogCopyCapability;
use Weline\Inventory\Service\InventoryService;
use Weline\Product\Api\Data\CopyDraft;
use Weline\Product\Service\ProductCopyService;

/**
 * TEST-P2C-COPY-01～05.
 */
/**
 * TEST-P2C-COPY-04: cross-Website copies create target catalog/overlay facts without lifting source overrides.
 * TEST-MIG-P2-05: inventory defaults to zero and only copies quantity when explicitly selected.
 */
final class ProductCopyServiceTest extends TestCase
{
    public function testThreeEntriesShareServiceAndExistingStoreCanSupplement(): void
    {
        $svc = ProductCopyService::forTesting();
        $this->seedCatalog($svc);

        $blank = new CopyDraft();
        $blank->entry = CopyDraft::ENTRY_BLANK;
        $blank->targetWebsiteId = 0;
        $blank->targetStoreId = 2;
        $d1 = $svc->createDraft($blank);
        $p1 = $svc->preview($d1->draftId);
        self::assertSame(0, $p1->productCount);
        $c1 = $svc->commit($d1->draftId, hash('sha256', 'blank'));
        self::assertTrue($c1->success);

        $pull = new CopyDraft();
        $pull->entry = CopyDraft::ENTRY_SITE_PULL;
        $pull->targetWebsiteId = 0;
        $pull->targetStoreId = 2;
        $pull->sourceWebsiteId = 0;
        $pull->categoryIds = [10];
        $pull->includeProducts = true;
        $d2 = $svc->createDraft($pull);
        $p2 = $svc->preview($d2->draftId);
        self::assertGreaterThan(0, $p2->productCount);
        $c2 = $svc->commit($d2->draftId, hash('sha256', 'pull'));
        self::assertTrue($c2->success);
        self::assertTrue($svc->isStoreProductSelected(0, 2, 100));

        $inherit = new CopyDraft();
        $inherit->entry = CopyDraft::ENTRY_STORE_INHERIT;
        $inherit->targetWebsiteId = 0;
        $inherit->targetStoreId = 3;
        $inherit->sourceWebsiteId = 0;
        $inherit->sourceStoreId = 2;
        $inherit->categoryIds = [10];
        $d3 = $svc->createDraft($inherit);
        $c3 = $svc->commit($d3->draftId, hash('sha256', 'inherit'));
        self::assertTrue($c3->success);
        self::assertTrue($svc->isStoreProductSelected(0, 3, 100));
    }

    public function testCategoryTreeExcludeChildAndIncludeProductsToggle(): void
    {
        $svc = ProductCopyService::forTesting();
        $svc->seedCategory(0, 1, null, 'Parent');
        $svc->seedCategory(0, 2, 1, 'Child');
        $svc->seedProduct(0, 11, 'P-PARENT', 'Parent Product', offerId: 111);
        $svc->seedProduct(0, 12, 'P-CHILD', 'Child Product', offerId: 112);
        $svc->seedLink(0, 1, 11);
        $svc->seedLink(0, 2, 12);

        $draft = new CopyDraft();
        $draft->entry = CopyDraft::ENTRY_SITE_PULL;
        $draft->targetWebsiteId = 0;
        $draft->targetStoreId = 5;
        $draft->sourceWebsiteId = 0;
        $draft->categoryIds = [1];
        $draft->excludedCategoryIds = [2];
        $draft->includeProducts = true;
        $d = $svc->createDraft($draft);
        $preview = $svc->preview($d->draftId);
        $skus = array_column($preview->items, 'sku');
        self::assertContains('P-PARENT', $skus);
        self::assertNotContains('P-CHILD', $skus);

        $svc->commit($d->draftId, hash('sha256', 'tree'));
        self::assertTrue($svc->isStoreProductSelected(0, 5, 11));
        // 排除子类后：12 不在本次复制集合；Website 无 overlay 时 isSelected 仍可能继承为 true
        self::assertContains(11, $svc->listExplicitStoreProducts(0, 5));
        self::assertNotContains(12, $svc->listExplicitStoreProducts(0, 5));
        self::assertSame([11], $svc->listCategoryLinks(0, 1));

        // includeProducts OFF → categories only, no product selection
        $draft2 = new CopyDraft();
        $draft2->entry = CopyDraft::ENTRY_SITE_PULL;
        $draft2->targetWebsiteId = 0;
        $draft2->targetStoreId = 6;
        $draft2->sourceWebsiteId = 0;
        $draft2->categoryIds = [1];
        $draft2->includeProducts = false;
        $d2 = $svc->createDraft($draft2);
        $p2 = $svc->preview($d2->draftId);
        self::assertSame(0, $p2->productCount);
        self::assertGreaterThan(0, $p2->categoryCount);
    }

    public function testInventoryDefaultsZeroUnlessExplicitCopyQty(): void
    {
        $inv = InventoryService::forTesting();
        $svc = ProductCopyService::forTesting(new InventoryCatalogCopyCapability($inv));
        $this->seedCatalog($svc);
        $inv->setOnHand(0, 0, 200, 7, 'src-stock', hash('sha256', 'src-stock'));

        $d1 = new CopyDraft();
        $d1->entry = CopyDraft::ENTRY_SITE_PULL;
        $d1->targetWebsiteId = 0;
        $d1->targetStoreId = 8;
        $d1->sourceWebsiteId = 0;
        $d1->categoryIds = [10];
        $d1->fieldPackages = [CopyDraft::PKG_IDENTITY, CopyDraft::PKG_INVENTORY];
        $d1->inventoryCopyQty = false;
        $a = $svc->createDraft($d1);
        self::assertFalse($svc->preview($a->draftId)->inventoryWillCopyQty);
        $svc->commit($a->draftId, hash('sha256', 'inv0'));
        self::assertSame(0, $inv->getAvailability(0, 8, 200)->onHandMinor);

        $d2 = new CopyDraft();
        $d2->entry = CopyDraft::ENTRY_SITE_PULL;
        $d2->targetWebsiteId = 0;
        $d2->targetStoreId = 9;
        $d2->sourceWebsiteId = 0;
        $d2->categoryIds = [10];
        $d2->fieldPackages = [CopyDraft::PKG_IDENTITY, CopyDraft::PKG_INVENTORY];
        $d2->inventoryCopyQty = true;
        $b = $svc->createDraft($d2);
        self::assertTrue($svc->preview($b->draftId)->inventoryWillCopyQty);
        $svc->commit($b->draftId, hash('sha256', 'inv7'));
        self::assertSame(7, $inv->getAvailability(0, 9, 200)->onHandMinor);
    }

    public function testCrossWebsiteCreatesNewCategoryUuidWithoutLiftingSource(): void
    {
        $svc = ProductCopyService::forTesting();
        $svc->seedCategory(0, 1, null, 'Root');
        $svc->seedProduct(0, 50, 'CROSS-1', 'Cross', ['title' => 'A'], offerId: 500, priceMinor: 100);
        $svc->seedLink(0, 1, 50);
        $svc->seedAttrCleared(0, 50, 7, 'title'); // source store overlay cleared — must not lift
        $svc->seedStoreProduct(0, 7, 50, true);
        $beforeSourceAttr = $svc->getAttr(0, 50, 7, 'title');

        $draft = new CopyDraft();
        $draft->entry = CopyDraft::ENTRY_STORE_INHERIT;
        $draft->targetWebsiteId = 1;
        $draft->targetStoreId = 1;
        $draft->sourceWebsiteId = 0;
        $draft->sourceStoreId = 7;
        $draft->categoryIds = [1];
        $draft->fieldPackages = [
            CopyDraft::PKG_IDENTITY,
            CopyDraft::PKG_ATTRS,
            CopyDraft::PKG_PRICE,
            CopyDraft::PKG_MEDIA,
        ];
        $d = $svc->createDraft($draft);
        $result = $svc->commit($d->draftId, hash('sha256', 'cross'));
        self::assertTrue($result->success);
        self::assertGreaterThan(0, $result->counts['categories_created'] ?? 0);
        self::assertGreaterThan(0, $result->counts['products_created'] ?? 0);

        // New category uuid on target website
        $targetCats = [];
        // find non-1 ids on website 1
        self::assertSame(1, $svc->countProducts(0)); // source product count unchanged
        self::assertSame(1, $svc->countProducts(1));
        self::assertSame($beforeSourceAttr, $svc->getAttr(0, 50, 7, 'title'));
        $isolation = array_values(array_filter(
            $result->audit,
            static fn(array $a): bool => ($a['op'] ?? '') === 'cross_website_isolation',
        ));
        self::assertNotEmpty($isolation);
        self::assertFalse($isolation[0]['source_overlay_lifted']);
    }

    public function testDuplicateSkipAndUpdateSelectedFields(): void
    {
        $svc = ProductCopyService::forTesting();
        $this->seedCatalog($svc);

        $mk = static function (int $storeId) {
            $d = new CopyDraft();
            $d->entry = CopyDraft::ENTRY_SITE_PULL;
            $d->targetWebsiteId = 0;
            $d->targetStoreId = $storeId;
            $d->sourceWebsiteId = 0;
            $d->categoryIds = [10];
            $d->fieldPackages = [CopyDraft::PKG_IDENTITY, CopyDraft::PKG_ATTRS];
            return $d;
        };

        $first = $svc->createDraft($mk(11));
        $svc->commit($first->draftId, hash('sha256', 'dup1'));
        self::assertSame(1, $svc->countSourceLinks(0));

        $skipDraft = $mk(11);
        $skipDraft->duplicatePolicy = CopyDraft::POLICY_SKIP;
        $skip = $svc->createDraft($skipDraft);
        $prev = $svc->preview($skip->draftId);
        self::assertSame(1, $prev->skipCount);
        $r1 = $svc->commit($skip->draftId, hash('sha256', 'dup-skip'));
        self::assertTrue($r1->success);
        self::assertSame(1, $r1->counts['products_skipped']);
        self::assertSame(1, $svc->countProducts(0)); // no second projection product

        // mutate source name then update_selected_fields
        $svc->seedProduct(0, 100, 'SKU-100', 'Renamed', ['color' => 'red'], offerId: 200, priceMinor: 50);
        $updDraft = $mk(11);
        $updDraft->duplicatePolicy = CopyDraft::POLICY_UPDATE;
        $updDraft->fieldPackages = [CopyDraft::PKG_IDENTITY]; // attrs not selected → keep old attr
        $upd = $svc->createDraft($updDraft);
        // preserve an existing store attr that should not be deleted
        // (attrs package not selected)
        $r2 = $svc->commit($upd->draftId, hash('sha256', 'dup-upd'));
        self::assertTrue($r2->success);
        self::assertSame(1, $r2->counts['products_updated']);
        self::assertSame('Renamed', $svc->getProduct(0, 100)['name']);
    }

    public function testExcludedCategoryRemovesItsWholeSubtree(): void
    {
        $svc = ProductCopyService::forTesting();
        $svc->seedCategory(0, 1, null, 'Root');
        $svc->seedCategory(0, 2, 1, 'Excluded child');
        $svc->seedCategory(0, 3, 2, 'Excluded grandchild');
        $svc->seedProduct(0, 10, 'ROOT', 'Root product', offerId: 100);
        $svc->seedProduct(0, 20, 'CHILD', 'Child product', offerId: 200);
        $svc->seedProduct(0, 30, 'GRANDCHILD', 'Grandchild product', offerId: 300);
        $svc->seedLink(0, 1, 10);
        $svc->seedLink(0, 2, 20);
        $svc->seedLink(0, 3, 30);

        $draft = new CopyDraft();
        $draft->entry = CopyDraft::ENTRY_SITE_PULL;
        $draft->targetWebsiteId = 0;
        $draft->targetStoreId = 22;
        $draft->categoryIds = [1];
        $draft->excludedCategoryIds = [2];

        $preview = $svc->preview($svc->createDraft($draft)->draftId);
        self::assertSame(['ROOT'], array_column($preview->items, 'sku'));
        self::assertSame(1, $preview->categoryCount);
        self::assertSame(1, $preview->linkCount);
    }

    public function testMultiStoreCommitSelectsAndInitializesEveryTargetStore(): void
    {
        $inventory = InventoryService::forTesting();
        $service = ProductCopyService::forTesting(new InventoryCatalogCopyCapability($inventory));
        $this->seedCatalog($service);

        $draft = new CopyDraft();
        $draft->entry = CopyDraft::ENTRY_SITE_PULL;
        $draft->sourceWebsiteId = 0;
        $draft->targetWebsiteId = 0;
        $draft->targetStoreId = 30;
        $draft->targetStoreIds = [30, 31, 30];
        $draft->categoryIds = [10];
        $draft->fieldPackages = [CopyDraft::PKG_IDENTITY, CopyDraft::PKG_INVENTORY];

        $created = $service->createDraft($draft);
        self::assertSame([30, 31], $created->selectedTargetStoreIds());
        self::assertSame(30, $created->targetStoreId);

        $result = $service->commit(
            $created->draftId,
            hash('sha256', 'memory-multi-store'),
        );
        self::assertTrue($result->success);
        self::assertSame(2, $result->counts['inventory_zeroed']);
        foreach ([30, 31] as $storeId) {
            self::assertSame([100], $service->listExplicitStoreProducts(0, $storeId));
            self::assertSame([200], $service->listExplicitStoreOffers(0, $storeId));
            self::assertSame(0, $inventory->getAvailability(0, $storeId, 200)->onHandMinor);
        }
    }

    public function testStoreInheritFiltersOffersAndCopiesSameWebsitePrice(): void
    {
        $svc = ProductCopyService::forTesting();
        $svc->seedCategory(0, 10, null, 'Default');
        $svc->seedProduct(0, 100, 'SKU-100', 'Product 100', offerId: 200, priceMinor: 50);
        $svc->seedProduct(0, 101, 'SKU-101', 'Product 101', offerId: 201, priceMinor: 70);
        $svc->seedLink(0, 10, 100);
        $svc->seedLink(0, 10, 101);
        $svc->seedStoreProduct(0, 7, 100, true);
        $svc->seedStoreProduct(0, 7, 101, true);
        $svc->seedStoreOffer(0, 7, 200, true);
        $svc->seedStoreOffer(0, 7, 201, false);

        $draft = new CopyDraft();
        $draft->entry = CopyDraft::ENTRY_STORE_INHERIT;
        $draft->sourceWebsiteId = 0;
        $draft->sourceStoreId = 7;
        $draft->targetWebsiteId = 0;
        $draft->targetStoreId = 8;
        $draft->categoryIds = [10];
        $draft->fieldPackages = [CopyDraft::PKG_IDENTITY, CopyDraft::PKG_PRICE];
        $created = $svc->createDraft($draft);

        self::assertSame(1, $svc->preview($created->draftId)->offerCount);
        self::assertTrue($svc->commit($created->draftId, hash('sha256', 'offer-filter'))->success);
        self::assertSame([200], $svc->listExplicitStoreOffers(0, 8));
        self::assertSame(
            ['cleared' => false, 'value' => 50],
            $svc->getPrice(0, 200, 8),
        );
        self::assertNull($svc->getPrice(0, 201, 8));
    }

    public function testCommitReplayIsIdempotentAndRejectsDifferentHash(): void
    {
        $svc = ProductCopyService::forTesting();
        $this->seedCatalog($svc);
        $draft = new CopyDraft();
        $draft->entry = CopyDraft::ENTRY_SITE_PULL;
        $draft->targetWebsiteId = 0;
        $draft->targetStoreId = 23;
        $draft->categoryIds = [10];
        $created = $svc->createDraft($draft);
        $hash = hash('sha256', 'same-request');

        $first = $svc->commit($created->draftId, $hash);
        $replay = $svc->commit($created->draftId, $hash);
        $conflict = $svc->commit($created->draftId, hash('sha256', 'other-request'));

        self::assertTrue($first->success);
        self::assertSame($first, $replay);
        self::assertFalse($conflict->success);
        self::assertSame('copy_idempotency_conflict', $conflict->errorCode);
        self::assertSame(1, $svc->countSourceLinks(0));
    }

    public function testTargetTransactionRollsBackCatalogAndInventoryTogether(): void
    {
        $inventory = InventoryService::forTesting();
        $svc = ProductCopyService::forTesting(new InventoryCatalogCopyCapability($inventory));
        $svc->seedCategory(0, 10, null, 'Default');
        $svc->seedProduct(0, 100, 'SKU-100', 'Product 100', offerId: 200);
        $svc->seedProduct(0, 101, 'SKU-101', 'Product 101', offerId: 201);
        $svc->seedLink(0, 10, 100);
        $svc->seedLink(0, 10, 101);

        $draft = new CopyDraft();
        $draft->entry = CopyDraft::ENTRY_SITE_PULL;
        $draft->targetWebsiteId = 0;
        $draft->targetStoreId = 24;
        $draft->categoryIds = [10];
        $draft->fieldPackages = [CopyDraft::PKG_IDENTITY, CopyDraft::PKG_INVENTORY];
        $created = $svc->createDraft($draft);
        $conflictKey = 'copy:' . $created->draftId . ':store:24:offer:201';
        $inventory->setOnHand(
            0,
            24,
            201,
            9,
            $conflictKey,
            hash('sha256', 'pre-existing-conflict'),
        );

        $result = $svc->commit($created->draftId, hash('sha256', 'transaction'));

        self::assertFalse($result->success);
        self::assertSame('copy_commit_failed', $result->errorCode);
        self::assertSame((string)__('复制提交失败'), $result->message);
        self::assertSame([], $svc->listExplicitStoreProducts(0, 24));
        self::assertSame([], $svc->listExplicitStoreOffers(0, 24));
        self::assertSame(0, $inventory->getAvailability(0, 24, 200)->onHandMinor);
        self::assertSame([], $inventory->listLedgerEvents(0, 24, 200));
        self::assertSame(9, $inventory->getAvailability(0, 24, 201)->onHandMinor);
        self::assertSame(CopyDraft::STATE_DRAFT, $svc->getDraft($created->draftId)?->state);
    }

    public function testCancelDraftLeavesCommittedIntact(): void
    {
        $svc = ProductCopyService::forTesting();
        $this->seedCatalog($svc);
        $d = new CopyDraft();
        $d->entry = CopyDraft::ENTRY_BLANK;
        $d->targetWebsiteId = 0;
        $d->targetStoreId = 20;
        $open = $svc->createDraft($d);
        $svc->cancel($open->draftId);
        self::assertSame(CopyDraft::STATE_CANCELLED, $svc->getDraft($open->draftId)?->state);

        $d2 = new CopyDraft();
        $d2->entry = CopyDraft::ENTRY_SITE_PULL;
        $d2->targetWebsiteId = 0;
        $d2->targetStoreId = 21;
        $d2->sourceWebsiteId = 0;
        $d2->categoryIds = [10];
        $c = $svc->createDraft($d2);
        $svc->commit($c->draftId, hash('sha256', 'c'));
        $this->expectException(\RuntimeException::class);
        $svc->cancel($c->draftId);
    }

    public function testCrossWebsitePreviewAndCommitRequireShareAuthorization(): void
    {
        $checked = [];
        $svc = ProductCopyService::forTesting(
            copyAuthorization: static function (
                string $productUuid,
                int $sourceWebsiteId,
                int $targetWebsiteId,
            ) use (&$checked): bool {
                $checked[] = [$productUuid, $sourceWebsiteId, $targetWebsiteId];
                return false;
            },
        );
        $this->seedCatalog($svc);

        $draft = new CopyDraft();
        $draft->entry = CopyDraft::ENTRY_SITE_PULL;
        $draft->sourceWebsiteId = 0;
        $draft->targetWebsiteId = 7;
        $draft->targetStoreId = 70;
        $draft->categoryIds = [10];
        $created = $svc->createDraft($draft);

        try {
            $svc->preview($created->draftId);
            self::fail('Expected product_copy_not_authorized');
        } catch (\Weline\Product\Service\ProductV2ConflictException $exception) {
            self::assertSame('product_copy_not_authorized', $exception->errorCode);
        }

        $result = $svc->commit($created->draftId, hash('sha256', 'copy-auth-denied'));
        self::assertFalse($result->success);
        self::assertSame('product_copy_not_authorized', $result->errorCode);
        self::assertSame(0, $svc->countProducts(7));
        self::assertContains(['product-0-100', 0, 7], $checked);
    }

    private function seedCatalog(ProductCopyService $svc): void
    {
        $svc->seedCategory(0, 10, null, 'Default');
        $svc->seedProduct(0, 100, 'SKU-100', 'Product 100', ['color' => 'blue'], offerId: 200, priceMinor: 50);
        $svc->seedLink(0, 10, 100);
    }
}
