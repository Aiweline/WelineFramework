<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\Data\CopyDraft;
use Weline\Product\Extends\Module\Weline_Framework\Query\ProductCopyQueryProvider;
use Weline\Product\Service\ProductCopyService;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\Data\WebsiteSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

final class ProductCopyQueryProviderTest extends TestCase
{
    public function testDescriptorPublishesOnlyBackendAclProtectedOperations(): void
    {
        $descriptor = $this->provider()->getDescriptor();
        self::assertSame('product_copy', $descriptor['provider']);

        $names = [];
        foreach ($descriptor['operations'] as $operation) {
            $names[] = $operation['name'];
            self::assertTrue($operation['frontend']);
            self::assertTrue($operation['backend']);
            self::assertFalse($operation['external']);
            self::assertSame('backend', $operation['auth']);
            self::assertSame([
                'kind' => 'source',
                'source_id' => ProductCopyQueryProvider::ACL_SOURCE,
            ], $operation['backend_acl']);
        }
        self::assertSame(
            ['scopeOptions', 'createDraft', 'getDraft', 'preview', 'commit', 'cancel'],
            $names,
        );
    }

    public function testBlankDraftPreviewCommitAndScopeOptionsUseOneBoundary(): void
    {
        $provider = $this->provider();
        $options = $provider->execute('scopeOptions');
        self::assertTrue($options['success']);
        self::assertSame([0, 1], array_column($options['websites'], 'website_id'));
        self::assertSame([1, 2], array_column($options['stores'], 'store_id'));

        $created = $provider->execute('createDraft', [
            'entry' => CopyDraft::ENTRY_BLANK,
            'target_website_id' => 0,
            'target_store_id' => 1,
            'category_ids' => [9, 9],
            'include_products' => true,
            'inventory_copy_qty' => true,
        ]);
        self::assertTrue($created['success']);
        $draft = $created['draft'];
        self::assertSame([], $draft['category_ids']);
        self::assertFalse($draft['include_products']);
        self::assertNull($draft['source_website_id']);
        self::assertNull($draft['source_store_id']);

        $preview = $provider->execute('preview', ['draft_id' => $draft['draft_id']]);
        self::assertTrue($preview['success']);
        self::assertSame(0, $preview['preview']['product_count']);

        $result = $provider->execute('commit', [
            'draft_id' => $draft['draft_id'],
            'request_hash' => hash('sha256', 'query-provider-blank'),
        ]);
        self::assertTrue($result['success']);
        self::assertSame($draft['draft_id'], $result['draft_id']);

        $loaded = $provider->execute('getDraft', ['draft_id' => $draft['draft_id']]);
        self::assertSame(CopyDraft::STATE_COMMITTED, $loaded['draft']['state']);
    }

    public function testScopeValidationRejectsMismatchedAndArchivedStores(): void
    {
        $provider = $this->provider();

        try {
            $provider->execute('createDraft', [
                'entry' => CopyDraft::ENTRY_BLANK,
                'target_website_id' => 1,
                'target_store_id' => 1,
            ]);
            self::fail('Website/Store mismatch must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('不属于', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('已归档');
        $provider->execute('createDraft', [
            'entry' => CopyDraft::ENTRY_BLANK,
            'target_website_id' => 1,
            'target_store_id' => 3,
        ]);
    }

    public function testStoreInheritanceRequiresSourceStoreOwnershipAndUnknownOperationFailsClosed(): void
    {
        $provider = $this->provider();
        try {
            $provider->execute('createDraft', [
                'entry' => CopyDraft::ENTRY_STORE_INHERIT,
                'target_website_id' => 1,
                'target_store_id' => 2,
                'source_website_id' => 1,
                'source_store_id' => 1,
            ]);
            self::fail('Source Store ownership mismatch must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('来源 Store', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $provider->execute('unsafeUnknownOperation');
    }

    private function provider(): ProductCopyQueryProvider
    {
        $websiteCatalog = $this->createStub(WebsiteCatalogInterface::class);
        $websiteCatalog->method('all')->willReturn([
            new WebsiteSummary(0, 'Default', 'default', 'http://default.test'),
            new WebsiteSummary(1, 'Second', 'second', 'http://second.test'),
        ]);

        $stores = [
            1 => new StoreSummary(1, 0, 'default', 'Default', 'normal', true, true, 'active', null),
            2 => new StoreSummary(2, 1, 'default', 'Second', 'normal', true, true, 'active', null),
            3 => new StoreSummary(3, 1, 'old', 'Archived', 'normal', false, false, 'tombstone', '2026-07-27 00:00:00'),
        ];
        $storeCatalog = $this->createStub(StoreCatalogInterface::class);
        $storeCatalog->method('all')->willReturn(array_values($stores));
        $storeCatalog->method('byId')->willReturnCallback(
            static fn(int $storeId): ?StoreSummary => $stores[$storeId] ?? null,
        );

        return new ProductCopyQueryProvider(
            ProductCopyService::forTesting(),
            $websiteCatalog,
            $storeCatalog,
        );
    }
}
