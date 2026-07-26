<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\Report\PixelEventValueReportService;
use Weline\Visitor\Service\Report\PixelReportQueryService;

/**
 * D06：事件价值走引擎 + 与旧 business value 口径对齐测（不查库）。
 */
final class PixelEventValueReportServiceTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function sampleEventRows(): array
    {
        return [
            ['event' => 'page_view', 'value' => 0],
            ['event' => 'page_view', 'value' => 0],
            ['event' => 'cta_click', 'value' => 5],
            ['event' => 'purchase', 'value' => 99.5],
            ['event' => 'purchase', 'value' => 0.5],
            ['event' => 'add_to_cart', 'value' => 0],
        ];
    }

    /**
     * 旧口径：Pixel::getBusinessValueByPeriod 单桶公式
     * total_value=SUM(value)、total_events=COUNT(*)、avg_value=value/events。
     *
     * @param list<array<string, mixed>> $rows
     * @return array{total_value: float, total_events: int, avg_value: float}
     */
    private function legacyBusinessValueTotals(array $rows): array
    {
        $totalValue = 0.0;
        $totalEvents = 0;
        foreach ($rows as $row) {
            $totalValue += (float)($row['value'] ?? 0);
            $totalEvents++;
        }

        return [
            'total_value' => $totalValue,
            'total_events' => $totalEvents,
            'avg_value' => $totalEvents > 0 ? $totalValue / $totalEvents : 0.0,
        ];
    }

    public function testEngineTotalsMatchLegacyBusinessValueTotals(): void
    {
        $service = new PixelEventValueReportService();
        $rows = $this->sampleEventRows();

        $engineRows = $service->aggregateEventRows($rows);
        $engineTotals = $service->totalsFromRows($engineRows);
        $legacyTotals = $this->legacyBusinessValueTotals($rows);

        self::assertSame($legacyTotals['total_events'], $engineTotals['total_events']);
        self::assertEqualsWithDelta($legacyTotals['total_value'], $engineTotals['total_value'], 0.0001);
        self::assertEqualsWithDelta($legacyTotals['avg_value'], $engineTotals['avg_value'], 0.0001);
    }

    public function testAggregateEventRowsGroupsByEventNameWithAvgValue(): void
    {
        $service = new PixelEventValueReportService();
        $rows = $service->aggregateEventRows($this->sampleEventRows());

        $byEvent = [];
        foreach ($rows as $row) {
            $byEvent[(string)$row['dimension_value']] = $row;
        }

        self::assertSame('event_name', $byEvent['purchase']['dimension']);
        self::assertSame(2, $byEvent['purchase']['events']);
        self::assertEqualsWithDelta(100.0, $byEvent['purchase']['value_sum'], 0.0001);
        self::assertEqualsWithDelta(50.0, $byEvent['purchase']['avg_value'], 0.0001);
        self::assertSame(2, $byEvent['purchase']['valued_events']);

        self::assertSame(2, $byEvent['page_view']['events']);
        self::assertEqualsWithDelta(0.0, $byEvent['page_view']['avg_value'], 0.0001);
        self::assertSame(0, $byEvent['page_view']['valued_events']);
    }

    public function testQueryUsesCatalogReportAndReturnsTotals(): void
    {
        $now = new DateTimeImmutable('2026-07-25 12:00:00', new DateTimeZone('UTC'));
        $from = new DateTimeImmutable('2026-07-20 00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('2026-07-25 00:00:00', new DateTimeZone('UTC'));
        $sample = $this->sampleEventRows();

        $service = new PixelEventValueReportService(
            queryService: new PixelReportQueryService(
                rowProvider: static function (array $ctx) use ($sample): array {
                    TestCase::assertSame('event_name', $ctx['dimension']);
                    TestCase::assertSame('hot', $ctx['route']['source']);
                    TestCase::assertContains('value_sum', $ctx['metrics']);

                    return $sample;
                }
            )
        );

        $result = $service->query($from, $to, 0, 50, $now);

        self::assertSame('pixel_event_value', $result['report']);
        self::assertSame('hot', $result['route']['source']);
        self::assertSame(6, $result['totals']['total_events']);
        self::assertEqualsWithDelta(105.0, $result['totals']['total_value'], 0.0001);
        self::assertEqualsWithDelta(105.0 / 6, $result['totals']['avg_value'], 0.0001);

        // 引擎行按 events 降序：page_view 与 purchase 同为 2，按维值字典序
        self::assertSame('page_view', $result['rows'][0]['dimension_value']);
    }

    public function testEmptyRowsProduceZeroTotals(): void
    {
        $service = new PixelEventValueReportService();
        $totals = $service->totalsFromRows([]);

        self::assertSame(0, $totals['total_events']);
        self::assertSame(0.0, $totals['total_value']);
        self::assertSame(0.0, $totals['avg_value']);
    }
}
