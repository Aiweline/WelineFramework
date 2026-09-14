<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Model\OrderItem;
use Weline\Order\Service\BackendOrderLinePresenter;

final class BackendOrderLinePresenterTest extends TestCase
{
    public function testPresentFallsBackToCatalogSnapshotWhenPersistedItemsEmpty(): void
    {
        $presenter = new BackendOrderLinePresenter();
        $lines = $presenter->present([
            'catalog_snapshot_json' => json_encode([
                'lines' => [[
                    'name' => '龙纹追踪器',
                    'sku' => 'DS-CJ-1',
                    'qty_minor' => 10,
                    'unit_price_minor' => 2194,
                    'row_total_minor' => 21940,
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ], []);

        self::assertCount(1, $lines);
        self::assertSame('龙纹追踪器', $lines[0][OrderItem::schema_fields_PRODUCT_NAME]);
        self::assertSame('DS-CJ-1', $lines[0][OrderItem::schema_fields_PRODUCT_SKU]);
        self::assertEqualsWithDelta(10.0, $lines[0][OrderItem::schema_fields_QTY_ORDERED], 0.001);
        self::assertEqualsWithDelta(21.94, $lines[0][OrderItem::schema_fields_PRICE], 0.001);
        self::assertEqualsWithDelta(219.4, $lines[0][OrderItem::schema_fields_ROW_TOTAL], 0.001);
        self::assertSame('catalog_snapshot', $lines[0]['source']);
        self::assertFalse($lines[0]['has_deal']);
        self::assertSame('', $lines[0]['campaign_label']);
    }

    public function testPresentPrefersPersistedOrderItems(): void
    {
        $presenter = new BackendOrderLinePresenter();
        $lines = $presenter->present([
            'catalog_snapshot_json' => json_encode([
                'lines' => [['name' => '快照名', 'sku' => 'SNAP', 'qty_minor' => 1, 'unit_price_minor' => 100]],
            ], JSON_UNESCAPED_UNICODE),
        ], [[
            OrderItem::schema_fields_PRODUCT_NAME => '表行名',
            OrderItem::schema_fields_PRODUCT_SKU => 'ROW',
            OrderItem::schema_fields_QTY_ORDERED => 2,
            OrderItem::schema_fields_PRICE => 11.5,
            OrderItem::schema_fields_ROW_TOTAL => 23.0,
        ]]);

        self::assertCount(1, $lines);
        self::assertSame('表行名', $lines[0][OrderItem::schema_fields_PRODUCT_NAME]);
        self::assertSame('order_item', $lines[0]['source']);
    }

    public function testPresentExposesCheckoutDealChromeFromLineSnapshot(): void
    {
        $presenter = new BackendOrderLinePresenter();
        $lines = $presenter->present([], [[
            OrderItem::schema_fields_PRODUCT_NAME => '活动商品',
            OrderItem::schema_fields_PRODUCT_SKU => 'DEAL-1',
            OrderItem::schema_fields_QTY_ORDERED => 2,
            OrderItem::schema_fields_PRICE => 80.10,
            OrderItem::schema_fields_ROW_TOTAL => 160.20,
            OrderItem::schema_fields_CATALOG_LINE_SNAPSHOT_JSON => json_encode([
                'unit_price_minor' => 8010,
                'compare_at_minor' => 8900,
                'has_deal' => true,
                'campaign_label' => "Today's Picks",
                'campaign_url' => '/promotion/deals',
                'options' => [[
                    'code' => 'color',
                    'label' => '颜色',
                    'value' => 'red',
                    'value_label' => '红',
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertCount(1, $lines);
        self::assertTrue($lines[0]['has_deal']);
        self::assertEqualsWithDelta(89.0, $lines[0]['compare_at'], 0.001);
        self::assertEqualsWithDelta(80.10, $lines[0][OrderItem::schema_fields_PRICE], 0.001);
        self::assertEqualsWithDelta(17.8, $lines[0][OrderItem::schema_fields_DISCOUNT_AMOUNT], 0.05);
        self::assertSame("Today's Picks", $lines[0]['campaign_label']);
        self::assertSame('/promotion/deals', $lines[0]['campaign_url']);
        self::assertSame('颜色', $lines[0]['options'][0]['label']);
        self::assertSame('红', $lines[0]['options'][0]['value']);
    }

    public function testPresentProjectsSnapshotImageSrc(): void
    {
        $resolver = new class implements \Weline\Order\Api\OrderCatalogImageResolverInterface {
            public function resolveReference(string $reference, int $websiteId = 0, int $storeId = 0): string
            {
                return $reference === '/media/demo.jpg' ? 'https://cdn.example/demo.jpg' : '';
            }

            public function resolveProductMainImages(int $websiteId, array $productIds, int $storeId = 0): array
            {
                return [];
            }
        };
        $presenter = new BackendOrderLinePresenter($resolver);
        $lines = $presenter->present([
            'website_id' => 0,
            'catalog_snapshot_json' => json_encode([
                'lines' => [[
                    'name' => '有图商品',
                    'sku' => 'IMG-1',
                    'qty_minor' => 1,
                    'unit_price_minor' => 100,
                    'image' => '/media/demo.jpg',
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ], []);

        self::assertSame('https://cdn.example/demo.jpg', $lines[0]['image_src']);
        self::assertSame('/media/demo.jpg', $lines[0]['image']);
    }

    public function testPresentFallsBackToProductMainImageWhenSnapshotImageMissing(): void
    {
        $resolver = new class implements \Weline\Order\Api\OrderCatalogImageResolverInterface {
            public function resolveReference(string $reference, int $websiteId = 0, int $storeId = 0): string
            {
                return '';
            }

            public function resolveProductMainImages(int $websiteId, array $productIds, int $storeId = 0): array
            {
                return [558 => 'https://cdn.example/p558.jpg'];
            }
        };
        $presenter = new BackendOrderLinePresenter($resolver);
        $lines = $presenter->present([
            'website_id' => 0,
            'catalog_snapshot_json' => json_encode([
                'lines' => [[
                    'name' => '历史无图',
                    'sku' => 'LEGACY',
                    'product_id' => 558,
                    'qty_minor' => 1,
                    'unit_price_minor' => 100,
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ], []);

        self::assertSame('https://cdn.example/p558.jpg', $lines[0]['image_src']);
        self::assertSame(558, $lines[0]['product_id']);
    }
}
