<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class PixelDashboardWidgetData
{
    public function __construct(
        private readonly Request $request,
        private readonly Url $url
    ) {
    }

    public function getDashboard(array $config = []): array
    {
        $range = $this->normalizeRange((string)($config['range'] ?? '7d'));
        $filters = ['range' => $range];
        $websiteId = $this->resolveWebsiteId($config);
        // website_id=0 是默认站点，必须与「全部站点」(null) 区分。
        if ($websiteId !== null) {
            $filters['websiteId'] = (string)$websiteId;
        }

        try {
            return PixelStatisticsService::getEventListeningDashboard($filters);
        } catch (\Throwable $e) {
            return $this->emptyDashboard($filters, $e);
        }
    }

    /**
     * 跳转到像素事件列表 / 详情报表（当前站点作用域）。
     *
     * @param array<string, mixed> $config
     */
    public function detailUrl(array $config = []): string
    {
        $query = [];
        $websiteId = $this->resolveWebsiteId($config);
        if ($websiteId !== null) {
            $query['websiteId'] = (string)$websiteId;
        }

        $event = trim((string)($config['event'] ?? ''));
        if ($event !== '') {
            $query['event'] = $event;
        }

        $range = $this->normalizeRange((string)($config['range'] ?? ($config['filters']['range'] ?? '7d')));
        $query['range'] = $range;

        $path = ($websiteId !== null && $event === '')
            ? 'visitor/backend/pixel-dashboard/detail'
            : 'visitor/backend/pixel-dashboard/index';

        // 后台小部件内优先用 path，避免 host/端口与当前后台入口不一致。
        return $this->url->getBackendUrlPath($path, $query);
    }

    public function rangeLabel(string $range): string
    {
        return match ($this->normalizeRange($range)) {
            'today' => (string)__('今日'),
            'yesterday' => (string)__('昨日'),
            '30d' => (string)__('近 30 天'),
            '90d' => (string)__('近 90 天'),
            default => (string)__('近 7 天'),
        };
    }

    /**
     * E01：流量渠道部件数据（catalog `pixel_channels` 走报表引擎）。
     *
     * @param array<string, mixed> $config
     * @return array{
     *   report: string,
     *   label: string,
     *   range: string,
     *   website_id: int|null,
     *   rows: list<array<string, mixed>>,
     *   error: string,
     *   window_clamped: bool,
     *   detail_url: string,
     *   list_url: string
     * }
     */
    public function getChannelsReport(array $config = []): array
    {
        return $this->getCatalogReportWidget('pixel_channels', $config, 8);
    }

    /**
     * E02：流量类型部件数据（catalog `pixel_traffic_type` 走报表引擎）。
     *
     * @param array<string, mixed> $config
     * @return array{
     *   report: string,
     *   label: string,
     *   range: string,
     *   website_id: int|null,
     *   rows: list<array<string, mixed>>,
     *   error: string,
     *   window_clamped: bool,
     *   detail_url: string,
     *   list_url: string
     * }
     */
    public function getTrafficTypeReport(array $config = []): array
    {
        return $this->getCatalogReportWidget('pixel_traffic_type', $config, 8);
    }

    /**
     * E03a：付费广告部件数据（catalog `pixel_paid`：traffic_type=paid，按 utm_campaign）。
     *
     * @param array<string, mixed> $config
     * @return array{
     *   report: string,
     *   label: string,
     *   range: string,
     *   website_id: int|null,
     *   rows: list<array<string, mixed>>,
     *   error: string,
     *   window_clamped: bool,
     *   detail_url: string,
     *   list_url: string
     * }
     */
    public function getPaidReport(array $config = []): array
    {
        return $this->getCatalogReportWidget('pixel_paid', $config, 8);
    }

    /**
     * E03b：社媒流量部件数据（catalog `pixel_social`：traffic_type=social，按 channel_code）。
     *
     * @param array<string, mixed> $config
     * @return array{
     *   report: string,
     *   label: string,
     *   range: string,
     *   website_id: int|null,
     *   rows: list<array<string, mixed>>,
     *   error: string,
     *   window_clamped: bool,
     *   detail_url: string,
     *   list_url: string
     * }
     */
    public function getSocialReport(array $config = []): array
    {
        return $this->getCatalogReportWidget('pixel_social', $config, 8);
    }

    /**
     * E04a：事件价值部件数据（catalog `pixel_event_value`：按 event_name，含 avg_value）。
     *
     * @param array<string, mixed> $config
     * @return array{
     *   report: string,
     *   label: string,
     *   range: string,
     *   website_id: int|null,
     *   rows: list<array<string, mixed>>,
     *   error: string,
     *   window_clamped: bool,
     *   detail_url: string,
     *   list_url: string
     * }
     */
    public function getEventValueReport(array $config = []): array
    {
        return $this->getCatalogReportWidget('pixel_event_value', $config, 8);
    }

    /**
     * E04b：渠道价值部件数据（catalog `pixel_value_by_channel`：按 channel_code，窄指标）。
     *
     * @param array<string, mixed> $config
     * @return array{
     *   report: string,
     *   label: string,
     *   range: string,
     *   website_id: int|null,
     *   rows: list<array<string, mixed>>,
     *   error: string,
     *   window_clamped: bool,
     *   detail_url: string,
     *   list_url: string
     * }
     */
    public function getValueByChannelReport(array $config = []): array
    {
        return $this->getCatalogReportWidget('pixel_value_by_channel', $config, 8);
    }

    /**
     * F05a：电商漏斗部件（F01 字典四步；需站点作用域）。
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function getEcommerceFunnelReport(array $config = []): array
    {
        $base = $this->ecommerceWidgetBase($config, 'ecommerce-funnel');
        if ($base['error'] !== '') {
            return $base;
        }

        try {
            /** @var PixelEcommerceFunnelService $service */
            $service = w_obj(PixelEcommerceFunnelService::class);
            $report = $service->buildForWebsite(
                (int)$base['website_id'],
                (string)$base['start_date'],
                (string)$base['end_date']
            );
            $base['steps'] = is_array($report['steps'] ?? null) ? $report['steps'] : [];
            $base['step1_sessions'] = (int)($report['step1_sessions'] ?? 0);
            $base['scored_sessions'] = (int)($report['scored_sessions'] ?? 0);
            $base['window_clamped'] = !empty($report['window_clamped']) || !empty($base['window_clamped']);
            $base['error'] = (string)($report['error'] ?? '');
        } catch (\Throwable $e) {
            $base['error'] = $e->getMessage();
        }

        return $base;
    }

    /**
     * F05a：购成与收入部件（F02；需站点作用域）。
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function getEcommerceRevenueReport(array $config = []): array
    {
        $base = $this->ecommerceWidgetBase($config, 'ecommerce-revenue');
        if ($base['error'] !== '') {
            return $base;
        }

        try {
            /** @var PixelEcommercePurchaseRevenueService $service */
            $service = w_obj(PixelEcommercePurchaseRevenueService::class);
            $report = $service->buildForWebsite(
                (int)$base['website_id'],
                (string)$base['start_date'],
                (string)$base['end_date']
            );
            foreach ([
                'purchases',
                'purchase_revenue',
                'avg_order_value',
                'purchase_sessions',
                'view_item_sessions',
                'purchase_rate_from_view_item',
            ] as $key) {
                $base[$key] = $report[$key] ?? ($key === 'purchases' || str_contains($key, 'sessions') ? 0 : 0.0);
            }
            $byChannel = is_array($report['by_channel'] ?? null) ? $report['by_channel'] : [];
            $base['by_channel'] = array_slice($byChannel, 0, 5);
            $base['window_clamped'] = !empty($report['window_clamped']) || !empty($base['window_clamped']);
            $base['error'] = (string)($report['error'] ?? '');
        } catch (\Throwable $e) {
            $base['error'] = $e->getMessage();
        }

        return $base;
    }

    /**
     * F05a：商品表现部件（F03；需站点作用域）。
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function getEcommerceItemsReport(array $config = [], int $limit = 5): array
    {
        $base = $this->ecommerceWidgetBase($config, 'ecommerce-items');
        if ($base['error'] !== '') {
            return $base;
        }

        try {
            /** @var PixelEcommerceItemPerformanceService $service */
            $service = w_obj(PixelEcommerceItemPerformanceService::class);
            $report = $service->buildForWebsite(
                (int)$base['website_id'],
                (string)$base['start_date'],
                (string)$base['end_date']
            );
            $items = is_array($report['items'] ?? null) ? $report['items'] : [];
            $base['items'] = array_slice($items, 0, max(1, $limit));
            $base['item_count'] = (int)($report['item_count'] ?? \count($items));
            $base['window_clamped'] = !empty($report['window_clamped']) || !empty($base['window_clamped']);
            $base['error'] = (string)($report['error'] ?? '');
        } catch (\Throwable $e) {
            $base['error'] = $e->getMessage();
        }

        return $base;
    }

    /**
     * 电商部件公共壳：站点作用域 + 日期窗 + 下钻 URL（detail 锚点）。
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function ecommerceWidgetBase(array $config, string $detailAnchor = ''): array
    {
        $range = $this->normalizeRange((string)($config['range'] ?? '7d'));
        $websiteId = $this->resolveWebsiteId($config);
        $detailUrl = $this->detailUrl(array_merge($config, [
            'range' => $range,
            'website_id' => $websiteId,
        ]));
        if ($detailAnchor !== '' && $websiteId !== null) {
            $detailUrl .= '#' . ltrim($detailAnchor, '#');
        }
        $listUrl = $this->listUrl([
            'range' => $range,
            'website_id' => $websiteId,
        ]);

        $base = [
            'range' => $range,
            'website_id' => $websiteId,
            'start_date' => '',
            'end_date' => '',
            'window_clamped' => false,
            'detail_url' => $detailUrl,
            'list_url' => $listUrl,
            'steps' => [],
            'step1_sessions' => 0,
            'scored_sessions' => 0,
            'purchases' => 0,
            'purchase_revenue' => 0.0,
            'avg_order_value' => 0.0,
            'purchase_sessions' => 0,
            'view_item_sessions' => 0,
            'purchase_rate_from_view_item' => 0.0,
            'by_channel' => [],
            'items' => [],
            'item_count' => 0,
            'error' => '',
        ];

        if ($websiteId === null) {
            $base['error'] = 'website scope required';

            return $base;
        }

        try {
            $filters = PixelStatisticsService::normalizeDashboardFilters([
                'range' => $range,
                'websiteId' => (string)$websiteId,
            ]);
            $base['start_date'] = (string)($filters['start_date'] ?? '');
            $base['end_date'] = (string)($filters['end_date'] ?? '');
            if ($base['start_date'] === '' || $base['end_date'] === '') {
                $base['error'] = 'missing date range';
            }
        } catch (\Throwable $e) {
            $base['error'] = $e->getMessage();
        }

        return $base;
    }

    /**
     * 按 catalog code 取单维报表（当前仅已挂载的 detail Tab code）。
     *
     * @param array<string, mixed> $config
     * @return array{
     *   report: string,
     *   label: string,
     *   range: string,
     *   website_id: int|null,
     *   rows: list<array<string, mixed>>,
     *   error: string,
     *   window_clamped: bool,
     *   detail_url: string,
     *   list_url: string
     * }
     */
    public function getCatalogReportWidget(string $reportCode, array $config = [], int $limit = 8): array
    {
        $range = $this->normalizeRange((string)($config['range'] ?? '7d'));
        $websiteId = $this->resolveWebsiteId($config);
        $detailUrl = $this->detailUrl(array_merge($config, [
            'range' => $range,
            'website_id' => $websiteId,
        ]));
        $listUrl = $this->listUrl([
            'range' => $range,
            'website_id' => $websiteId,
        ]);

        $empty = [
            'report' => $reportCode,
            'label' => $reportCode,
            'range' => $range,
            'website_id' => $websiteId,
            'rows' => [],
            'error' => '',
            'window_clamped' => false,
            'detail_url' => $detailUrl,
            'list_url' => $listUrl,
        ];

        try {
            $tabService = w_obj(\Weline\Visitor\Service\Report\PixelDetailReportTabService::class);
            if (!$tabService->isMounted($reportCode)) {
                $empty['error'] = 'report tab is not mounted: ' . $reportCode;

                return $empty;
            }

            $filters = PixelStatisticsService::normalizeDashboardFilters([
                'range' => $range,
                'websiteId' => $websiteId !== null ? (string)$websiteId : null,
            ]);
            $from = new \DateTimeImmutable((string)$filters['start_date']);
            $to = new \DateTimeImmutable((string)$filters['end_date']);

            $rowProvider = static function (array $ctx) use ($filters, $websiteId): array {
                $scoped = $filters;
                if ($websiteId !== null) {
                    $scoped['website_id'] = $websiteId;
                }
                $route = $ctx['route'] ?? [];
                if (isset($route['from'], $route['to'])) {
                    $scoped['start_date'] = (string)$route['from'];
                    $scoped['end_date'] = (string)$route['to'];
                }

                return PixelStatisticsService::fetchHotReportEventRows($scoped, 20000);
            };

            $tab = $tabService->buildTab($reportCode, $from, $to, $websiteId, $rowProvider, $limit);

            return [
                'report' => $reportCode,
                'label' => (string)($tab['label'] ?? $reportCode),
                'range' => $range,
                'website_id' => $websiteId,
                'rows' => array_slice($tab['rows'] ?? [], 0, max(1, $limit)),
                'error' => (string)($tab['error'] ?? ''),
                'window_clamped' => !empty($tab['window_clamped']),
                'detail_url' => $detailUrl,
                'list_url' => $listUrl,
                'dimension' => (string)($tab['dimension'] ?? ''),
                'filters' => is_array($tab['filters'] ?? null) ? $tab['filters'] : [],
            ];
        } catch (\Throwable $e) {
            $empty['error'] = $e->getMessage();

            return $empty;
        }
    }

    /**
     * 事件列表下钻 URL（可附 channel_code / traffic_type 等）。
     *
     * @param array<string, mixed> $config
     * @param array<string, string> $extras
     */
    public function listUrl(array $config = [], array $extras = []): string
    {
        $range = $this->normalizeRange((string)($config['range'] ?? '7d'));
        $websiteId = $this->resolveWebsiteId($config);
        $base = [
            'range' => $range,
        ];
        if ($websiteId !== null) {
            $base['websiteId'] = (string)$websiteId;
        }

        $query = PixelStatisticsService::buildListDrilldownQuery($base, $extras);

        return $this->url->getBackendUrlPath('visitor/backend/pixel-dashboard/list', $query);
    }

    /**
     * 渠道行下钻：维值 + catalog filters → list。
     *
     * @param array<string, mixed> $config
     * @param array<string, string> $catalogFilters
     */
    public function channelDrilldownUrl(
        array $config,
        string $dimensionId,
        string $dimensionValue,
        array $catalogFilters = [],
    ): string {
        $extras = \Weline\Visitor\Service\Report\PixelDetailReportTabService::drilldownExtras(
            $dimensionId,
            $dimensionValue,
            $catalogFilters
        );
        if ($extras === []) {
            return $this->listUrl($config);
        }

        return $this->listUrl($config, $extras);
    }

    public function formatNumber(int|float|string|null $value, int $decimals = 0): string
    {
        return number_format((float)($value ?? 0), $decimals);
    }

    public function formatPercent(int|float|string|null $value): string
    {
        return rtrim(rtrim(number_format((float)($value ?? 0), 2), '0'), '.') . '%';
    }

    private function normalizeRange(string $range): string
    {
        $range = trim($range);
        return in_array($range, ['today', 'yesterday', '7d', '30d', '90d'], true) ? $range : '7d';
    }

    private function resolveWebsiteId(array $config): ?int
    {
        foreach (['website_id', 'websiteId', 'dashboard_website_id'] as $key) {
            $value = $config[$key] ?? null;
            if ($this->isResolvableWebsiteId($value)) {
                return (int)$value;
            }
        }

        foreach (['website_id', 'target_id', 'theme_layout_target_id', 'theme_layout_source_target_id'] as $key) {
            $value = $this->request->getParam($key, null);
            if ($this->isResolvableWebsiteId($value)) {
                return (int)$value;
            }
        }

        return null;
    }

    private function isResolvableWebsiteId(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === 'all') {
            return false;
        }
        if (!is_numeric($value)) {
            return false;
        }

        return (int)$value >= 0;
    }

    private function emptyDashboard(array $filters, ?\Throwable $error = null): array
    {
        return [
            'filters' => $filters,
            'summary' => [
                'total_events' => 0,
                'active_sites' => 0,
                'event_types' => 0,
                'active_users' => 0,
                'total_value' => 0,
                'avg_value' => 0,
                'un_deal_count' => 0,
                'dealed_count' => 0,
                'value_event_count' => 0,
                'event_change' => 0,
                'events_per_user' => 0,
                'value_event_rate' => 0,
                'processed_rate' => 0,
                'last_seen' => null,
            ],
            'trend' => [],
            'event_rows' => [],
            'site_rows' => [],
            'source_rows' => [],
            'realtime_rows' => [],
            'recent_events' => [],
            'widget_error' => $error ? $error->getMessage() : '',
        ];
    }
}
