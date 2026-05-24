<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Visitor\Model\Pixel;
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
            'realtime_rows' => self::getDashboardRealtimeRows($normalizedFilters, 10, 6),
            'recent_events' => self::getDashboardRecentEvents($normalizedFilters, 25),
        ];
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
     *     day_count: int
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
        ];
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
        $createdAt = self::col(Pixel::schema_fields_CREATED_AT);
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
                MIN({$createdAt}) AS first_seen,
                MAX({$createdAt}) AS last_seen
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
        $createdAt = self::col(Pixel::schema_fields_CREATED_AT);
        $ip = self::col(Pixel::schema_fields_IP);
        $event = self::col(Pixel::schema_fields_EVENT);
        $value = self::col(Pixel::schema_fields_VALUE);
        $dayExpression = "DATE({$createdAt})";
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
            'count' => (int)($row['event_count'] ?? 0),
            'active_users' => (int)($row['active_users'] ?? 0),
            'total_value' => (float)($row['total_value'] ?? 0),
        ], $rows);
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
        $createdAt = self::col(Pixel::schema_fields_CREATED_AT);
        $ip = self::col(Pixel::schema_fields_IP);
        $value = self::col(Pixel::schema_fields_VALUE);
        $timeFormat = self::getTimeSlotExpression($createdAt, $intervalMinutes);
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
        $createdAt = self::col(Pixel::schema_fields_CREATED_AT);
        $rows = self::fetchRows(
            "SELECT
                {$pixelId} AS pixel_id,
                {$website} AS website_id,
                {$event} AS event,
                {$url} AS url,
                {$ip} AS ip,
                {$source} AS source,
                {$referer} AS referer,
                {$lang} AS lang,
                {$currency} AS currency,
                {$value} AS value,
                {$createdAt} AS created_at
            FROM {$table}
            WHERE {$whereSql}
            ORDER BY {$createdAt} DESC, {$pixelId} DESC
            LIMIT {$limit}",
            $params
        );

        return array_map(static fn(array $row): array => [
            'pixel_id' => (int)($row['pixel_id'] ?? 0),
            'website_id' => (int)($row['website_id'] ?? 0),
            'event' => (string)($row['event'] ?? ''),
            'url' => (string)($row['url'] ?? ''),
            'ip' => (string)($row['ip'] ?? ''),
            'source' => (string)($row['source'] ?? ''),
            'referer' => (string)($row['referer'] ?? ''),
            'lang' => (string)($row['lang'] ?? ''),
            'currency' => (string)($row['currency'] ?? ''),
            'value' => (float)($row['value'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
        ], $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, int|string>}
     */
    private static function buildDashboardWhere(array $filters, string $alias): array
    {
        $clauses = [
            self::col(Pixel::schema_fields_CREATED_AT, $alias) . " >= :start_date",
            self::col(Pixel::schema_fields_CREATED_AT, $alias) . " <= :end_date",
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

        return [implode(' AND ', $clauses), $params];
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
        if (self::getPdoDriver() === 'pgsql') {
            return "TO_CHAR(DATE_TRUNC('hour', {$createdAtField}) + FLOOR(EXTRACT(MINUTE FROM {$createdAtField}) / {$intervalMinutes}) * INTERVAL '{$intervalMinutes} minutes', 'YYYY-MM-DD HH24:MI:SS')";
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

