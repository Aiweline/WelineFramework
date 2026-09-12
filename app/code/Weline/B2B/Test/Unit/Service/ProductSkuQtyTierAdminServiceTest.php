<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\B2BConflictException;
use Weline\B2B\Service\B2BService;
use Weline\B2B\Service\ProductSkuQtyTierAdminService;

require_once dirname(__DIR__) . '/bootstrap.php';

final class ProductSkuQtyTierAdminServiceTest extends TestCase
{
    private B2BService $service;
    private ProductSkuQtyTierAdminService $tiers;

    protected function setUp(): void
    {
        $this->service = B2BService::forTesting();
        $this->service->enableAllowlist(['website:0']);
        $this->service->seedGroup('g-dealer', 0, 'dealer');
        $this->tiers = ProductSkuQtyTierAdminService::forTesting(
            $this->service,
            static fn (int $websiteId, int $productId): array => match ($productId) {
                101 => ['SKU-A', 'SKU-B'],
                202 => ['SKU-ONLY'],
                default => [],
            },
        );
    }

    public function testUpsertCreatesWebsiteLevelListAndKeepsChannelNull(): void
    {
        $result = $this->tiers->upsertSkuQtyTiers([
            'website_id' => 0,
            'group_id' => 'g-dealer',
            'product_id' => 101,
            'tiers' => [
                ['sku' => 'SKU-A', 'min_qty' => 1, 'amount_minor' => 900],
                ['sku' => 'SKU-A', 'min_qty' => 10, 'amount_minor' => 800],
                ['sku' => 'SKU-B', 'min_qty' => 1, 'amount_minor' => 1200],
            ],
        ]);

        self::assertTrue($result['created']);
        self::assertTrue($result['active']);
        self::assertNull($result['channel_id']);
        self::assertSame(1, $result['version']);
        $list = $this->service->engine()->lists()->get((string)$result['list_id']);
        self::assertNotNull($list);
        self::assertNull($list->channelId);
        self::assertSame([1 => 900, 10 => 800], $list->skuQtyTiers['SKU-A']);
        self::assertSame([1 => 1200], $list->skuQtyTiers['SKU-B']);
    }

    public function testUpsertCopyForwardPreservesOtherSkus(): void
    {
        $this->service->seedPriceList('pl-shared', 'g-dealer', 0, 1, [
            'SKU-OTHER' => 500,
            'SKU-A' => 1000,
        ]);

        $result = $this->tiers->upsertSkuQtyTiers([
            'website_id' => 0,
            'group_id' => 'g-dealer',
            'product_id' => 101,
            'expected_version' => 1,
            'tiers' => [
                ['sku' => 'SKU-A', 'min_qty' => 1, 'amount_minor' => 880],
                ['sku' => 'SKU-B', 'min_qty' => 5, 'amount_minor' => 700],
            ],
        ]);

        self::assertFalse($result['created']);
        self::assertSame(2, $result['version']);
        $list = $this->service->engine()->lists()->get('pl-shared');
        self::assertNotNull($list);
        self::assertSame([1 => 500], $list->skuQtyTiers['SKU-OTHER']);
        self::assertSame([1 => 880], $list->skuQtyTiers['SKU-A']);
        self::assertSame([5 => 700], $list->skuQtyTiers['SKU-B']);
    }

    public function testClearingLastProductSkusDeactivatesList(): void
    {
        $this->service->seedPriceList('pl-solo', 'g-dealer', 0, 1, [
            'SKU-ONLY' => [1 => 400, 10 => 350],
        ]);

        $result = $this->tiers->upsertSkuQtyTiers([
            'website_id' => 0,
            'group_id' => 'g-dealer',
            'product_id' => 202,
            'expected_version' => 1,
            'tiers' => [],
        ]);

        self::assertTrue($result['deactivated']);
        self::assertFalse($result['active']);
        self::assertSame(2, $result['version']);
        $list = $this->service->engine()->lists()->get('pl-solo');
        self::assertNotNull($list);
        self::assertFalse($list->active);
        self::assertArrayHasKey('SKU-ONLY', $list->skuQtyTiers);
    }

    public function testExpectedVersionConflict(): void
    {
        $this->service->seedPriceList('pl-conflict', 'g-dealer', 0, 3, [
            'SKU-A' => 100,
        ]);

        $this->expectException(B2BConflictException::class);
        $this->tiers->upsertSkuQtyTiers([
            'website_id' => 0,
            'group_id' => 'g-dealer',
            'product_id' => 101,
            'expected_version' => 1,
            'tiers' => [
                ['sku' => 'SKU-A', 'min_qty' => 1, 'amount_minor' => 200],
            ],
        ]);
    }

    public function testRejectsSkuNotBelongingToProduct(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tiers->upsertSkuQtyTiers([
            'website_id' => 0,
            'group_id' => 'g-dealer',
            'product_id' => 101,
            'tiers' => [
                ['sku' => 'SKU-FOREIGN', 'min_qty' => 1, 'amount_minor' => 100],
            ],
        ]);
    }
}
