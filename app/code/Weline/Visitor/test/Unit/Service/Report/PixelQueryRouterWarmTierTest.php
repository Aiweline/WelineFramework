<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelStatsDaily;
use Weline\Visitor\Model\PixelStatsHourly;
use Weline\Visitor\Service\Report\PixelQueryRouter;
use Weline\Visitor\Service\Report\PixelReportQueryService;

/**
 * G06：QueryRouter 热/温路由 + ReportQuery 温 SUM（不查库）。
 */
final class PixelQueryRouterWarmTierTest extends TestCase
{
    private PixelQueryRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new PixelQueryRouter();
    }

    public function testShortWindowRoutesHot(): void
    {
        $now = new DateTimeImmutable('2026-07-26 12:00:00', new DateTimeZone('UTC'));
        $from = new DateTimeImmutable('2026-07-20 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-07-26 00:00:00', new DateTimeZone('UTC'));

        $route = $this->router->route(['channel_code'], $from, $to, $now);

        self::assertSame(PixelQueryRouter::SOURCE_HOT, $route['source']);
        self::assertSame(Pixel::schema_table, $route['table']);
        self::assertSame(Pixel::schema_fields_CREATED_AT, $route['time_field']);
        self::assertSame('event', $route['grain']);
    }

    public function testLongWindowRoutesWarmDaily(): void
    {
        $now = new DateTimeImmutable('2026-07-26 12:00:00', new DateTimeZone('UTC'));
        $from = new DateTimeImmutable('2026-05-01 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-07-26 00:00:00', new DateTimeZone('UTC'));

        $route = $this->router->route(['channel_code', 'traffic_type'], $from, $to, $now);

        self::assertSame(PixelQueryRouter::SOURCE_WARM_DAILY, $route['source']);
        self::assertSame(PixelStatsDaily::schema_table, $route['table']);
        self::assertSame(PixelStatsDaily::schema_fields_DAY_BUCKET, $route['time_field']);
        self::assertSame('day', $route['grain']);
        self::assertSame('2026-05-01', $route['from']);
        self::assertSame('2026-07-26', $route['to']);
        self::assertTrue($this->router->isWarmSource($route['source']));
    }

    public function testBeforeHotRetentionButWithinWarmUsesHourlyWhenSpanShort(): void
    {
        $now = new DateTimeImmutable('2026-07-26 12:00:00', new DateTimeZone('UTC'));
        // 热保留 365 天：from 在 400 天前 → 超热保留；跨度 1 天 → 小时温表
        $from = new DateTimeImmutable('2025-06-20 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2025-06-21 00:00:00', new DateTimeZone('UTC'));

        $route = $this->router->route(['event_name'], $from, $to, $now);

        self::assertSame(PixelQueryRouter::SOURCE_WARM_HOURLY, $route['source']);
        self::assertSame(PixelStatsHourly::schema_table, $route['table']);
        self::assertSame(PixelStatsHourly::schema_fields_HOUR_BUCKET, $route['time_field']);
        self::assertSame('hour', $route['grain']);
    }

    public function testBeyondWarmRetentionRejected(): void
    {
        $now = new DateTimeImmutable('2026-07-26 12:00:00', new DateTimeZone('UTC'));
        $from = new DateTimeImmutable('2022-01-01 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2022-02-01 00:00:00', new DateTimeZone('UTC'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cold archive route is not available');
        $this->router->route(['channel_code'], $from, $to, $now);
    }

    public function testHighCardinalityDimensionRejectedOnWarm(): void
    {
        $now = new DateTimeImmutable('2026-07-26 12:00:00', new DateTimeZone('UTC'));
        $from = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-07-26 00:00:00', new DateTimeZone('UTC'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('warm aggregate does not support dimension: page_path');
        $this->router->route(['page_path'], $from, $to, $now);
    }

    public function testReportQueryWarmSumsPreAggregatedRows(): void
    {
        $now = new DateTimeImmutable('2026-07-26 12:00:00', new DateTimeZone('UTC'));
        $from = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-07-26 00:00:00', new DateTimeZone('UTC'));

        $service = new PixelReportQueryService(
            rowProvider: static function (array $ctx): array {
                TestCase::assertSame(PixelQueryRouter::SOURCE_WARM_DAILY, $ctx['route']['source']);
                TestCase::assertSame(PixelStatsDaily::schema_table, $ctx['route']['table']);

                return [
                    [
                        'channel_code' => 'summer',
                        'event_name' => 'page_view',
                        'events' => 10,
                        'value_sum' => 0,
                        'valued_events' => 0,
                        'purchases' => 0,
                        'add_to_carts' => 0,
                    ],
                    [
                        'channel_code' => 'summer',
                        'event_name' => 'purchase',
                        'events' => 2,
                        'value_sum' => 198.5,
                        'valued_events' => 2,
                        'purchases' => 2,
                        'add_to_carts' => 0,
                    ],
                    [
                        'channel_code' => 'winter',
                        'event_name' => 'add_to_cart',
                        'events' => 3,
                        'value_sum' => 0,
                        'valued_events' => 0,
                        'purchases' => 0,
                        'add_to_carts' => 3,
                    ],
                ];
            }
        );

        $result = $service->queryBySingleDimension(
            'channel_code',
            ['events', 'value_sum', 'purchases', 'add_to_carts'],
            $from,
            $to,
            0,
            50,
            $now
        );

        self::assertSame(PixelQueryRouter::SOURCE_WARM_DAILY, $result['route']['source']);
        $byCode = [];
        foreach ($result['rows'] as $row) {
            $byCode[(string)$row['dimension_value']] = $row;
        }
        self::assertSame(12, $byCode['summer']['events']);
        self::assertEqualsWithDelta(198.5, $byCode['summer']['value_sum'], 0.0001);
        self::assertSame(2, $byCode['summer']['purchases']);
        self::assertSame(3, $byCode['winter']['add_to_carts']);
    }
}
