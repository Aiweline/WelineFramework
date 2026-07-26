<?php

declare(strict_types=1);

namespace Weline\Visitor\Service\Report;

use DateTimeInterface;

/**
 * D06：事件价值报表走引擎（catalog `pixel_event_value`）。
 *
 * 总计口径与旧 Pixel::getBusinessValueByPeriod 对齐：
 * total_value = SUM(value)、total_events = COUNT(*)、avg_value = total_value / total_events。
 */
class PixelEventValueReportService
{
    public const REPORT_CODE = 'pixel_event_value';

    public function __construct(
        private ?PixelReportCatalog $catalog = null,
        private ?PixelReportQueryService $queryService = null,
    ) {
    }

    /**
     * 经引擎按 event_name 聚合事件价值。
     *
     * @return array{
     *   report: string,
     *   route: array<string, mixed>,
     *   rows: list<array<string, mixed>>,
     *   totals: array{total_value: float, total_events: int, avg_value: float}
     * }
     */
    public function query(
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?int $websiteId = null,
        int $limit = 50,
        ?DateTimeInterface $now = null,
    ): array {
        $report = $this->catalog()->require(self::REPORT_CODE);

        $result = $this->queryService()->queryBySingleDimension(
            $report['dimension'],
            $report['metrics'],
            $from,
            $to,
            $websiteId,
            $limit,
            $now
        );

        $rows = $this->decorateRows($result['rows']);

        return [
            'report' => self::REPORT_CODE,
            'route' => $result['route'],
            'rows' => $rows,
            'totals' => $this->totalsFromRows($rows),
        ];
    }

    /**
     * 纯内存：按 event_name 聚合并附派生 avg_value（供无库单测与对齐测）。
     *
     * @param list<array<string, mixed>> $eventRows
     * @return list<array<string, mixed>>
     */
    public function aggregateEventRows(array $eventRows): array
    {
        $report = $this->catalog()->require(self::REPORT_CODE);
        $rows = $this->queryService()->aggregateRowsByDimension(
            $eventRows,
            $report['dimension'],
            $report['metrics']
        );

        return $this->decorateRows($rows);
    }

    /**
     * 与旧 business value 相同的总计公式。
     *
     * @param list<array<string, mixed>> $rows 引擎聚合行（含 events / value_sum）
     * @return array{total_value: float, total_events: int, avg_value: float}
     */
    public function totalsFromRows(array $rows): array
    {
        $totalValue = 0.0;
        $totalEvents = 0;
        foreach ($rows as $row) {
            $totalValue += (float)($row['value_sum'] ?? 0);
            $totalEvents += (int)($row['events'] ?? 0);
        }

        return [
            'total_value' => $totalValue,
            'total_events' => $totalEvents,
            'avg_value' => $totalEvents > 0 ? $totalValue / $totalEvents : 0.0,
        ];
    }

    /**
     * 为聚合行补派生 `avg_value`（detail 事件价值 Tab 复用同一口径）。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function decorateRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            $events = (int)($row['events'] ?? 0);
            $valueSum = (float)($row['value_sum'] ?? 0);
            $row['avg_value'] = $events > 0 ? $valueSum / $events : 0.0;

            return $row;
        }, $rows);
    }

    private function catalog(): PixelReportCatalog
    {
        return $this->catalog ??= new PixelReportCatalog();
    }

    private function queryService(): PixelReportQueryService
    {
        return $this->queryService ??= new PixelReportQueryService();
    }
}
