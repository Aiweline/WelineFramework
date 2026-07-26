<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\PixelStatisticsCache;

/**
 * 像素统计服务层
 * 
 * 功能：
 * - 封装统计逻辑
 * - 统一错误处理
 * - 数据格式化
 * - 缓存管理
 */
class PixelStatisticsService
{
    private const DASHBOARD_ALLOWED_RANGES = ['today', 'yesterday', '7d', '30d', '90d', 'custom'];

    /**
     * 获取站点统计摘要（带缓存）
     * 
     * @param int $websiteId 站点ID
     * @return array
     */
    public static function getWebsiteSummary(int $websiteId): array
    {
        try {
            return PixelStatisticsCache::getWebsiteSummary($websiteId, function() use ($websiteId) {
                return Pixel::getWebsiteSummary($websiteId);
            });
        } catch (\Exception $e) {
            throw new \Exception(__('获取站点统计摘要失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 获取趋势数据（带缓存）
     * 
     * @param int|null $websiteId 站点ID，null表示所有站点
     * @param int $days 天数，默认7天
     * @return array
     */
    public static function getTrends(?int $websiteId = null, int $days = 7): array
    {
        try {
            return PixelStatisticsCache::getTrends($websiteId, $days, function() use ($websiteId, $days) {
                $trends = [];
                $endDate = date('Y-m-d H:i:s');
                $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
                
                $websiteIds = $websiteId !== null ? [$websiteId] : Pixel::getAllWebsiteIds();
                
                for ($i = $days - 1; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-{$i} days"));
                    $dayStart = $date . ' 00:00:00';
                    $dayEnd = $date . ' 23:59:59';
                    
                    $dayCount = 0;
                    $dayValue = 0;
                    
                    foreach ($websiteIds as $siteId) {
                        $dayStats = Pixel::getWebsiteStatsByDateRange($siteId, $dayStart, $dayEnd);
                        $dayCount += $dayStats['total_count'] ?? 0;
                        
                        // 计算当天的总价值
                        $pixels = Pixel::getPixelsByWebsiteId($siteId, [
                            Pixel::schema_fields_CREATED_AT => [
                                'operator' => '>=',
                                'value' => $dayStart
                            ]
                        ]);
                        
                        $pixels = array_filter($pixels, function($pixel) use ($dayEnd) {
                            return ($pixel[Pixel::schema_fields_CREATED_AT] ?? '') <= $dayEnd;
                        });
                        
                        foreach ($pixels as $pixel) {
                            $dayValue += (float)($pixel[Pixel::schema_fields_VALUE] ?? 0);
                        }
                    }
                    
                    $trends[] = [
                        'date' => $date,
                        'count' => $dayCount,
                        'value' => $dayValue
                    ];
                }
                
                return $trends;
            });
        } catch (\Exception $e) {
            throw new \Exception(__('获取趋势数据失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 按 IP 过滤的 UV（独立访客数）与 PV（页面浏览量）
     * UV = 时间范围内去重 IP 数；PV = 同一范围内像素记录条数（即 UV 访问产生的路径浏览次数）
     *
     * @param int $websiteId 站点ID
     * @param string|null $startDate 开始时间 Y-m-d H:i:s
     * @param string|null $endDate 结束时间 Y-m-d H:i:s
     * @return array{uv: int, pv: int}
     */
    public static function getUvPvByDateRange(int $websiteId, ?string $startDate = null, ?string $endDate = null): array
    {
        $uv = Pixel::getUvCountByDateRange($websiteId, $startDate, $endDate);
        $pv = Pixel::getPvCountByDateRange($websiteId, $startDate, $endDate);
        return ['uv' => $uv, 'pv' => $pv];
    }
    
    /**
     * 获取事件统计（带缓存）
     *
     * @param int $websiteId 站点ID
     * @param string|null $event 事件名，null表示所有事件
     * @param string|null $startDate 开始日期
     * @param string|null $endDate 结束日期
     * @return array|int
     */
    public static function getEventStats(int $websiteId, ?string $event = null, ?string $startDate = null, ?string $endDate = null)
    {
        try {
            return PixelStatisticsCache::getEventStats($websiteId, $event, function() use ($websiteId, $event, $startDate, $endDate) {
                if ($event !== null) {
                    // 单个事件统计
                    $model = w_obj(Pixel::class)->reset()
                        ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                        ->where(Pixel::schema_fields_EVENT, $event);
                    
                    if ($startDate) {
                        $model->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=');
                    }
                    if ($endDate) {
                        $model->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');
                    }
                    
                    return (int)$model->count();
                } else {
                    // 所有事件统计
                    $eventList = Pixel::getEventsByWebsiteId($websiteId);
                    $eventStats = [];
                    
                    foreach ($eventList as $evt) {
                        $model = w_obj(Pixel::class)->reset()
                            ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                            ->where(Pixel::schema_fields_EVENT, $evt);
                        
                        if ($startDate) {
                            $model->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=');
                        }
                        if ($endDate) {
                            $model->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');
                        }
                        
                        $count = (int)$model->count();
                        if ($count > 0) {
                            $eventStats[$evt] = $count;
                        }
                    }
                    
                    return $eventStats;
                }
            });
        } catch (\Exception $e) {
            throw new \Exception(__('获取事件统计失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 获取实时数据（带缓存）
     * 
     * @param int $websiteId 站点ID
     * @param int $interval 时间间隔（分钟），10或30
     * @param int $hours 获取最近N小时的数据
     * @return array
     */
    public static function getRealtimeData(int $websiteId, int $interval = 10, int $hours = 24): array
    {
        try {
            return PixelStatisticsCache::getRealtimeData($websiteId, $interval, $hours, function() use ($websiteId, $interval, $hours) {
                return Pixel::getDashboardData($websiteId, $interval, $hours);
            });
        } catch (\Exception $e) {
            throw new \Exception(__('获取实时数据失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 获取商业价值数据（带缓存）
     * 
     * @param int $websiteId 站点ID
     * @param string $period 时间维度：daily, weekly, monthly, quarterly, yearly
     * @param string|null $startDate 开始日期
     * @param string|null $endDate 结束日期
     * @return array
     */
    public static function getBusinessValue(int $websiteId, string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        try {
            return PixelStatisticsCache::getBusinessValue($websiteId, $period, $startDate, $endDate, function() use ($websiteId, $period, $startDate, $endDate) {
                return Pixel::getBusinessValueByPeriod($websiteId, $period, $startDate, $endDate);
            });
        } catch (\Exception $e) {
            throw new \Exception(__('获取商业价值数据失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 获取每日对比数据
     * 
     * @param int $websiteId 站点ID
     * @param int $days 获取最近N天的对比数据
     * @return array
     */
    public static function getDailyComparison(int $websiteId, int $days = 7): array
    {
        try {
            return Pixel::getDailyComparisonData($websiteId, $days);
        } catch (\Exception $e) {
            throw new \Exception(__('获取每日对比数据失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 获取热门事件Top N
     * 
     * @param int $websiteId 站点ID
     * @param int $limit 返回前N个事件
     * @param string|null $startDate 开始日期
     * @param string|null $endDate 结束日期
     * @return array
     */
    public static function getTopEvents(int $websiteId, int $limit = 10, ?string $startDate = null, ?string $endDate = null): array
    {
        try {
            $eventList = Pixel::getEventsByWebsiteId($websiteId);
            $eventStats = [];
            
            foreach ($eventList as $event) {
                $model = w_obj(Pixel::class)->reset()
                    ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(Pixel::schema_fields_EVENT, $event);
                
                if ($startDate) {
                    $model->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=');
                }
                if ($endDate) {
                    $model->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');
                }
                
                $count = (int)$model->count();
                if ($count > 0) {
                    $eventStats[$event] = $count;
                }
            }
            
            // 按数量排序
            arsort($eventStats);
            
            // 取前N个
            return array_slice($eventStats, 0, $limit, true);
        } catch (\Exception $e) {
            throw new \Exception(__('获取热门事件失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 获取后台多站点事件监听看板数据。
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function getEventListeningDashboard(array $filters = []): array
    {
        $normalizedFilters = self::normalizeDashboardFilters($filters);
        $summary = self::getDashboardSummary($normalizedFilters);
        $previousSummary = self::getPreviousDashboardSummary($normalizedFilters);

        $summary['previous_total_events'] = (int)($previousSummary['total_events'] ?? 0);
        $summary['event_change'] = self::calculateChangeRate(
            (int)($summary['total_events'] ?? 0),
            (int)($previousSummary['total_events'] ?? 0)
        );
        $summary['events_per_user'] = (int)$summary['active_users'] > 0
            ? round((int)$summary['total_events'] / (int)$summary['active_users'], 2)
            : 0.0;
        $summary['value_event_rate'] = (int)$summary['total_events'] > 0
            ? round(((int)$summary['value_event_count'] / (int)$summary['total_events']) * 100, 2)
            : 0.0;
        $summary['processed_rate'] = (int)$summary['total_events'] > 0
            ? round(((int)$summary['dealed_count'] / (int)$summary['total_events']) * 100, 2)
            : 0.0;

        return [
            'filters' => $normalizedFilters,
            'website_options' => self::getWebsiteFilterOptions(),
            'event_options' => self::getEventFilterOptions($normalizedFilters['website_id']),
            'summary' => $summary,
            'trend' => self::getDashboardTrend($normalizedFilters),
            'event_rows' => self::getDashboardEventRows($normalizedFilters, 20),
            'site_rows' => self::getDashboardSiteRows($normalizedFilters, 100),
            'source_rows' => self::getDashboardSourceRows($normalizedFilters, 10),
            // C05：按 channel_code 聚合 + pixel_channel name join
            'channel_rows' => self::getDashboardChannelRows($normalizedFilters, 15),
            'realtime_rows' => self::safeDashboardRealtimeRows($normalizedFilters, 10, 6),
            'recent_events' => self::getDashboardRecentEvents($normalizedFilters, 25),
        ];
    }

    public const LIST_DEFAULT_PAGE_SIZE = 50;
    public const LIST_MAX_PAGE_SIZE = 200;

    /**
     * C02/C03a：看板 list 热表分页（含 channel/traffic_type/utm_* WHERE）。
     *
     * @param array<string, mixed> $filters
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   page_size: int,
     *   page_count: int,
     *   filters: array<string, mixed>,
     *   error: string
     * }
     */
    public static function getDashboardEventListPage(array $filters = [], int $page = 1, int $pageSize = self::LIST_DEFAULT_PAGE_SIZE): array
    {
        [$page, $pageSize] = self::normalizeListPagination($page, $pageSize);
        try {
            $normalized = self::normalizeDashboardFilters($filters);
        } catch (\Throwable $throwable) {
            return [
                'rows' => [],
                'total' => 0,
                'page' => $page,
                'page_size' => $pageSize,
                'page_count' => 0,
                'filters' => [],
                'error' => $throwable->getMessage(),
            ];
        }

        try {
            [$whereSql, $params] = self::buildDashboardWhere($normalized, 'p');
            $table = self::tableSql('p');
            $totalRow = self::fetchOne("SELECT COUNT(*) AS cnt FROM {$table} WHERE {$whereSql}", $params);
            $total = (int)($totalRow['cnt'] ?? 0);
            $pageCount = $total > 0 ? (int)\ceil($total / $pageSize) : 0;
            if ($pageCount > 0 && $page > $pageCount) {
                $page = $pageCount;
            }
            $offset = ($page - 1) * $pageSize;

            $pixelId = self::col(Pixel::schema_fields_ID);
            $eventTime = self::eventTimeExpression('p');
            $select = [
                "{$pixelId} AS pixel_id",
                self::col(Pixel::schema_fields_WEBSITE_ID) . ' AS website_id',
                self::col(Pixel::schema_fields_EVENT) . ' AS event',
                self::col(Pixel::schema_fields_URL) . ' AS url',
                self::col(Pixel::schema_fields_IP) . ' AS ip',
                self::col(Pixel::schema_fields_SOURCE) . ' AS source',
                self::col(Pixel::schema_fields_VALUE) . ' AS value',
                "{$eventTime} AS created_at",
            ];
            if (self::hasPixelAttributionColumns()) {
                foreach (['session_id', 'channel_code', 'channel_name', 'traffic_type', 'utm_source', 'utm_medium', 'utm_campaign'] as $field) {
                    $select[] = self::col($field) . ' AS ' . $field;
                }
            }

            $limitSql = self::getPdoDriver() === 'mysql'
                ? "LIMIT {$offset}, {$pageSize}"
                : "LIMIT {$pageSize} OFFSET {$offset}";

            $rawRows = self::fetchRows(
                'SELECT ' . implode(",\n                ", $select) . "
                FROM {$table}
                WHERE {$whereSql}
                ORDER BY {$eventTime} DESC, {$pixelId} DESC
                {$limitSql}",
                $params
            );

            /** @var \Weline\Visitor\Service\PixelAttributionRowResolver $resolver */
            $resolver = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Visitor\Service\PixelAttributionRowResolver::class
            );

            $rows = array_map(static function (array $row) use ($resolver): array {
                $resolved = $resolver->resolve($row);

                return [
                    'pixel_id' => (int)($row['pixel_id'] ?? 0),
                    'website_id' => (int)($row['website_id'] ?? 0),
                    'event' => (string)($row['event'] ?? ''),
                    'url' => (string)($row['url'] ?? ''),
                    'ip' => (string)($row['ip'] ?? ''),
                    'source' => (string)($resolved['source_label'] ?? $row['source'] ?? ''),
                    'channel_code' => (string)($resolved['channel_code'] ?? ''),
                    'traffic_type' => (string)($resolved['traffic_type'] ?? ''),
                    'session_id' => (string)($resolved['session_id'] ?? ''),
                    'utm_source' => (string)($resolved['utm_source'] ?? ''),
                    'utm_medium' => (string)($resolved['utm_medium'] ?? ''),
                    'utm_campaign' => (string)($resolved['utm_campaign'] ?? ''),
                    'value' => (float)($row['value'] ?? 0),
                    'created_at' => $row['created_at'] ?? null,
                ];
            }, $rawRows);

            return [
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'page_count' => $pageCount,
                'filters' => $normalized,
                'error' => '',
            ];
        } catch (\Throwable $throwable) {
            return [
                'rows' => [],
                'total' => 0,
                'page' => $page,
                'page_size' => $pageSize,
                'page_count' => 0,
                'filters' => $normalized,
                'error' => $throwable->getMessage(),
            ];
        }
    }

    public const EXPORT_MAX_ROWS = 10000;

    /**
     * C04：导出列固定顺序（含归因列；browser_info 保留在末尾兼容旧导出）。
     *
     * @var list<string>
     */
    public const EXPORT_COLUMNS = [
        'pixel_id',
        'created_at',
        'website_id',
        'event',
        'name',
        'module',
        'url',
        'referer',
        'source',
        'session_id',
        'channel_code',
        'channel_name',
        'traffic_type',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'value',
        'currency',
        'lang',
        'ip',
        'user_id',
        'user_agent',
        'cron_deal',
        'browser_info',
    ];

    /**
     * C04：与 list 同筛选条件的明细导出行（含归因列）。
     *
     * @param array<string, mixed> $filters
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   columns: list<string>,
     *   filters: array<string, mixed>,
     *   error: string
     * }
     */
    public static function getDashboardEventExportRows(array $filters = [], int $limit = self::EXPORT_MAX_ROWS): array
    {
        $limit = max(1, min(self::EXPORT_MAX_ROWS, $limit));
        try {
            $normalized = self::normalizeDashboardFilters($filters);
        } catch (\Throwable $throwable) {
            return [
                'rows' => [],
                'columns' => self::EXPORT_COLUMNS,
                'filters' => [],
                'error' => $throwable->getMessage(),
            ];
        }

        try {
            [$whereSql, $params] = self::buildDashboardWhere($normalized, 'p');
            $table = self::tableSql('p');
            $pixelId = self::col(Pixel::schema_fields_ID);
            $eventTime = self::eventTimeExpression('p');

            $select = ["{$pixelId} AS pixel_id", "{$eventTime} AS created_at"];
            foreach ([
                Pixel::schema_fields_WEBSITE_ID,
                Pixel::schema_fields_EVENT,
                Pixel::schema_fields_NAME,
                Pixel::schema_fields_MODULE,
                Pixel::schema_fields_URL,
                Pixel::schema_fields_REFERER,
                Pixel::schema_fields_SOURCE,
                Pixel::schema_fields_VALUE,
                Pixel::schema_fields_CURRENCY,
                Pixel::schema_fields_LANG,
                Pixel::schema_fields_IP,
                Pixel::schema_fields_USER_ID,
                Pixel::schema_fields_USER_AGENT,
                Pixel::schema_fields_CRON_DEAL,
                Pixel::schema_fields_BROWSER_INFO,
            ] as $field) {
                $select[] = self::col($field) . ' AS ' . $field;
            }
            if (self::hasPixelAttributionColumns()) {
                foreach (['session_id', 'channel_code', 'channel_name', 'traffic_type', 'utm_source', 'utm_medium', 'utm_campaign'] as $field) {
                    $select[] = self::col($field) . ' AS ' . $field;
                }
            }

            $limitSql = self::getPdoDriver() === 'mysql'
                ? "LIMIT 0, {$limit}"
                : "LIMIT {$limit} OFFSET 0";

            $rawRows = self::fetchRows(
                'SELECT ' . implode(",\n                ", $select) . "
                FROM {$table}
                WHERE {$whereSql}
                ORDER BY {$eventTime} DESC, {$pixelId} DESC
                {$limitSql}",
                $params
            );

            /** @var \Weline\Visitor\Service\PixelAttributionRowResolver $resolver */
            $resolver = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Visitor\Service\PixelAttributionRowResolver::class
            );

            $rows = array_map(static function (array $row) use ($resolver): array {
                $resolved = $resolver->resolve($row);
                $merged = $row + [
                    'session_id' => '',
                    'channel_code' => '',
                    'channel_name' => '',
                    'traffic_type' => '',
                    'utm_source' => '',
                    'utm_medium' => '',
                    'utm_campaign' => '',
                ];
                foreach (['session_id', 'channel_code', 'channel_name', 'traffic_type', 'utm_source', 'utm_medium', 'utm_campaign'] as $field) {
                    if (trim((string)$merged[$field]) === '') {
                        $merged[$field] = (string)($resolved[$field] ?? '');
                    }
                }
                if (trim((string)($merged['source'] ?? '')) === '') {
                    $merged['source'] = (string)($resolved['source_label'] ?? '');
                }

                $ordered = [];
                foreach (self::EXPORT_COLUMNS as $column) {
                    $ordered[$column] = $merged[$column] ?? '';
                }

                return $ordered;
            }, $rawRows);

            return [
                'rows' => $rows,
                'columns' => self::EXPORT_COLUMNS,
                'filters' => $normalized,
                'error' => '',
            ];
        } catch (\Throwable $throwable) {
            return [
                'rows' => [],
                'columns' => self::EXPORT_COLUMNS,
                'filters' => $normalized,
                'error' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @return array{0: int, 1: int} [page, page_size]
     */
    public static function normalizeListPagination(int $page, int $pageSize): array
    {
        $page = max(1, $page);
        if ($pageSize <= 0) {
            $pageSize = self::LIST_DEFAULT_PAGE_SIZE;
        }
        $pageSize = max(1, min(self::LIST_MAX_PAGE_SIZE, $pageSize));

        return [$page, $pageSize];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string>>
     */
    private static function safeDashboardRealtimeRows(array $filters, int $intervalMinutes, int $slots): array
    {
        try {
            return self::getDashboardRealtimeRows($filters, $intervalMinutes, $slots);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     website_id: int|null,
     *     website_id_raw: string,
     *     event: string|null,
     *     range: string,
     *     start_date: string,
     *     end_date: string,
     *     start_day: string,
     *     end_day: string,
     *     day_count: int,
     *     channel_code: string|null,
     *     traffic_type: string|null,
     *     utm_source: string|null,
     *     utm_medium: string|null,
     *     utm_campaign: string|null
     * }
     */
    public static function normalizeDashboardFilters(array $filters = []): array
    {
        $websiteRaw = $filters['website_id'] ?? $filters['websiteId'] ?? null;
        $websiteId = null;
        $websiteIdRaw = '';

        if ($websiteRaw !== null && $websiteRaw !== '' && $websiteRaw !== 'all') {
            if (!is_numeric($websiteRaw)) {
                throw new \InvalidArgumentException((string)__('站点筛选参数无效'));
            }
            $websiteId = (int)$websiteRaw;
            if ($websiteId < 0) {
                throw new \InvalidArgumentException((string)__('站点筛选参数无效'));
            }
            $websiteIdRaw = (string)$websiteId;
        }

        $event = trim((string)($filters['event'] ?? ''));
        if ($event === '') {
            $event = null;
        } elseif (strlen($event) > 255) {
            throw new \InvalidArgumentException((string)__('事件筛选参数过长'));
        }

        $range = trim((string)($filters['range'] ?? '30d'));
        if ($range === '') {
            $range = '30d';
        }
        if (!in_array($range, self::DASHBOARD_ALLOWED_RANGES, true)) {
            throw new \InvalidArgumentException((string)__('时间范围参数无效'));
        }

        $startRaw = trim((string)($filters['startDate'] ?? $filters['start_date'] ?? ''));
        $endRaw = trim((string)($filters['endDate'] ?? $filters['end_date'] ?? ''));

        if ($range === 'custom' || $startRaw !== '' || $endRaw !== '') {
            if ($startRaw === '' || $endRaw === '') {
                throw new \InvalidArgumentException((string)__('自定义时间范围需要开始日期和结束日期'));
            }
            $startDate = self::normalizeDashboardDate($startRaw, false);
            $endDate = self::normalizeDashboardDate($endRaw, true);
            $range = 'custom';
        } else {
            [$startDate, $endDate] = self::resolvePresetDateRange($range);
        }

        if (strtotime($startDate) > strtotime($endDate)) {
            throw new \InvalidArgumentException((string)__('开始日期不能晚于结束日期'));
        }

        $startDay = substr($startDate, 0, 10);
        $endDay = substr($endDate, 0, 10);
        $dayCount = self::countDays($startDay, $endDay);
        if ($dayCount > 366) {
            throw new \InvalidArgumentException((string)__('时间范围不能超过 366 天'));
        }

        $attribution = self::normalizeAttributionFilters($filters);

        return [
            'website_id' => $websiteId,
            'website_id_raw' => $websiteIdRaw,
            'event' => $event,
            'range' => $range,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_day' => $startDay,
            'end_day' => $endDay,
            'day_count' => $dayCount,
            'channel_code' => $attribution['channel_code'],
            'traffic_type' => $attribution['traffic_type'],
            'utm_source' => $attribution['utm_source'],
            'utm_medium' => $attribution['utm_medium'],
            'utm_campaign' => $attribution['utm_campaign'],
        ];
    }

    /**
     * C03a：归一化归因筛选（空串视为未筛）。
     *
     * @param array<string, mixed> $filters
     * @return array{
     *   channel_code: string|null,
     *   traffic_type: string|null,
     *   utm_source: string|null,
     *   utm_medium: string|null,
     *   utm_campaign: string|null
     * }
     */
    public static function normalizeAttributionFilters(array $filters): array
    {
        $channelCode = self::normalizeOptionalStringFilter(
            $filters['channel_code'] ?? $filters['channelCode'] ?? null,
            64,
            (string)__('渠道码筛选参数过长')
        );
        $trafficType = self::normalizeOptionalStringFilter(
            $filters['traffic_type'] ?? $filters['trafficType'] ?? null,
            32,
            (string)__('流量类型筛选参数过长')
        );
        if ($trafficType !== null && !\in_array($trafficType, PixelChannel::TRAFFIC_TYPES, true)) {
            throw new \InvalidArgumentException((string)__('流量类型筛选参数无效'));
        }

        return [
            'channel_code' => $channelCode,
            'traffic_type' => $trafficType,
            'utm_source' => self::normalizeOptionalStringFilter(
                $filters['utm_source'] ?? $filters['utmSource'] ?? null,
                255,
                (string)__('utm_source 筛选参数过长')
            ),
            'utm_medium' => self::normalizeOptionalStringFilter(
                $filters['utm_medium'] ?? $filters['utmMedium'] ?? null,
                255,
                (string)__('utm_medium 筛选参数过长')
            ),
            'utm_campaign' => self::normalizeOptionalStringFilter(
                $filters['utm_campaign'] ?? $filters['utmCampaign'] ?? null,
                255,
                (string)__('utm_campaign 筛选参数过长')
            ),
        ];
    }

    /**
     * 是否带有任一归因筛选（供 list/看板判断）。
     *
     * @param array<string, mixed> $filters 已归一化或原始均可
     */
    public static function hasAttributionFilter(array $filters): bool
    {
        foreach (['channel_code', 'traffic_type', 'utm_source', 'utm_medium', 'utm_campaign'] as $key) {
            $value = $filters[$key] ?? null;
            if (\is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * C07：list 下钻 URL 契约键（须与 list 表单 name / getDashboardRequestFilters 一致）。
     *
     * @var list<string>
     */
    public const LIST_DRILLDOWN_QUERY_KEYS = [
        'websiteId',
        'event',
        'range',
        'startDate',
        'endDate',
        'channel_code',
        'traffic_type',
        'utm_source',
        'utm_medium',
        'utm_campaign',
    ];

    /**
     * C06/C07：构造 list 下钻 query（与 list 表单/filters 字段名一致；空串剔除）。
     *
     * @param array<string, mixed> $filters 归一化或原始均可
     * @param array<string, mixed> $extra   覆盖项（如 channel_code / traffic_type）
     * @return array<string, string>
     */
    public static function buildListDrilldownQuery(array $filters = [], array $extra = []): array
    {
        $merged = array_merge($filters, $extra);

        $websiteRaw = $merged['websiteId']
            ?? $merged['website_id_raw']
            ?? $merged['website_id']
            ?? '';
        if ($websiteRaw === null || $websiteRaw === 'all') {
            $websiteRaw = '';
        }
        $websiteRaw = trim((string)$websiteRaw);

        $event = trim((string)($merged['event'] ?? ''));
        $range = trim((string)($merged['range'] ?? ''));
        if ($range === '') {
            $range = '30d';
        }

        $startDate = trim((string)($merged['startDate'] ?? $merged['start_date'] ?? $merged['start_day'] ?? ''));
        $endDate = trim((string)($merged['endDate'] ?? $merged['end_date'] ?? $merged['end_day'] ?? ''));
        // 归一化日期可能带时间，下钻只传 YYYY-MM-DD
        if (\strlen($startDate) >= 10) {
            $startDate = substr($startDate, 0, 10);
        }
        if (\strlen($endDate) >= 10) {
            $endDate = substr($endDate, 0, 10);
        }
        if ($range !== 'custom') {
            $startDate = '';
            $endDate = '';
        }

        $channelCode = trim((string)($merged['channel_code'] ?? $merged['channelCode'] ?? ''));
        $trafficType = trim((string)($merged['traffic_type'] ?? $merged['trafficType'] ?? ''));
        $utmSource = trim((string)($merged['utm_source'] ?? $merged['utmSource'] ?? ''));
        $utmMedium = trim((string)($merged['utm_medium'] ?? $merged['utmMedium'] ?? ''));
        $utmCampaign = trim((string)($merged['utm_campaign'] ?? $merged['utmCampaign'] ?? ''));

        $query = [
            'websiteId' => $websiteRaw,
            'event' => $event,
            'range' => $range,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'channel_code' => $channelCode,
            'traffic_type' => $trafficType,
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
        ];

        // 仅允许契约键，防止额外参数漂移
        $allowed = array_fill_keys(self::LIST_DRILLDOWN_QUERY_KEYS, true);
        $query = array_intersect_key($query, $allowed);

        return array_filter(
            $query,
            static fn($value): bool => $value !== null && $value !== ''
        );
    }

    private static function normalizeOptionalStringFilter(mixed $raw, int $maxLen, string $tooLongMessage): ?string
    {
        $value = trim((string)($raw ?? ''));
        if ($value === '') {
            return null;
        }
        if (\strlen($value) > $maxLen) {
            throw new \InvalidArgumentException($tooLongMessage);
        }

        return $value;
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    private static function getWebsiteFilterOptions(): array
    {
        $options = [];
        foreach (Pixel::getAllWebsiteIds() as $websiteId) {
            $id = (int)$websiteId;
            $options[] = [
                'id' => $id,
                'label' => (string)__('站点 %{1}', [$id]),
            ];
        }
        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function getEventFilterOptions(?int $websiteId): array
    {
        $eventField = self::col(Pixel::schema_fields_EVENT, '');
        $websiteField = self::col(Pixel::schema_fields_WEBSITE_ID, '');
        $clauses = ["{$eventField} IS NOT NULL", "{$eventField} <> ''"];
        $params = [];
        if ($websiteId !== null) {
            $clauses[] = "{$websiteField} = :website_id";
            $params[':website_id'] = $websiteId;
        }

        $rows = self::fetchRows(
            'SELECT DISTINCT ' . $eventField . ' AS event FROM ' . self::tableSql('') . ' WHERE ' . implode(' AND ', $clauses) . ' ORDER BY ' . $eventField . ' ASC LIMIT 500',
            $params
        );

        return array_values(array_map(static fn(array $row): string => (string)($row['event'] ?? ''), $rows));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int|float|string|null>
     */
    private static function getDashboardSummary(array $filters): array
    {
        [$whereSql, $params] = self::buildDashboardWhere($filters, 'p');
        $table = self::tableSql('p');
        $website = self::col(Pixel::schema_fields_WEBSITE_ID);
        $event = self::col(Pixel::schema_fields_EVENT);
        $ip = self::col(Pixel::schema_fields_IP);
        $value = self::col(Pixel::schema_fields_VALUE);
        $cronDeal = self::col(Pixel::schema_fields_CRON_DEAL);
        $eventTime = self::eventTimeExpression('p');
        $row = self::fetchOne(
            "SELECT
                COUNT(*) AS total_events,
                COUNT(DISTINCT {$website}) AS active_sites,
                COUNT(DISTINCT {$event}) AS event_types,
                COUNT(DISTINCT NULLIF({$ip}, '')) AS active_users,
                COALESCE(SUM({$value}), 0) AS total_value,
                COALESCE(AVG({$value}), 0) AS avg_value,
                SUM(CASE WHEN {$cronDeal} = 0 THEN 1 ELSE 0 END) AS un_deal_count,
                SUM(CASE WHEN {$cronDeal} <> 0 THEN 1 ELSE 0 END) AS dealed_count,
                SUM(CASE WHEN {$value} > 0 THEN 1 ELSE 0 END) AS value_event_count,
                MIN({$eventTime}) AS first_seen,
                MAX({$eventTime}) AS last_seen
            FROM {$table}
            WHERE {$whereSql}",
            $params
        );

        return [
            'total_events' => (int)($row['total_events'] ?? 0),
            'active_sites' => (int)($row['active_sites'] ?? 0),
            'event_types' => (int)($row['event_types'] ?? 0),
            'active_users' => (int)($row['active_users'] ?? 0),
            'total_value' => (float)($row['total_value'] ?? 0),
            'avg_value' => round((float)($row['avg_value'] ?? 0), 2),
            'un_deal_count' => (int)($row['un_deal_count'] ?? 0),
            'dealed_count' => (int)($row['dealed_count'] ?? 0),
            'value_event_count' => (int)($row['value_event_count'] ?? 0),
            'first_seen' => $row['first_seen'] ?? null,
            'last_seen' => $row['last_seen'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int|float|string|null>
     */
    private static function getPreviousDashboardSummary(array $filters): array
    {
        $start = new \DateTimeImmutable((string)$filters['start_date']);
        $end = new \DateTimeImmutable((string)$filters['end_date']);
        $seconds = max(1, $end->getTimestamp() - $start->getTimestamp());
        $previousEnd = $start->modify('-1 second');
        $previousStart = $previousEnd->modify('-' . $seconds . ' seconds');

        $previousFilters = $filters;
        $previousFilters['start_date'] = $previousStart->format('Y-m-d H:i:s');
        $previousFilters['end_date'] = $previousEnd->format('Y-m-d H:i:s');

        return self::getDashboardSummary($previousFilters);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string>>
     */
    private static function getDashboardTrend(array $filters): array
    {
        [$whereSql, $params] = self::buildDashboardWhere($filters, 'p');
        $table = self::tableSql('p');
        $eventTime = self::eventTimeExpression('p');
        $ip = self::col(Pixel::schema_fields_IP);
        $event = self::col(Pixel::schema_fields_EVENT);
        $value = self::col(Pixel::schema_fields_VALUE);
        $dayExpression = "DATE({$eventTime})";
        $rows = self::fetchRows(
            "SELECT
                {$dayExpression} AS day,
                COUNT(*) AS event_count,
                COUNT(DISTINCT NULLIF({$ip}, '')) AS active_users,
                COUNT(DISTINCT {$event}) AS event_types,
                COALESCE(SUM({$value}), 0) AS total_value
            FROM {$table}
            WHERE {$whereSql}
            GROUP BY {$dayExpression}
            ORDER BY day ASC",
            $params
        );

        $rowsByDay = [];
        foreach ($rows as $row) {
            $rowsByDay[(string)$row['day']] = $row;
        }

        $trend = [];
        $cursor = new \DateTimeImmutable((string)$filters['start_day']);
        $end = new \DateTimeImmutable((string)$filters['end_day']);
        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');
            $row = $rowsByDay[$day] ?? [];
            $trend[] = [
                'date' => $day,
                'count' => (int)($row['event_count'] ?? 0),
                'users' => (int)($row['active_users'] ?? 0),
                'event_types' => (int)($row['event_types'] ?? 0),
                'value' => (float)($row['total_value'] ?? 0),
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $trend;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string|null>>
     */
    private static function getDashboardEventRows(array $filters, int $limit): array
    {
        [$whereSql, $params] = self::buildDashboardWhere($filters, 'p');
        $limit = max(1, min(100, $limit));
        $table = self::tableSql('p');
        $event = self::col(Pixel::schema_fields_EVENT);
        $ip = self::col(Pixel::schema_fields_IP);
        $website = self::col(Pixel::schema_fields_WEBSITE_ID);
        $value = self::col(Pixel::schema_fields_VALUE);
        $createdAt = self::col(Pixel::schema_fields_CREATED_AT);
        $rows = self::fetchRows(
            "SELECT
                {$event} AS event_name,
                COUNT(*) AS event_count,
                COUNT(DISTINCT NULLIF({$ip}, '')) AS active_users,
                COUNT(DISTINCT {$website}) AS site_count,
                COALESCE(SUM({$value}), 0) AS total_value,
                COALESCE(AVG({$value}), 0) AS avg_value,
                MAX({$createdAt}) AS last_seen
            FROM {$table}
            WHERE {$whereSql}
            GROUP BY {$event}
            ORDER BY event_count DESC, last_seen DESC
            LIMIT {$limit}",
            $params
        );

        $totalEvents = max(1, (int)self::getDashboardSummary($filters)['total_events']);
        return array_map(static function (array $row) use ($totalEvents): array {
            $count = (int)($row['event_count'] ?? 0);
            return [
                'event' => (string)($row['event_name'] ?? ''),
                'count' => $count,
                'active_users' => (int)($row['active_users'] ?? 0),
                'site_count' => (int)($row['site_count'] ?? 0),
                'total_value' => (float)($row['total_value'] ?? 0),
                'avg_value' => round((float)($row['avg_value'] ?? 0), 2),
                'share' => round(($count / $totalEvents) * 100, 2),
                'last_seen' => $row['last_seen'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string|null>>
     */
    private static function getDashboardSiteRows(array $filters, int $limit): array
    {
        [$whereSql, $params] = self::buildDashboardWhere($filters, 'p');
        $limit = max(1, min(500, $limit));
        $table = self::tableSql('p');
        $website = self::col(Pixel::schema_fields_WEBSITE_ID);
        $ip = self::col(Pixel::schema_fields_IP);
        $event = self::col(Pixel::schema_fields_EVENT);
        $value = self::col(Pixel::schema_fields_VALUE);
        $createdAt = self::col(Pixel::schema_fields_CREATED_AT);
        $rows = self::fetchRows(
            "SELECT
                {$website} AS website_id,
                COUNT(*) AS event_count,
                COUNT(DISTINCT NULLIF({$ip}, '')) AS active_users,
                COUNT(DISTINCT {$event}) AS event_types,
                COALESCE(SUM({$value}), 0) AS total_value,
                COALESCE(AVG({$value}), 0) AS avg_value,
                MAX({$createdAt}) AS last_seen
            FROM {$table}
            WHERE {$whereSql}
            GROUP BY {$website}
            ORDER BY event_count DESC, last_seen DESC
            LIMIT {$limit}",
            $params
        );

        return array_map(static fn(array $row): array => [
            'website_id' => (int)($row['website_id'] ?? 0),
            'count' => (int)($row['event_count'] ?? 0),
            'active_users' => (int)($row['active_users'] ?? 0),
            'event_types' => (int)($row['event_types'] ?? 0),
            'total_value' => (float)($row['total_value'] ?? 0),
            'avg_value' => round((float)($row['avg_value'] ?? 0), 2),
            'last_seen' => $row['last_seen'] ?? null,
        ], $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string|null>>
     */
    private static function getDashboardSourceRows(array $filters, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $rows = [];
        // A14：短窗且扁平列就绪 → SQL 直接读 utm/channel；无信号再走 A15 browser_info 回退。
        if (self::isShortAttributionWindow($filters) && self::hasPixelAttributionColumns()) {
            try {
                $flatRows = self::getDashboardSourceRowsFromFlatSql($filters, $limit);
                if (self::sourceRowsHaveAttributionSignal($flatRows)) {
                    $rows = $flatRows;
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        if ($rows === []) {
            try {
                $rows = self::getDashboardSourceRowsViaResolver($filters, $limit);
            } catch (\Throwable) {
                $rows = self::getDashboardSourceRowsLegacySql($filters, $limit);
            }
        }

        // C05：来源行补 channel_name（pixel_channel join）
        return self::enrichRowsWithChannelNames($rows, $filters['website_id'] ?? null);
    }

    /**
     * C05：按 channel_code 聚合流量板块（name 来自 pixel_channel join / 扁平列兜底）。
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string|null>>
     */
    public static function getDashboardChannelRows(array $filters, int $limit = 15): array
    {
        $limit = max(1, min(50, $limit));
        if (!self::hasPixelAttributionColumns()) {
            return [];
        }

        try {
            $rows = self::getDashboardChannelRowsFromFlatSql($filters, $limit);
        } catch (\Throwable) {
            return [];
        }

        return self::enrichRowsWithChannelNames($rows, $filters['website_id'] ?? null);
    }

    /**
     * D07a：为报表引擎提供热明细事件行（含归因扁平列）。列未就绪或查询失败时返回 []。
     *
     * @param array<string, mixed> $filters normalizeDashboardFilters 结果（可再覆盖 start/end）
     * @return list<array<string, mixed>>
     */
    public static function fetchHotReportEventRows(array $filters, int $limit = 20000): array
    {
        $limit = max(1, min(100000, $limit));
        if (!self::hasPixelAttributionColumns()) {
            return [];
        }

        try {
            [$whereSql, $params] = self::buildDashboardWhere($filters, 'p');
            $table = self::tableSql('p');
            $event = self::col(Pixel::schema_fields_EVENT);
            $value = self::col(Pixel::schema_fields_VALUE);
            $created = self::eventTimeExpression('p');
            $channelCode = self::col('channel_code');
            $trafficType = self::col('traffic_type');
            $utmSource = self::col('utm_source');
            $utmMedium = self::col('utm_medium');
            $utmCampaign = self::col('utm_campaign');
            $path = self::col(Pixel::schema_fields_URL);

            $rows = self::fetchRows(
                "SELECT
                    {$event} AS event,
                    {$value} AS value,
                    {$created} AS created_at,
                    {$channelCode} AS channel_code,
                    {$trafficType} AS traffic_type,
                    {$utmSource} AS utm_source,
                    {$utmMedium} AS utm_medium,
                    {$utmCampaign} AS utm_campaign,
                    {$path} AS url
                FROM {$table}
                WHERE {$whereSql}
                ORDER BY {$created} DESC
                LIMIT {$limit}",
                $params
            );

            $out = [];
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $out[] = [
                    'event' => (string)($row['event'] ?? ''),
                    'value' => (float)($row['value'] ?? 0),
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'channel_code' => (string)($row['channel_code'] ?? ''),
                    'traffic_type' => (string)($row['traffic_type'] ?? ''),
                    'utm_source' => (string)($row['utm_source'] ?? ''),
                    'utm_medium' => (string)($row['utm_medium'] ?? ''),
                    'utm_campaign' => (string)($row['utm_campaign'] ?? ''),
                    'url' => (string)($row['url'] ?? ''),
                    'path' => (string)($row['url'] ?? ''),
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string|null>>
     */
    private static function getDashboardChannelRowsFromFlatSql(array $filters, int $limit): array
    {
        [$whereSql, $params] = self::buildDashboardWhere($filters, 'p');
        $limit = max(1, min(50, $limit));
        $table = self::tableSql('p');
        $ip = self::col(Pixel::schema_fields_IP);
        $value = self::col(Pixel::schema_fields_VALUE);
        $channelCode = self::col('channel_code');
        $channelName = self::col('channel_name');
        $trafficType = self::col('traffic_type');

        $rows = self::fetchRows(
            "SELECT
                {$channelCode} AS channel_code,
                MAX({$channelName}) AS channel_name,
                MAX({$trafficType}) AS traffic_type,
                COUNT(*) AS event_count,
                COUNT(DISTINCT NULLIF({$ip}, '')) AS active_users,
                COALESCE(SUM({$value}), 0) AS total_value
            FROM {$table}
            WHERE {$whereSql}
              AND NULLIF({$channelCode}, '') IS NOT NULL
            GROUP BY {$channelCode}
            ORDER BY event_count DESC
            LIMIT {$limit}",
            $params
        );

        return array_map(static function (array $row): array {
            return [
                'channel_code' => trim((string)($row['channel_code'] ?? '')),
                'channel_name' => trim((string)($row['channel_name'] ?? '')),
                'traffic_type' => trim((string)($row['traffic_type'] ?? '')),
                'count' => (int)($row['event_count'] ?? 0),
                'active_users' => (int)($row['active_users'] ?? 0),
                'total_value' => (float)($row['total_value'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * C05：用 pixel_channel 表填充/覆盖 channel_name（站点优先于全局；campaign 优先于 rule）。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function enrichRowsWithChannelNames(array $rows, ?int $websiteId = null): array
    {
        if ($rows === []) {
            return $rows;
        }

        $codes = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = trim((string)($row['channel_code'] ?? ''));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }
        if ($codes === []) {
            return array_map(static function (array $row): array {
                if (!\array_key_exists('channel_name', $row)) {
                    $row['channel_name'] = '';
                }

                return $row;
            }, $rows);
        }

        $map = self::loadPixelChannelNameMap(\array_keys($codes), $websiteId);

        return array_map(static function (array $row) use ($map): array {
            $code = trim((string)($row['channel_code'] ?? ''));
            $flatName = trim((string)($row['channel_name'] ?? ''));
            if ($code !== '' && isset($map[$code])) {
                $joined = $map[$code];
                $joinedName = trim((string)($joined['name'] ?? ''));
                if ($joinedName !== '') {
                    $row['channel_name'] = $joinedName;
                } elseif ($flatName === '') {
                    $row['channel_name'] = '';
                }
                if (trim((string)($row['traffic_type'] ?? '')) === ''
                    && trim((string)($joined['traffic_type'] ?? '')) !== '') {
                    $row['traffic_type'] = (string)$joined['traffic_type'];
                }
            } elseif (!\array_key_exists('channel_name', $row)) {
                $row['channel_name'] = $flatName;
            }

            return $row;
        }, $rows);
    }

    /**
     * @param list<string> $codes
     * @return array<string, array{name: string, traffic_type: string}>
     */
    public static function loadPixelChannelNameMap(array $codes, ?int $websiteId = null): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn($c): string => trim((string)$c),
            $codes
        ), static fn(string $c): bool => $c !== '')));
        if ($codes === []) {
            return [];
        }
        if (!self::hasPixelChannelTable()) {
            return [];
        }

        try {
            $placeholders = [];
            $params = [];
            foreach ($codes as $i => $code) {
                $key = ':code_' . $i;
                $placeholders[] = $key;
                $params[$key] = $code;
            }

            $table = self::quoteIdentifier(self::getPixelChannelTableName());
            $codeCol = self::quoteIdentifier(PixelChannel::schema_fields_CODE);
            $nameCol = self::quoteIdentifier(PixelChannel::schema_fields_NAME);
            $typeCol = self::quoteIdentifier(PixelChannel::schema_fields_TRAFFIC_TYPE);
            $siteCol = self::quoteIdentifier(PixelChannel::schema_fields_WEBSITE_ID);
            $kindCol = self::quoteIdentifier(PixelChannel::schema_fields_KIND);
            $enabledCol = self::quoteIdentifier(PixelChannel::schema_fields_ENABLED);

            $websiteSql = '';
            if ($websiteId !== null) {
                $websiteSql = " AND {$siteCol} IN (0, :website_id)";
                $params[':website_id'] = (int)$websiteId;
            }

            $rows = self::fetchRows(
                "SELECT {$codeCol} AS code, {$nameCol} AS name, {$typeCol} AS traffic_type,
                        {$siteCol} AS website_id, {$kindCol} AS kind
                 FROM {$table}
                 WHERE {$enabledCol} = 1
                   AND {$codeCol} IN (" . implode(', ', $placeholders) . ")
                   {$websiteSql}",
                $params
            );

            $best = [];
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $code = trim((string)($row['code'] ?? ''));
                $name = trim((string)($row['name'] ?? ''));
                if ($code === '' || $name === '') {
                    continue;
                }
                $site = (int)($row['website_id'] ?? 0);
                $kind = trim((string)($row['kind'] ?? PixelChannel::KIND_CAMPAIGN));
                $score = 0;
                if ($websiteId !== null && $site === (int)$websiteId) {
                    $score += 100;
                } elseif ($site === 0) {
                    $score += 10;
                }
                if ($kind === PixelChannel::KIND_CAMPAIGN) {
                    $score += 5;
                }
                if (!isset($best[$code]) || $score > $best[$code]['score']) {
                    $best[$code] = [
                        'score' => $score,
                        'name' => $name,
                        'traffic_type' => trim((string)($row['traffic_type'] ?? '')),
                    ];
                }
            }

            $map = [];
            foreach ($best as $code => $item) {
                $map[$code] = [
                    'name' => (string)$item['name'],
                    'traffic_type' => (string)$item['traffic_type'],
                ];
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    private static function hasPixelChannelTable(): bool
    {
        try {
            $channel = w_obj(PixelChannel::class);
            $connector = $channel->getConnection()->getConnector();
            $table = (string)$channel->getTable();

            return $connector->hasField($table, PixelChannel::schema_fields_CODE);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function getPixelChannelTableName(): string
    {
        try {
            return (string)w_obj(PixelChannel::class)->getTable();
        } catch (\Throwable) {
            return PixelChannel::schema_table;
        }
    }

    /**
     * 短窗：今日/昨日/近7天，或自定义跨度 ≤7 天（A14）。
     *
     * @param array<string, mixed> $filters
     */
    public static function isShortAttributionWindow(array $filters): bool
    {
        $range = (string)($filters['range'] ?? '');
        if (\in_array($range, ['today', 'yesterday', '7d'], true)) {
            return true;
        }

        return (int)($filters['day_count'] ?? 0) > 0 && (int)$filters['day_count'] <= 7;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public static function sourceRowsHaveAttributionSignal(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $source = strtolower(trim((string)($row['source'] ?? '')));
            if ($source !== '' && $source !== 'direct' && $source !== 'worker') {
                return true;
            }
            if (trim((string)($row['channel_code'] ?? '')) !== ''
                || trim((string)($row['utm_source'] ?? '')) !== ''
                || trim((string)($row['utm_medium'] ?? '')) !== ''
                || trim((string)($row['utm_campaign'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * A14：短窗 SQL 聚合扁平列；C05 再补 pixel_channel name join。
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string|null>>
     */
    private static function getDashboardSourceRowsFromFlatSql(array $filters, int $limit): array
    {
        [$whereSql, $params] = self::buildDashboardWhere($filters, 'p');
        $limit = max(1, min(50, $limit));
        $table = self::tableSql('p');
        $ip = self::col(Pixel::schema_fields_IP);
        $value = self::col(Pixel::schema_fields_VALUE);
        $utmSource = self::col('utm_source');
        $channelCode = self::col('channel_code');
        $channelName = self::col('channel_name');
        $source = self::col(Pixel::schema_fields_SOURCE);
        $utmMedium = self::col('utm_medium');
        $utmCampaign = self::col('utm_campaign');
        $trafficType = self::col('traffic_type');
        $sourceExpression = "COALESCE("
            . "NULLIF({$utmSource}, ''), "
            . "NULLIF({$channelCode}, ''), "
            . "NULLIF({$source}, ''), "
            . "'direct')";

        $rows = self::fetchRows(
            "SELECT
                {$sourceExpression} AS source_name,
                MAX({$channelCode}) AS channel_code,
                MAX({$channelName}) AS channel_name,
                MAX({$utmSource}) AS utm_source,
                MAX({$utmMedium}) AS utm_medium,
                MAX({$utmCampaign}) AS utm_campaign,
                MAX({$trafficType}) AS traffic_type,
                COUNT(*) AS event_count,
                COUNT(DISTINCT NULLIF({$ip}, '')) AS active_users,
                COALESCE(SUM({$value}), 0) AS total_value
            FROM {$table}
            WHERE {$whereSql}
            GROUP BY {$sourceExpression}
            ORDER BY event_count DESC
            LIMIT {$limit}",
            $params
        );

        return array_map(static function (array $row): array {
            $utmSource = trim((string)($row['utm_source'] ?? ''));
            $utmMedium = trim((string)($row['utm_medium'] ?? ''));
            $label = (string)($row['source_name'] ?? 'direct');
            if ($utmSource !== '' && $utmMedium !== '' && !str_contains($label, '/')) {
                $label = $utmSource . '/' . $utmMedium;
            }

            return [
                'source' => $label,
                'channel_code' => trim((string)($row['channel_code'] ?? '')),
                'channel_name' => trim((string)($row['channel_name'] ?? '')),
                'utm_source' => $utmSource,
                'utm_medium' => $utmMedium,
                'utm_campaign' => trim((string)($row['utm_campaign'] ?? '')),
                'traffic_type' => trim((string)($row['traffic_type'] ?? '')),
                'count' => (int)($row['event_count'] ?? 0),
                'active_users' => (int)($row['active_users'] ?? 0),
                'total_value' => (float)($row['total_value'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string|null>>
     */
    private static function getDashboardSourceRowsViaResolver(array $filters, int $limit): array
    {
        [$whereSql, $params] = self::buildDashboardWhere($filters, 'p');
        $table = self::tableSql('p');
        $eventTime = self::eventTimeExpression('p');
        $pixelId = self::col(Pixel::schema_fields_ID);
        $select = [
            $pixelId . ' AS pixel_id',
            self::col(Pixel::schema_fields_URL) . ' AS url',
            self::col(Pixel::schema_fields_REFERER) . ' AS referer',
            self::col(Pixel::schema_fields_SOURCE) . ' AS source',
            self::col(Pixel::schema_fields_BROWSER_INFO) . ' AS browser_info',
            self::col(Pixel::schema_fields_IP) . ' AS ip',
            self::col(Pixel::schema_fields_VALUE) . ' AS value',
        ];
        if (self::hasPixelAttributionColumns()) {
            foreach (['session_id', 'channel_code', 'channel_name', 'traffic_type', 'utm_source', 'utm_medium', 'utm_campaign'] as $field) {
                $select[] = self::col($field) . ' AS ' . $field;
            }
        }

        $scanLimit = 3000;
        $rows = self::fetchRows(
            'SELECT ' . implode(",\n                ", $select) . "
            FROM {$table}
            WHERE {$whereSql}
            ORDER BY {$eventTime} DESC, {$pixelId} DESC
            LIMIT {$scanLimit}",
            $params
        );

        /** @var PixelAttributionRowResolver $resolver */
        $resolver = \Weline\Framework\Manager\ObjectManager::getInstance(PixelAttributionRowResolver::class);
        $buckets = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $resolved = $resolver->resolve($row);
            $key = (string)($resolved['source_label'] ?? 'direct');
            if ($key === '') {
                $key = 'direct';
            }
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'source' => $key,
                    'channel_code' => '',
                    'channel_name' => '',
                    'utm_source' => '',
                    'utm_medium' => '',
                    'utm_campaign' => '',
                    'traffic_type' => '',
                    'count' => 0,
                    'ips' => [],
                    'total_value' => 0.0,
                ];
            }
            $buckets[$key]['count']++;
            $buckets[$key]['total_value'] += (float)($row['value'] ?? 0);
            if ($buckets[$key]['channel_code'] === '' && $resolved['channel_code'] !== '') {
                $buckets[$key]['channel_code'] = $resolved['channel_code'];
            }
            if ($buckets[$key]['channel_name'] === '' && ($resolved['channel_name'] ?? '') !== '') {
                $buckets[$key]['channel_name'] = (string)$resolved['channel_name'];
            }
            if ($buckets[$key]['utm_source'] === '' && $resolved['utm_source'] !== '') {
                $buckets[$key]['utm_source'] = $resolved['utm_source'];
            }
            if ($buckets[$key]['utm_medium'] === '' && $resolved['utm_medium'] !== '') {
                $buckets[$key]['utm_medium'] = $resolved['utm_medium'];
            }
            if ($buckets[$key]['utm_campaign'] === '' && $resolved['utm_campaign'] !== '') {
                $buckets[$key]['utm_campaign'] = $resolved['utm_campaign'];
            }
            if ($buckets[$key]['traffic_type'] === '' && $resolved['traffic_type'] !== '') {
                $buckets[$key]['traffic_type'] = $resolved['traffic_type'];
            }
            $ip = trim((string)($row['ip'] ?? ''));
            if ($ip !== '') {
                $buckets[$key]['ips'][$ip] = true;
            }
        }

        usort($buckets, static fn(array $a, array $b): int => ($b['count'] <=> $a['count']));
        $buckets = array_slice($buckets, 0, $limit);

        return array_map(static fn(array $row): array => [
            'source' => (string)$row['source'],
            'channel_code' => (string)$row['channel_code'],
            'channel_name' => (string)$row['channel_name'],
            'utm_source' => (string)$row['utm_source'],
            'utm_medium' => (string)$row['utm_medium'],
            'utm_campaign' => (string)$row['utm_campaign'],
            'traffic_type' => (string)$row['traffic_type'],
            'count' => (int)$row['count'],
            'active_users' => \count($row['ips']),
            'total_value' => (float)$row['total_value'],
        ], $buckets);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string|null>>
     */
    private static function getDashboardSourceRowsLegacySql(array $filters, int $limit): array
    {
        [$whereSql, $params] = self::buildDashboardWhere($filters, 'p');
        $limit = max(1, min(50, $limit));
        $table = self::tableSql('p');
        $source = self::col(Pixel::schema_fields_SOURCE);
        $ip = self::col(Pixel::schema_fields_IP);
        $value = self::col(Pixel::schema_fields_VALUE);
        $sourceExpression = "COALESCE(NULLIF({$source}, ''), 'direct')";
        $rows = self::fetchRows(
            "SELECT
                {$sourceExpression} AS source_name,
                COUNT(*) AS event_count,
                COUNT(DISTINCT NULLIF({$ip}, '')) AS active_users,
                COALESCE(SUM({$value}), 0) AS total_value
            FROM {$table}
            WHERE {$whereSql}
            GROUP BY {$sourceExpression}
            ORDER BY event_count DESC
            LIMIT {$limit}",
            $params
        );

        return array_map(static fn(array $row): array => [
            'source' => (string)($row['source_name'] ?? 'direct'),
            'channel_code' => '',
            'channel_name' => '',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'traffic_type' => '',
            'count' => (int)($row['event_count'] ?? 0),
            'active_users' => (int)($row['active_users'] ?? 0),
            'total_value' => (float)($row['total_value'] ?? 0),
        ], $rows);
    }

    private static function hasPixelAttributionColumns(): bool
    {
        try {
            $pixel = w_obj(Pixel::class);
            $connector = $pixel->getConnection()->getConnector();
            $table = (string)$pixel->getTable();
            foreach (['session_id', 'channel_code', 'utm_source'] as $field) {
                if (!$connector->hasField($table, $field)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string>>
     */
    private static function getDashboardRealtimeRows(array $filters, int $intervalMinutes, int $slots): array
    {
        $intervalMinutes = in_array($intervalMinutes, [10, 30], true) ? $intervalMinutes : 10;
        $slots = max(1, min(24, $slots));
        $minutes = $intervalMinutes * $slots;

        $realtimeFilters = $filters;
        $realtimeFilters['start_date'] = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));
        $realtimeFilters['end_date'] = date('Y-m-d H:i:s');

        [$whereSql, $params] = self::buildDashboardWhere($realtimeFilters, 'p');
        $table = self::tableSql('p');
        $eventTime = self::eventTimeExpression('p');
        $ip = self::col(Pixel::schema_fields_IP);
        $value = self::col(Pixel::schema_fields_VALUE);
        $timeFormat = self::getTimeSlotExpression($eventTime, $intervalMinutes);
        $rows = self::fetchRows(
            "SELECT
                {$timeFormat} AS time_slot,
                COUNT(*) AS event_count,
                COUNT(DISTINCT NULLIF({$ip}, '')) AS active_users,
                COALESCE(SUM({$value}), 0) AS total_value
            FROM {$table}
            WHERE {$whereSql}
            GROUP BY time_slot
            ORDER BY time_slot DESC
            LIMIT {$slots}",
            $params
        );

        $rows = array_reverse($rows);
        return array_map(static fn(array $row): array => [
            'timestamp' => (string)($row['time_slot'] ?? ''),
            'count' => (int)($row['event_count'] ?? 0),
            'active_users' => (int)($row['active_users'] ?? 0),
            'value' => (float)($row['total_value'] ?? 0),
        ], $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, int|float|string|null>>
     */
    private static function getDashboardRecentEvents(array $filters, int $limit): array
    {
        [$whereSql, $params] = self::buildDashboardWhere($filters, 'p');
        $limit = max(1, min(100, $limit));
        $table = self::tableSql('p');
        $pixelId = self::col(Pixel::schema_fields_ID);
        $website = self::col(Pixel::schema_fields_WEBSITE_ID);
        $event = self::col(Pixel::schema_fields_EVENT);
        $url = self::col(Pixel::schema_fields_URL);
        $ip = self::col(Pixel::schema_fields_IP);
        $source = self::col(Pixel::schema_fields_SOURCE);
        $referer = self::col(Pixel::schema_fields_REFERER);
        $lang = self::col(Pixel::schema_fields_LANG);
        $currency = self::col(Pixel::schema_fields_CURRENCY);
        $value = self::col(Pixel::schema_fields_VALUE);
        $browserInfo = self::col(Pixel::schema_fields_BROWSER_INFO);
        $eventTime = self::eventTimeExpression('p');
        $select = [
            "{$pixelId} AS pixel_id",
            "{$website} AS website_id",
            "{$event} AS event",
            "{$url} AS url",
            "{$ip} AS ip",
            "{$source} AS source",
            "{$referer} AS referer",
            "{$lang} AS lang",
            "{$currency} AS currency",
            "{$value} AS value",
            "{$browserInfo} AS browser_info",
            "{$eventTime} AS created_at",
        ];
        if (self::hasPixelAttributionColumns()) {
            foreach (['session_id', 'channel_code', 'channel_name', 'traffic_type', 'utm_source', 'utm_medium', 'utm_campaign'] as $field) {
                $select[] = self::col($field) . ' AS ' . $field;
            }
        }

        $rows = self::fetchRows(
            'SELECT ' . implode(",\n                ", $select) . "
            FROM {$table}
            WHERE {$whereSql}
            ORDER BY {$eventTime} DESC, {$pixelId} DESC
            LIMIT {$limit}",
            $params
        );

        /** @var PixelAttributionRowResolver $resolver */
        $resolver = \Weline\Framework\Manager\ObjectManager::getInstance(PixelAttributionRowResolver::class);

        return array_map(static function (array $row) use ($resolver): array {
            $resolved = $resolver->resolve($row);

            return [
                'pixel_id' => (int)($row['pixel_id'] ?? 0),
                'website_id' => (int)($row['website_id'] ?? 0),
                'event' => (string)($row['event'] ?? ''),
                'url' => (string)($row['url'] ?? ''),
                'ip' => (string)($row['ip'] ?? ''),
                'source' => (string)($resolved['source_label'] ?? $row['source'] ?? ''),
                'channel_code' => (string)($resolved['channel_code'] ?? ''),
                'utm_source' => (string)($resolved['utm_source'] ?? ''),
                'utm_medium' => (string)($resolved['utm_medium'] ?? ''),
                'utm_campaign' => (string)($resolved['utm_campaign'] ?? ''),
                'traffic_type' => (string)($resolved['traffic_type'] ?? ''),
                'session_id' => (string)($resolved['session_id'] ?? ''),
                'referer' => (string)($row['referer'] ?? ''),
                'lang' => (string)($row['lang'] ?? ''),
                'currency' => (string)($row['currency'] ?? ''),
                'value' => (float)($row['value'] ?? 0),
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $filters 须已 normalizeDashboardFilters
     * @return array{0: string, 1: array<string, int|string>}
     */
    private static function buildDashboardWhere(array $filters, string $alias): array
    {
        $eventTime = self::eventTimeExpression($alias);
        $clauses = [
            "{$eventTime} >= :start_date",
            "{$eventTime} <= :end_date",
        ];
        $params = [
            ':start_date' => (string)$filters['start_date'],
            ':end_date' => (string)$filters['end_date'],
        ];

        if ($filters['website_id'] !== null) {
            $clauses[] = self::col(Pixel::schema_fields_WEBSITE_ID, $alias) . " = :website_id";
            $params[':website_id'] = (int)$filters['website_id'];
        }

        if ($filters['event'] !== null) {
            $clauses[] = self::col(Pixel::schema_fields_EVENT, $alias) . " = :event";
            $params[':event'] = (string)$filters['event'];
        }

        $attributionKeys = [
            'channel_code' => ':channel_code',
            'traffic_type' => ':traffic_type',
            'utm_source' => ':utm_source',
            'utm_medium' => ':utm_medium',
            'utm_campaign' => ':utm_campaign',
        ];
        $wantsAttribution = false;
        foreach ($attributionKeys as $field => $_param) {
            if (($filters[$field] ?? null) !== null) {
                $wantsAttribution = true;
                break;
            }
        }
        if ($wantsAttribution) {
            if (!self::hasPixelAttributionColumns()) {
                throw new \InvalidArgumentException((string)__('归因筛选需要扁平列，请先执行 setup:upgrade'));
            }
            foreach ($attributionKeys as $field => $param) {
                $value = $filters[$field] ?? null;
                if ($value === null) {
                    continue;
                }
                $clauses[] = self::col($field, $alias) . " = {$param}";
                $params[$param] = (string)$value;
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * 事件时间：优先 created_at；历史空值回退 create_time（框架自动时间戳）。
     */
    private static function eventTimeExpression(string $alias = 'p'): string
    {
        return 'COALESCE('
            . self::col(Pixel::schema_fields_CREATED_AT, $alias)
            . ', '
            . self::col('create_time', $alias)
            . ')';
    }

    private static function tableSql(string $alias): string
    {
        $table = self::quoteIdentifier(self::getPixelTableName());
        if ($alias === '') {
            return $table;
        }
        return $table . ' ' . self::quoteIdentifier($alias);
    }

    private static function col(string $field, string $alias = 'p'): string
    {
        $column = self::quoteIdentifier($field);
        if ($alias === '') {
            return $column;
        }
        return self::quoteIdentifier($alias) . '.' . $column;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (str_contains($identifier, '"') || str_contains($identifier, '`')) {
            return $identifier;
        }

        $quote = self::getPdoDriver() === 'mysql' ? '`' : '"';
        $escapedQuote = $quote . $quote;
        $parts = explode('.', $identifier);
        $quotedParts = array_map(
            static fn(string $part): string => $quote . str_replace($quote, $escapedQuote, $part) . $quote,
            $parts
        );
        return implode('.', $quotedParts);
    }

    private static function getTimeSlotExpression(string $createdAtField, int $intervalMinutes): string
    {
        $intervalMinutes = max(1, (int)$intervalMinutes);
        $driver = self::getPdoDriver();
        if ($driver === 'pgsql') {
            return "TO_CHAR(DATE_TRUNC('hour', {$createdAtField}) + FLOOR(EXTRACT(MINUTE FROM {$createdAtField}) / {$intervalMinutes}) * INTERVAL '{$intervalMinutes} minutes', 'YYYY-MM-DD HH24:MI:SS')";
        }
        if ($driver === 'sqlite') {
            return "strftime('%Y-%m-%d %H:', {$createdAtField})"
                . " || printf('%02d', (CAST(strftime('%M', {$createdAtField}) AS INTEGER) / {$intervalMinutes}) * {$intervalMinutes})"
                . " || ':00'";
        }

        return "DATE_FORMAT(DATE_SUB({$createdAtField}, INTERVAL MINUTE({$createdAtField}) % {$intervalMinutes} MINUTE), '%Y-%m-%d %H:%i:00')";
    }
    
    /**
     * @param array<string, int|string> $params
     * @return array<int, array<string, mixed>>
     */
    private static function fetchRows(string $sql, array $params = []): array
    {
        $statement = self::getPixelPdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $statement->execute();
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, int|string> $params
     * @return array<string, mixed>
     */
    private static function fetchOne(string $sql, array $params = []): array
    {
        $rows = self::fetchRows($sql, $params);
        return $rows[0] ?? [];
    }

    private static function getPixelPdo(): \PDO
    {
        return w_obj(Pixel::class)->getConnection()->getConnector()->getLink();
    }

    private static function getPdoDriver(): string
    {
        return (string)self::getPixelPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    private static function getPixelTableName(): string
    {
        $model = w_obj(Pixel::class);
        $table = (string)$model->getTable();
        $prefix = (string)$model->getConnection()->getConfigProvider()->getPrefix();
        if ($prefix !== '' && !str_contains($table, '"') && !str_contains($table, '`') && !str_starts_with($table, $prefix)) {
            return $prefix . $table;
        }
        return $table;
    }

    private static function normalizeDashboardDate(string $value, bool $endOfDay): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value) {
            return $endOfDay ? $date->format('Y-m-d 23:59:59') : $date->format('Y-m-d 00:00:00');
        }

        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if ($dateTime instanceof \DateTimeImmutable && $dateTime->format('Y-m-d H:i:s') === $value) {
            return $dateTime->format('Y-m-d H:i:s');
        }

        throw new \InvalidArgumentException((string)__('日期格式无效，请使用 YYYY-MM-DD'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function resolvePresetDateRange(string $range): array
    {
        $today = new \DateTimeImmutable('today');
        return match ($range) {
            'today' => [
                $today->format('Y-m-d 00:00:00'),
                $today->format('Y-m-d 23:59:59'),
            ],
            'yesterday' => [
                $today->modify('-1 day')->format('Y-m-d 00:00:00'),
                $today->modify('-1 day')->format('Y-m-d 23:59:59'),
            ],
            '7d' => [
                $today->modify('-6 days')->format('Y-m-d 00:00:00'),
                $today->format('Y-m-d 23:59:59'),
            ],
            '90d' => [
                $today->modify('-89 days')->format('Y-m-d 00:00:00'),
                $today->format('Y-m-d 23:59:59'),
            ],
            default => [
                $today->modify('-29 days')->format('Y-m-d 00:00:00'),
                $today->format('Y-m-d 23:59:59'),
            ],
        };
    }

    private static function countDays(string $startDay, string $endDay): int
    {
        $start = new \DateTimeImmutable($startDay);
        $end = new \DateTimeImmutable($endDay);
        return ((int)$start->diff($end)->days) + 1;
    }

    private static function calculateChangeRate(int $current, int $previous): float
    {
        if ($previous > 0) {
            return round((($current - $previous) / $previous) * 100, 2);
        }
        return $current > 0 ? 100.0 : 0.0;
    }
    
    /**
     * 格式化统计数据用于图表展示
     * 
     * @param array $data 原始数据
     * @param string $type 图表类型：line, bar, pie
     * @return array
     */
    public static function formatForChart(array $data, string $type = 'line'): array
    {
        switch ($type) {
            case 'line':
            case 'bar':
                return [
                    'labels' => array_column($data, 'date') ?? array_keys($data),
                    'datasets' => [
                        [
                            'label' => __('数量'),
                            'data' => array_column($data, 'count') ?? array_values($data),
                            'borderColor' => 'rgb(102, 126, 234)',
                            'backgroundColor' => 'rgba(102, 126, 234, 0.1)'
                        ]
                    ]
                ];
                
            case 'pie':
                return [
                    'labels' => array_keys($data),
                    'datasets' => [
                        [
                            'data' => array_values($data),
                            'backgroundColor' => [
                                'rgba(102, 126, 234, 0.8)',
                                'rgba(17, 153, 142, 0.8)',
                                'rgba(240, 147, 251, 0.8)',
                                'rgba(79, 172, 254, 0.8)',
                                'rgba(255, 99, 132, 0.8)',
                                'rgba(54, 162, 235, 0.8)',
                                'rgba(255, 206, 86, 0.8)',
                                'rgba(75, 192, 192, 0.8)',
                                'rgba(153, 102, 255, 0.8)',
                                'rgba(255, 159, 64, 0.8)'
                            ]
                        ]
                    ]
                ];
                
            default:
                return $data;
        }
    }
    
    /**
     * 清除统计缓存
     * 
     * @param int|null $websiteId 站点ID，null表示清除所有
     * @return void
     */
    public static function clearCache(?int $websiteId = null): void
    {
        PixelStatisticsCache::clearWebsiteCache($websiteId);
    }

    /**
     * 获取全站 Dashboard 概览数据（聚合所有站点）
     *
     * 该方法主要用于后台首页面板等场景，一次性返回：
     * - 聚合统计指标（总记录数 / 已处理 / 未处理 / 总价值 / 事件类型数）
     * - 各站点摘要
     * - 最近 N 天的聚合趋势
     * - 全站热门事件 Top N
     * - 第一个站点的实时数据快照
     *
     * @param int $days 趋势天数
     * @param int $topEventLimit 热门事件数量
     * @return array{
     *     stats: array,
     *     website_stats: array,
     *     website_ids: array,
     *     trends: array,
     *     top_events: array,
     *     realtime_data: array
     * }
     * @throws \Exception
     */
    public static function getGlobalDashboardOverview(int $days = 7, int $topEventLimit = 10): array
    {
        $websiteIds = Pixel::getAllWebsiteIds();

        $stats        = [];
        $websiteStats = [];
        $totalValue   = 0.0;
        $allEvents    = [];

        foreach ($websiteIds as $websiteId) {
            $summary = Pixel::getWebsiteSummary((int)$websiteId);
            $websiteStats[$websiteId] = $summary;

            $stats['total_count']   = ($stats['total_count'] ?? 0) + ($summary['total_count'] ?? 0);
            $stats['un_deal_count'] = ($stats['un_deal_count'] ?? 0) + ($summary['un_deal_count'] ?? 0);
            $stats['dealed_count']  = ($stats['dealed_count'] ?? 0) + ($summary['dealed_count'] ?? 0);

            // 累计总价值
            $pixels = Pixel::getPixelsByWebsiteId((int)$websiteId);
            foreach ($pixels as $pixel) {
                $totalValue += (float)($pixel[Pixel::schema_fields_VALUE] ?? 0);
            }

            // 收集所有事件及数量
            foreach ($summary['event_list'] ?? [] as $event) {
                if (!isset($allEvents[$event])) {
                    $allEvents[$event] = 0;
                }
                $allEvents[$event] += (int)($summary['events'][$event] ?? 0);
            }
        }

        // 热门事件 Top N
        arsort($allEvents);
        $topEvents = array_slice($allEvents, 0, $topEventLimit, true);

        // 最近 N 天趋势（聚合所有站点）
        $trends = self::getTrends(null, $days);

        // 第一个站点的实时数据（可选）
        $realtimeData = [];
        if (!empty($websiteIds)) {
            try {
                $firstWebsiteId = (int)($websiteIds[0]);
                $realtimeData   = self::getRealtimeData($firstWebsiteId, 10, 24);
            } catch (\Throwable $e) {
                // 实时数据失败不影响整体概览，静默忽略
            }
        }

        $stats['total_value']  = $totalValue;
        $stats['event_types']  = count($allEvents);

        return [
            'stats'         => $stats,
            'website_stats' => $websiteStats,
            'website_ids'   => $websiteIds,
            'trends'        => $trends,
            'top_events'    => $topEvents,
            'realtime_data' => $realtimeData,
        ];
    }
}

