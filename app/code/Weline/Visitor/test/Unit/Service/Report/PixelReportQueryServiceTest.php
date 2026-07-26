<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\Report\PixelQueryRouter;
use Weline\Visitor\Service\Report\PixelReportQueryService;

/**
 * D04：ReportQuery 单维 group-by（不查库；用 rowProvider 注入事件行）。
 */
final class PixelReportQueryServiceTest extends TestCase
{
    public function testAggregateRowsByChannelCode(): void
    {
        $service = new PixelReportQueryService();
        $rows = $service->aggregateRowsByDimension(
            [
                ['channel_code' => 'summer', 'event' => 'page_view', 'value' => 0],
                ['channel_code' => 'summer', 'event' => 'purchase', 'value' => 99.5],
                ['channel_code' => 'winter', 'event' => 'add_to_cart', 'value' => 0],
                ['channel_code' => '', 'event' => 'page_view', 'value' => 1],
            ],
            'channel_code',
            ['events', 'value_sum', 'valued_events', 'purchases', 'add_to_carts']
        );

        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(string)$row['dimension_value']] = $row;
        }

        self::assertSame('channel_code', $byCode['summer']['dimension']);
        self::assertSame(2, $byCode['summer']['events']);
        self::assertSame(99.5, $byCode['summer']['value_sum']);
        self::assertSame(1, $byCode['summer']['valued_events']);
        self::assertSame(1, $byCode['summer']['purchases']);
        self::assertSame(0, $byCode['summer']['add_to_carts']);

        self::assertSame(1, $byCode['winter']['events']);
        self::assertSame(1, $byCode['winter']['add_to_carts']);
        self::assertSame(0, $byCode['winter']['purchases']);

        self::assertSame(1, $byCode['']['events']);
        self::assertSame(1, $byCode['']['valued_events']);
    }

    public function testQueryBySingleDimensionUsesRouterAndProvider(): void
    {
        $now = new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));
        $from = new DateTimeImmutable('2026-07-20 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-07-25 00:00:00', new DateTimeZone('UTC'));

        $service = new PixelReportQueryService(
            rowProvider: static function (array $ctx) use ($from, $to): array {
                TestCase::assertSame(PixelQueryRouter::SOURCE_HOT, $ctx['route']['source']);
                TestCase::assertSame('w_pixel', $ctx['route']['table']);
                TestCase::assertSame('channel_code', $ctx['dimension']);
                TestCase::assertSame(0, $ctx['website_id']);
                TestCase::assertSame($from->format('Y-m-d H:i:s'), $ctx['route']['from']);
                TestCase::assertSame($to->format('Y-m-d H:i:s'), $ctx['route']['to']);

                return [
                    ['channel_code' => 'summer', 'event' => 'page_view', 'value' => 0],
                    ['channel_code' => 'summer', 'event' => 'cta_click', 'value' => 0],
                    ['channel_code' => 'ads', 'event' => 'page_view', 'value' => 10],
                ];
            }
        );

        $result = $service->queryBySingleDimension(
            'channel_code',
            ['events', 'value_sum'],
            $from,
            $to,
            0,
            50,
            $now
        );

        self::assertSame('hot', $result['route']['source']);
        self::assertSame('channel_code', $result['dimension']);
        self::assertSame(0, $result['website_id']);
        self::assertCount(2, $result['rows']);
        self::assertSame('summer', $result['rows'][0]['dimension_value']);
        self::assertSame(2, $result['rows'][0]['events']);
        self::assertSame('ads', $result['rows'][1]['dimension_value']);
        self::assertSame(1, $result['rows'][1]['events']);
        self::assertSame(10.0, $result['rows'][1]['value_sum']);
    }

    public function testRejectsDailyOnlyMetrics(): void
    {
        $service = new PixelReportQueryService();
        $this->expectException(InvalidArgumentException::class);
        $service->aggregateRowsByDimension(
            [['channel_code' => 'x', 'event' => 'page_view']],
            'channel_code',
            ['sessions']
        );
    }

    public function testLongWindowRoutesWarmViaRouter(): void
    {
        $service = new PixelReportQueryService(
            rowProvider: static function (array $ctx): array {
                TestCase::assertSame(PixelQueryRouter::SOURCE_WARM_DAILY, $ctx['route']['source']);

                return [
                    ['channel_code' => 'summer', 'events' => 5, 'value_sum' => 0, 'valued_events' => 0, 'purchases' => 0, 'add_to_carts' => 0],
                ];
            }
        );
        $now = new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));
        $result = $service->queryBySingleDimension(
            'channel_code',
            ['events'],
            new DateTimeImmutable('2026-07-01 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-07-25 00:00:00', new DateTimeZone('UTC')),
            null,
            50,
            $now
        );
        self::assertSame(PixelQueryRouter::SOURCE_WARM_DAILY, $result['route']['source']);
        self::assertSame(5, $result['rows'][0]['events']);
    }

    public function testRejectsBeyondWarmRetentionViaRouter(): void
    {
        $service = new PixelReportQueryService(
            rowProvider: static fn(): array => []
        );
        $now = new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cold archive route is not available');
        $service->queryBySingleDimension(
            'channel_code',
            ['events'],
            new DateTimeImmutable('2022-01-01 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2022-03-01 00:00:00', new DateTimeZone('UTC')),
            null,
            50,
            $now
        );
    }

    public function testRequiresConfiguredRowProviderForQuery(): void
    {
        $service = new PixelReportQueryService();
        $now = new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));
        $this->expectException(DomainException::class);
        $service->queryBySingleDimension(
            'channel_code',
            ['events'],
            new DateTimeImmutable('2026-07-24 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-07-25 00:00:00', new DateTimeZone('UTC')),
            null,
            50,
            $now
        );
    }
}
