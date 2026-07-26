<?php

declare(strict_types=1);

namespace Weline\Visitor\Service\Report;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use InvalidArgumentException;

/**
 * detail 报表 Tab 挂载（D07 逐个追加，禁止一次挂满 catalog）。
 *
 * 当前已挂：D07a–D07f（catalog 六个预设全部挂完）。
 */
class PixelDetailReportTabService
{
    /** 已挂载的 catalog code（按挂载顺序）。 */
    public const MOUNTED_REPORT_CODES = [
        'pixel_channels',
        'pixel_traffic_type',
        'pixel_paid',
        'pixel_social',
        'pixel_event_value',
        'pixel_value_by_channel',
    ];

    public const FIRST_TAB_CODE = 'pixel_channels';

    /**
     * 引擎维度 → list 下钻 query 键。
     *
     * @var array<string, string>
     */
    public const DIMENSION_DRILLDOWN_KEYS = [
        'channel_code' => 'channel_code',
        'traffic_type' => 'traffic_type',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'event_name' => 'event',
    ];

    public function __construct(
        private ?PixelReportCatalog $catalog = null,
        private ?PixelReportQueryService $queryService = null,
        private ?PixelQueryRouter $router = null,
        private ?PixelEventValueReportService $eventValueService = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function mountedCodes(): array
    {
        return self::MOUNTED_REPORT_CODES;
    }

    public function isMounted(string $code): bool
    {
        return \in_array(trim($code), self::MOUNTED_REPORT_CODES, true);
    }

    /**
     * 按 Tab 维度生成 list 下钻附加参数（空维值返回 []）。
     *
     * catalog filters（如 paid Tab 的 traffic_type=paid）一并带入，
     * 使下钻列表与 Tab 聚合口径一致。
     *
     * @param array<string, string> $catalogFilters
     * @return array<string, string>
     */
    public static function drilldownExtras(
        string $dimensionId,
        string $dimensionValue,
        array $catalogFilters = [],
    ): array {
        $dimensionId = trim($dimensionId);
        $dimensionValue = trim($dimensionValue);
        if ($dimensionId === '' || $dimensionValue === '') {
            return [];
        }

        $key = self::DIMENSION_DRILLDOWN_KEYS[$dimensionId] ?? null;
        if ($key === null) {
            return [];
        }

        $extras = [];
        foreach ($catalogFilters as $field => $value) {
            $filterKey = self::DIMENSION_DRILLDOWN_KEYS[(string)$field] ?? null;
            $value = trim((string)$value);
            if ($filterKey === null || $value === '') {
                continue;
            }
            $extras[$filterKey] = $value;
        }
        $extras[$key] = $dimensionValue;

        return $extras;
    }

    /**
     * 构建已挂载 Tab 的引擎数据（可注入 rowProvider；无库单测用）。
     *
     * @param (callable(array): list<array<string, mixed>>)|null $rowProvider
     * @return list<array{
     *   code: string,
     *   label: string,
     *   description: string,
     *   dimension: string,
     *   metrics: list<string>,
     *   filters: array<string, string>,
     *   widget_code: string,
     *   route: array<string, mixed>|null,
     *   rows: list<array<string, mixed>>,
     *   window_clamped: bool,
     *   from: string,
     *   to: string,
     *   error: string
     * }>
     */
    public function buildMountedTabs(
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?int $websiteId = null,
        ?callable $rowProvider = null,
        int $limit = 50,
        ?DateTimeInterface $now = null,
    ): array {
        $tabs = [];
        foreach (self::MOUNTED_REPORT_CODES as $code) {
            $tabs[] = $this->buildTab($code, $from, $to, $websiteId, $rowProvider, $limit, $now);
        }

        return $tabs;
    }

    /**
     * 纯内存：用事件行填充单个已挂载 Tab（不走 router/rowProvider）。
     *
     * @param list<array<string, mixed>> $eventRows
     * @return array{
     *   code: string,
     *   label: string,
     *   description: string,
     *   dimension: string,
     *   metrics: list<string>,
     *   filters: array<string, string>,
     *   widget_code: string,
     *   route: null,
     *   rows: list<array<string, mixed>>,
     *   window_clamped: bool,
     *   from: string,
     *   to: string,
     *   error: string
     * }
     */
    public function buildTabFromEventRows(string $code, array $eventRows): array
    {
        if (!$this->isMounted($code)) {
            throw new InvalidArgumentException('report tab is not mounted: ' . $code);
        }

        $report = $this->catalog()->require($code);
        $filtered = $this->applyCatalogFilters($eventRows, $report['filters']);
        $rows = $this->sortRows($this->queryService()->aggregateRowsByDimension(
            $filtered,
            $report['dimension'],
            $report['metrics']
        ));

        return $this->tabPayload($report, null, $this->decorateRows($report['code'], $rows), false, '', '', '');
    }

    /**
     * @param (callable(array): list<array<string, mixed>>)|null $rowProvider
     * @return array{
     *   code: string,
     *   label: string,
     *   description: string,
     *   dimension: string,
     *   metrics: list<string>,
     *   filters: array<string, string>,
     *   widget_code: string,
     *   route: array<string, mixed>|null,
     *   rows: list<array<string, mixed>>,
     *   window_clamped: bool,
     *   from: string,
     *   to: string,
     *   error: string
     * }
     */
    public function buildTab(
        string $code,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?int $websiteId = null,
        ?callable $rowProvider = null,
        int $limit = 50,
        ?DateTimeInterface $now = null,
    ): array {
        if (!$this->isMounted($code)) {
            throw new InvalidArgumentException('report tab is not mounted: ' . $code);
        }

        $report = $this->catalog()->require($code);
        [$queryFrom, $queryTo, $clamped] = $this->clampToHotWindow($from, $to, $now);

        try {
            // catalog filters（paid/social 等）在热行取回后就地过滤，避免二次取数
            $filters = $report['filters'];
            $wrappedProvider = $rowProvider === null
                ? null
                : function (array $ctx) use ($rowProvider, $filters): array {
                    $ctx['filters'] = $filters;

                    return $this->applyCatalogFilters($rowProvider($ctx), $filters);
                };

            $query = new PixelReportQueryService(
                $this->router(),
                null,
                null,
                $wrappedProvider
            );
            $result = $query->queryBySingleDimension(
                $report['dimension'],
                $report['metrics'],
                $queryFrom,
                $queryTo,
                $websiteId,
                $limit,
                $now
            );

            return $this->tabPayload(
                $report,
                $result['route'],
                $this->decorateRows($report['code'], $result['rows']),
                $clamped,
                $queryFrom->format('Y-m-d H:i:s'),
                $queryTo->format('Y-m-d H:i:s'),
                ''
            );
        } catch (DomainException|InvalidArgumentException|\Throwable $e) {
            return $this->tabPayload(
                $report,
                null,
                [],
                $clamped,
                $queryFrom->format('Y-m-d H:i:s'),
                $queryTo->format('Y-m-d H:i:s'),
                $e->getMessage()
            );
        }
    }

    /**
     * 热路由短窗：超出 7 天时钳到查询结束时刻往前 hotWindowDays。
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: bool}
     */
    public function clampToHotWindow(
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?DateTimeInterface $now = null,
    ): array {
        $fromAt = DateTimeImmutable::createFromInterface($from);
        $toAt = DateTimeImmutable::createFromInterface($to);
        if ($toAt < $fromAt) {
            throw new InvalidArgumentException('query end must not be earlier than query start');
        }

        $windowDays = $this->router()->getHotWindowDays();
        $maxSpan = $windowDays * 86400;
        $span = $toAt->getTimestamp() - $fromAt->getTimestamp();
        $clamped = false;
        if ($span > $maxSpan) {
            $fromAt = $toAt->sub(new DateInterval('P' . $windowDays . 'D'));
            $clamped = true;
        }

        // 亦钳热保留边界（相对 now）
        $nowAt = $now === null
            ? new DateTimeImmutable('now', $fromAt->getTimezone())
            : DateTimeImmutable::createFromInterface($now);
        $retentionDays = $this->router()->getHotRetentionDays();
        $hotBoundary = $nowAt->sub(new DateInterval('P' . $retentionDays . 'D'));
        if ($fromAt < $hotBoundary) {
            $fromAt = $hotBoundary;
            $clamped = true;
            if ($toAt < $fromAt) {
                $toAt = $fromAt;
            }
        }

        return [$fromAt, $toAt, $clamped];
    }

    /**
     * 事件价值 Tab 复用 D06 的 `avg_value` 派生，避免第二套口径。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decorateRows(string $code, array $rows): array
    {
        if ($code !== PixelEventValueReportService::REPORT_CODE) {
            return $rows;
        }

        return $this->eventValueService()->decorateRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $left = (int)($a['events'] ?? 0);
            $right = (int)($b['events'] ?? 0);
            if ($left === $right) {
                return strcmp((string)($a['dimension_value'] ?? ''), (string)($b['dimension_value'] ?? ''));
            }

            return $right <=> $left;
        });

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function applyCatalogFilters(array $rows, array $filters): array
    {
        if ($filters === []) {
            return $rows;
        }

        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $ok = true;
            foreach ($filters as $field => $expected) {
                $actual = (string)($row[$field] ?? '');
                if ($actual !== (string)$expected) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array{
     *   code: string,
     *   label: string,
     *   description: string,
     *   dimension: string,
     *   metrics: list<string>,
     *   filters: array<string, string>,
     *   widget_code: string,
     *   enabled: bool
     * } $report
     * @param array<string, mixed>|null $route
     * @param list<array<string, mixed>> $rows
     * @return array{
     *   code: string,
     *   label: string,
     *   description: string,
     *   dimension: string,
     *   metrics: list<string>,
     *   filters: array<string, string>,
     *   widget_code: string,
     *   route: array<string, mixed>|null,
     *   rows: list<array<string, mixed>>,
     *   window_clamped: bool,
     *   from: string,
     *   to: string,
     *   error: string
     * }
     */
    private function tabPayload(
        array $report,
        ?array $route,
        array $rows,
        bool $clamped,
        string $from,
        string $to,
        string $error,
    ): array {
        return [
            'code' => $report['code'],
            'label' => $report['label'],
            'description' => $report['description'],
            'dimension' => $report['dimension'],
            'metrics' => $report['metrics'],
            'filters' => $report['filters'],
            'widget_code' => $report['widget_code'],
            'route' => $route,
            'rows' => $rows,
            'window_clamped' => $clamped,
            'from' => $from,
            'to' => $to,
            'error' => $error,
        ];
    }

    private function catalog(): PixelReportCatalog
    {
        return $this->catalog ??= new PixelReportCatalog();
    }

    private function queryService(): PixelReportQueryService
    {
        return $this->queryService ??= new PixelReportQueryService();
    }

    private function router(): PixelQueryRouter
    {
        return $this->router ??= new PixelQueryRouter();
    }

    private function eventValueService(): PixelEventValueReportService
    {
        return $this->eventValueService ??= new PixelEventValueReportService(
            $this->catalog(),
            $this->queryService()
        );
    }
}
