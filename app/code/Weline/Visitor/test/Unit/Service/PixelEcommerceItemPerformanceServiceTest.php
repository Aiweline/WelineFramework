<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Http\Request;
use Weline\Visitor\Service\PixelEcommerceFunnelService;
use Weline\Visitor\Service\PixelEcommerceItemPerformanceService;
use Weline\Visitor\Service\PixelEventService;
use Weline\Visitor\Service\Report\PixelQueryRouter;

/**
 * F03：商品表现（items 展开；入库保留 + 聚合）。
 */
final class PixelEcommerceItemPerformanceServiceTest extends TestCase
{
    private PixelEcommerceItemPerformanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $router = new PixelQueryRouter();
        $this->service = new PixelEcommerceItemPerformanceService(
            $router,
            new PixelEcommerceFunnelService($router)
        );
    }

    public function testExpandAndAggregateByItem(): void
    {
        $rows = [
            [
                'event' => 'view_item',
                'session_id' => 's1',
                'value' => 0,
                'items' => [
                    ['item_id' => 'sku-a', 'item_name' => 'Alpha', 'price' => 10, 'quantity' => 1],
                ],
            ],
            [
                'event' => 'add_to_cart',
                'session_id' => 's1',
                'value' => 10,
                'items' => [
                    ['item_id' => 'sku-a', 'item_name' => 'Alpha', 'price' => 10, 'quantity' => 1],
                ],
            ],
            [
                'event' => 'purchase',
                'session_id' => 's1',
                'value' => 20,
                'items' => [
                    ['item_id' => 'sku-a', 'item_name' => 'Alpha', 'price' => 10, 'quantity' => 2],
                ],
            ],
            [
                'event' => 'view_item',
                'session_id' => 's2',
                'value' => 0,
                'items' => [
                    ['item_id' => 'sku-b', 'item_name' => 'Beta', 'price' => 5, 'quantity' => 1],
                ],
            ],
            [
                'event' => 'checkout_success',
                'session_id' => 's2',
                'value' => 5,
                'items' => [
                    ['item_id' => 'sku-b', 'item_name' => 'Beta', 'price' => 5, 'quantity' => 1],
                ],
            ],
        ];

        $agg = $this->service->aggregateByItem($rows);
        self::assertSame('sku-a', $agg[0]['item_id']);
        self::assertSame(1, $agg[0]['views']);
        self::assertSame(1, $agg[0]['add_to_carts']);
        self::assertSame(1, $agg[0]['purchases']);
        self::assertEqualsWithDelta(20.0, $agg[0]['item_revenue'], 0.0001);
        self::assertEqualsWithDelta(2.0, $agg[0]['quantity_sold'], 0.0001);
        self::assertSame('sku-b', $agg[1]['item_id']);
        self::assertEqualsWithDelta(5.0, $agg[1]['item_revenue'], 0.0001);
    }

    public function testPurchaseWithoutPriceFallsBackToEventValueShare(): void
    {
        $lines = $this->service->expandItemsFromRow([
            'event' => 'purchase',
            'value' => 90,
            'session_id' => 's',
            'items' => [
                ['item_id' => 'a', 'item_name' => 'A'],
                ['item_id' => 'b', 'item_name' => 'B'],
                ['item_id' => 'c', 'item_name' => 'C'],
            ],
        ]);
        self::assertCount(3, $lines);
        self::assertEqualsWithDelta(30.0, $lines[0]['line_revenue'], 0.0001);
        self::assertEqualsWithDelta(30.0, $lines[1]['line_revenue'], 0.0001);
    }

    public function testExtractItemsFromBrowserInfoEcommerce(): void
    {
        $row = [
            'event' => 'view_item',
            'session_id' => 's',
            'browser_info' => json_encode([
                'additionalInfo' => [
                    'ecommerce' => [
                        'items' => [
                            ['item_id' => 'p1', 'item_name' => 'Phone', 'price' => 99, 'quantity' => 1],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];
        $items = $this->service->extractItems($row);
        self::assertSame('p1', $items[0]['item_id']);
        $expanded = $this->service->expandItemsFromRow($row);
        self::assertSame('Phone', $expanded[0]['item_name']);
    }

    public function testPreparePersistsEcommerceItemsIntoBrowserInfo(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('clientIP')->willReturn('127.0.0.1');
        $service = new PixelEventService($request);
        $method = new ReflectionMethod(PixelEventService::class, 'prepare');
        $method->setAccessible(true);

        /** @var array{data: array<string,mixed>} $prepared */
        $prepared = $method->invoke($service, [
            'eventName' => 'add_to_cart',
            'url' => 'https://example.test/product/1',
            'websiteId' => 1,
            'value' => 12,
            'items' => [
                ['item_id' => 'sku-99', 'item_name' => 'Widget', 'price' => 12, 'quantity' => 1],
            ],
            'additionalInfo' => [
                'environment' => ['session_id' => 'wps-f03-items'],
            ],
        ]);

        $browser = json_decode((string)$prepared['data']['browser_info'], true);
        self::assertIsArray($browser);
        $items = $browser['additionalInfo']['ecommerce']['items'] ?? [];
        self::assertNotEmpty($items);
        self::assertSame('sku-99', $items[0]['item_id']);
        self::assertSame('Widget', $items[0]['item_name']);
    }

    public function testBuildForWebsiteAndDetailWiring(): void
    {
        $result = $this->service->buildForWebsite(
            2,
            new DateTimeImmutable('2026-07-20 00:00:00'),
            new DateTimeImmutable('2026-07-26 23:59:59'),
            static function (): array {
                return [
                    [
                        'event' => 'view_item',
                        'session_id' => 's',
                        'value' => 0,
                        'items' => [['item_id' => 'x', 'item_name' => 'X', 'price' => 1, 'quantity' => 1]],
                    ],
                ];
            }
        );
        self::assertSame(1, $result['item_count']);
        self::assertSame('', $result['error']);

        $root = dirname(__DIR__, 3);
        $controller = (string)\file_get_contents($root . '/Controller/Backend/PixelDashboard.php');
        self::assertStringContainsString('PixelEcommerceItemPerformanceService', $controller);
        self::assertStringContainsString('ecommerce_items', $controller);
        $detail = (string)\file_get_contents($root . '/view/templates/Backend/PixelDashboard/detail.phtml');
        self::assertStringContainsString('ecommerce-items', $detail);
        self::assertStringContainsString('商品表现', $detail);
    }
}
