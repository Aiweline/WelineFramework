<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\PixelEcommerceFunnelService;
use Weline\Visitor\Service\PixelEcommercePurchaseRevenueService;
use Weline\Visitor\Service\Report\PixelQueryRouter;

/**
 * F02：购成 / 收入（仅购买类 value；按日/渠道；依赖 F01 事件集）。
 */
final class PixelEcommercePurchaseRevenueServiceTest extends TestCase
{
    private PixelEcommercePurchaseRevenueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $router = new PixelQueryRouter();
        $this->service = new PixelEcommercePurchaseRevenueService(
            $router,
            new PixelEcommerceFunnelService($router)
        );
    }

    public function testAggregateIgnoresNonPurchaseValue(): void
    {
        $rows = [
            ['session_id' => 'a', 'event' => 'view_item', 'value' => 0, 'channel_code' => 'x', 'created_at' => '2026-07-20 10:00:00'],
            ['session_id' => 'a', 'event' => 'add_to_cart', 'value' => 50, 'channel_code' => 'x', 'created_at' => '2026-07-20 10:01:00'],
            ['session_id' => 'a', 'event' => 'purchase', 'value' => 99.5, 'channel_code' => 'x', 'created_at' => '2026-07-20 10:02:00'],
            ['session_id' => 'b', 'event' => 'view_item', 'value' => 0, 'channel_code' => 'y', 'created_at' => '2026-07-20 11:00:00'],
            ['session_id' => 'b', 'event' => 'checkout_success', 'value' => 20, 'channel_code' => 'y', 'created_at' => '2026-07-20 11:02:00'],
            ['session_id' => 'c', 'event' => 'view_item', 'value' => 0, 'channel_code' => 'z', 'created_at' => '2026-07-20 12:00:00'],
            ['session_id' => 'c', 'event' => 'page_view', 'value' => 1000, 'channel_code' => 'z', 'created_at' => '2026-07-20 12:01:00'],
        ];

        $tot = $this->service->aggregateFromRows($rows);
        self::assertSame(2, $tot['purchases']);
        self::assertEqualsWithDelta(119.5, $tot['purchase_revenue'], 0.0001);
        self::assertEqualsWithDelta(59.75, $tot['avg_order_value'], 0.0001);
        self::assertSame(2, $tot['purchase_sessions']);
        self::assertSame(3, $tot['view_item_sessions']);
        self::assertEqualsWithDelta(2 / 3, $tot['purchase_rate_from_view_item'], 0.0001);
        self::assertEqualsWithDelta(1050.0, $tot['non_purchase_value_ignored'], 0.0001, '加购与 page_view 不得计入收入');
    }

    public function testAggregateByChannelAndDay(): void
    {
        $rows = [
            ['session_id' => 'a', 'event' => 'purchase', 'value' => 10, 'channel_code' => 'summer', 'created_at' => '2026-07-20 10:00:00'],
            ['session_id' => 'b', 'event' => 'checkout_success', 'value' => 30, 'channel_code' => 'summer', 'created_at' => '2026-07-21 10:00:00'],
            ['session_id' => 'c', 'event' => 'purchase', 'value' => 5, 'channel_code' => '', 'created_at' => '2026-07-21 11:00:00'],
            ['session_id' => 'd', 'event' => 'add_to_cart', 'value' => 99, 'channel_code' => 'summer', 'created_at' => '2026-07-21 12:00:00'],
        ];

        $byChannel = $this->service->aggregateByChannel($rows);
        self::assertSame('summer', $byChannel[0]['channel_code']);
        self::assertSame(2, $byChannel[0]['purchases']);
        self::assertEqualsWithDelta(40.0, $byChannel[0]['purchase_revenue'], 0.0001);
        self::assertSame('(none)', $byChannel[1]['channel_code']);
        self::assertEqualsWithDelta(5.0, $byChannel[1]['purchase_revenue'], 0.0001);

        $byDay = $this->service->aggregateByDay($rows);
        self::assertSame(['2026-07-20', '2026-07-21'], array_column($byDay, 'day'));
        self::assertSame(1, $byDay[0]['purchases']);
        self::assertSame(2, $byDay[1]['purchases']);
        self::assertEqualsWithDelta(35.0, $byDay[1]['purchase_revenue'], 0.0001);
    }

    public function testBuildForWebsiteUsesRunnerAndClamps(): void
    {
        $seen = null;
        $result = $this->service->buildForWebsite(
            9,
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-20 23:59:59'),
            static function (int $websiteId, DateTimeImmutable $from, DateTimeImmutable $to) use (&$seen): array {
                $seen = [$websiteId, $from->format('Y-m-d'), $to->format('Y-m-d')];

                return [
                    ['session_id' => 's1', 'event' => 'view_item', 'value' => 0, 'channel_code' => 'a', 'created_at' => $from->format('Y-m-d H:i:s')],
                    ['session_id' => 's1', 'event' => 'purchase', 'value' => 12, 'channel_code' => 'a', 'created_at' => $to->format('Y-m-d H:i:s')],
                ];
            }
        );

        self::assertTrue($result['window_clamped']);
        self::assertSame([9, '2026-01-14', '2026-01-20'], $seen);
        self::assertSame(1, $result['purchases']);
        self::assertEqualsWithDelta(12.0, $result['purchase_revenue'], 0.0001);
        self::assertSame(1.0, $result['purchase_rate_from_view_item']);
        self::assertSame('', $result['error']);
        self::assertNotEmpty($result['by_channel']);
        self::assertNotEmpty($result['by_day']);
    }

    public function testSqlOnlySelectsPurchaseAndViewItemEvents(): void
    {
        $from = new DateTimeImmutable('2026-07-20 00:00:00');
        $to = new DateTimeImmutable('2026-07-26 23:59:59');
        [$sql, $params] = $this->service->buildEventRowsSql(4, $from, $to);

        self::assertStringContainsString('checkout_success', $sql);
        self::assertStringContainsString('purchase', $sql);
        self::assertStringContainsString('view_item', $sql);
        self::assertStringContainsString('channel_code', $sql);
        self::assertStringContainsString('session_id', $sql);
        self::assertStringNotContainsString('add_to_cart', $sql);
        self::assertStringNotContainsString('page_view', $sql);
        self::assertSame(4, $params[':website_id']);
    }

    public function testDetailWiresPurchaseRevenueCard(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/Service/PixelEcommercePurchaseRevenueService.php');

        $controller = (string)\file_get_contents($root . '/Controller/Backend/PixelDashboard.php');
        self::assertStringContainsString('PixelEcommercePurchaseRevenueService', $controller);
        self::assertStringContainsString('ecommerce_revenue', $controller);

        $detail = (string)\file_get_contents($root . '/view/templates/Backend/PixelDashboard/detail.phtml');
        self::assertStringContainsString('ecommerce-revenue', $detail);
        self::assertStringContainsString('购成与收入', $detail);
        self::assertStringContainsString('purchase_revenue', $detail);
    }

    public function testBuildForWebsiteSurfacesInvalidWebsiteId(): void
    {
        $result = $this->service->buildForWebsite(
            -3,
            new DateTimeImmutable('2026-07-20 00:00:00'),
            new DateTimeImmutable('2026-07-26 23:59:59'),
            static fn(): array => [['event' => 'purchase', 'value' => 9]]
        );

        self::assertSame('invalid website_id', $result['error']);
        self::assertSame(0.0, (float)($result['purchase_revenue'] ?? 0));
    }

    public function testBuildForWebsiteSwallowsQueryErrorsWithoutThrowing(): void
    {
        $result = $this->service->buildForWebsite(
            1,
            new DateTimeImmutable('2026-07-20 00:00:00'),
            new DateTimeImmutable('2026-07-26 23:59:59'),
            static function (): array {
                throw new \RuntimeException('flat column missing');
            }
        );

        self::assertSame('flat column missing', $result['error']);
        self::assertSame(0.0, (float)($result['purchase_revenue'] ?? 0));
    }
}
