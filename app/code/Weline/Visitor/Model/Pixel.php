<?php
namespace Weline\Visitor\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'weline 访客像素统计')]
#[Index(name: 'idx_event', columns: ['event'])]
#[Index(name: 'idx_currency', columns: ['currency'])]
#[Index(name: 'idx_lang', columns: ['lang'])]
#[Index(name: 'idx_website_id', columns: ['website_id'])]
#[Index(name: 'idx_module', columns: ['module'])]
#[Index(name: 'idx_source', columns: ['source'])]
#[Index(name: 'idx_cron_deal', columns: ['cron_deal'])]
class Pixel extends Model
{
    public const schema_table = 'w_pixel';
    public const schema_primary_key = 'pixel_id';
    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'pixel_id';
    #[Col('varchar', 255, comment: 'URL')]
    public const schema_fields_URL = 'url';
    #[Col('varchar', 255, nullable: false, comment: '模块')]
    public const schema_fields_MODULE = 'module';
    #[Col('varchar', 255, comment: '名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 255, comment: 'referer来源')]
    public const schema_fields_REFERER = 'referer';
    #[Col('varchar', 255, comment: '来源')]
    public const schema_fields_SOURCE = 'source';
    #[Col('int', 0, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col('varchar', 255, comment: '用户代理')]
    public const schema_fields_USER_AGENT = 'user_agent';
    #[Col('varchar', 45, comment: 'IP地址')]
    public const schema_fields_IP = 'ip';
    #[Col('varchar', 255, nullable: false, comment: '事件')]
    public const schema_fields_EVENT = 'event';
    #[Col('int', 0, nullable: false, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 255, nullable: false, comment: '语言')]
    public const schema_fields_LANG = 'lang';
    #[Col('varchar', 255, nullable: false, comment: '货币')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('int', 0, nullable: false, comment: '价值')]
    public const schema_fields_VALUE = 'value';
    #[Col('text', comment: '浏览器信息')]
    public const schema_fields_BROWSER_INFO = 'browser_info';
    #[Col('int', 0, nullable: false, default: 0, comment: '定时处理')]
    public const schema_fields_CRON_DEAL = 'cron_deal';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    /**
     * 获取未处理的像素记录
     * 
     * @param int|null $websiteId 站点ID，如果提供则只获取该站点的记录
     * @return array
     */
    public static function getUnDeaPixels(?int $websiteId = null): array
    {
        $model = w_obj(self::class)->reset()->where(self::schema_fields_CRON_DEAL, 0);
        
        // 如果提供了站点ID，则添加站点ID过滤条件
        if ($websiteId !== null && $websiteId > 0) {
            $model->where(self::schema_fields_WEBSITE_ID, $websiteId);
        }
        
        return $model->select()->fetchArray();
    }
    
    /**
     * 根据站点ID获取像素记录（支持分页和限制）
     * 
     * @param int $websiteId 站点ID
     * @param array $conditions 额外的查询条件
     * @param int|null $limit 限制返回数量，null表示不限制（但最大10000）
     * @param int|null $offset 偏移量，用于分页
     * @return array
     */
    public static function getPixelsByWebsiteId(int $websiteId, array $conditions = [], ?int $limit = null, ?int $offset = null): array
    {
        $model = w_obj(self::class)->reset()->where(self::schema_fields_WEBSITE_ID, $websiteId);
        
        // 应用额外的查询条件
        foreach ($conditions as $field => $value) {
            if (is_array($value)) {
                // 支持 ['field' => 'value', 'operator' => '='] 格式
                $operator = $value['operator'] ?? '=';
                $val = $value['value'] ?? $value;
                $model->where($field, $val, $operator);
            } else {
                $model->where($field, $value);
            }
        }
        
        // 添加限制，避免查询过大导致性能问题
        if ($limit !== null) {
            $limit = min($limit, 10000); // 最大限制10000条
            $model->limit($limit);
            if ($offset !== null) {
                $model->offset($offset);
            }
        } else {
            // 如果没有指定限制，默认限制10000条
            $model->limit(10000);
        }
        
        // 使用索引字段排序，提高查询性能
        $model->order(self::schema_fields_CREATED_AT . ' DESC');
        
        return $model->select()->fetchArray();
    }
    
    /**
     * 根据站点ID和事件名获取像素记录
     * 
     * @param int $websiteId 站点ID
     * @param string $event 事件名
     * @return array
     */
    public static function getPixelsByWebsiteIdAndEvent(int $websiteId, string $event): array
    {
        return w_obj(self::class)->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_EVENT, $event)
            ->select()
            ->fetchArray();
    }
    
    /**
     * 统计指定站点的像素记录数量
     * 
     * @param int $websiteId 站点ID
     * @param array $conditions 额外的查询条件
     * @return int
     */
    public static function countPixelsByWebsiteId(int $websiteId, array $conditions = []): int
    {
        $model = w_obj(self::class)->reset()->where(self::schema_fields_WEBSITE_ID, $websiteId);
        
        // 应用额外的查询条件
        foreach ($conditions as $field => $value) {
            if (is_array($value)) {
                $operator = $value['operator'] ?? '=';
                $val = $value['value'] ?? $value;
                $model->where($field, $val, $operator);
            } else {
                $model->where($field, $value);
            }
        }
        
        return (int)$model->count();
    }
    
    /**
     * 统计指定站点的事件数量
     * 
     * @param int $websiteId 站点ID
     * @param string $event 事件名
     * @return int
     */
    public static function countPixelsByWebsiteIdAndEvent(int $websiteId, string $event): int
    {
        return (int)w_obj(self::class)->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_EVENT, $event)
            ->count();
    }
    
    /**
     * 获取所有站点ID列表（去重，带缓存优化）
     * 
     * @return array
     */
    public static function getAllWebsiteIds(): array
    {
        try {
            $model = w_obj(self::class);
            
            // 检查表是否存在
            $connector = $model->getConnection()->getConnector();
            $tableName = $model->getTable();
            
            if (!$connector->tableExist($tableName)) {
                return [];
            }
            
            // 使用索引字段查询，提高性能
            // website_id字段已有索引 idx_website_id
            $sql = "SELECT DISTINCT `" . self::schema_fields_WEBSITE_ID . "` 
                    FROM `{$tableName}` 
                    WHERE `" . self::schema_fields_WEBSITE_ID . "` IS NOT NULL 
                    ORDER BY `" . self::schema_fields_WEBSITE_ID . "` ASC
                    LIMIT 1000"; // 限制最大返回数量，避免性能问题
            
            $result = $connector->query($sql)->fetch();
            
            if (empty($result)) {
                return [];
            }
            
            return array_column($result, self::schema_fields_WEBSITE_ID);
        } catch (\Exception $e) {
            // 如果查询失败，返回空数组
            return [];
        }
    }
    
    /**
     * 获取指定站点的所有事件列表（去重）
     * 
     * @param int $websiteId 站点ID
     * @return array
     */
    public static function getEventsByWebsiteId(int $websiteId): array
    {
        $result = w_obj(self::class)->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->field(self::schema_fields_EVENT)
            ->group(self::schema_fields_EVENT)
            ->select()
            ->fetchArray();
        
        return array_column($result, self::schema_fields_EVENT);
    }
    
    /**
     * 获取站点统计摘要
     * 
     * @param int $websiteId 站点ID
     * @return array 返回统计摘要信息
     */
    public static function getWebsiteSummary(int $websiteId): array
    {
        $totalCount = self::countPixelsByWebsiteId($websiteId);
        $eventList = self::getEventsByWebsiteId($websiteId);
        
        $events = [];
        foreach ($eventList as $event) {
            $events[$event] = self::countPixelsByWebsiteIdAndEvent($websiteId, $event);
        }
        
        // 统计未处理记录数
        $unDealCount = count(self::getUnDeaPixels($websiteId));
        
        return [
            'website_id' => $websiteId,
            'total_count' => $totalCount,
            'un_deal_count' => $unDealCount,
            'dealed_count' => $totalCount - $unDealCount,
            'events' => $events,
            'event_list' => $eventList,
            'event_count' => count($eventList)
        ];
    }
    
    /**
     * 获取指定时间范围内的站点统计
     * 
     * @param int $websiteId 站点ID
     * @param string|null $startDate 开始日期（Y-m-d H:i:s格式）
     * @param string|null $endDate 结束日期（Y-m-d H:i:s格式）
     * @return array
     */
    public static function getWebsiteStatsByDateRange(int $websiteId, ?string $startDate = null, ?string $endDate = null): array
    {
        $model = w_obj(self::class)->reset()->where(self::schema_fields_WEBSITE_ID, $websiteId);
        
        // 添加时间范围条件（使用created_at字段）
        if ($startDate !== null) {
            $model->where(self::schema_fields_CREATED_AT, $startDate, '>=');
        }
        if ($endDate !== null) {
            $model->where(self::schema_fields_CREATED_AT, $endDate, '<=');
        }
        
        $totalCount = (int)$model->count();
        
        // 获取事件统计（需要重新获取，因为时间范围可能影响事件列表）
        // 先获取该时间范围内所有事件
        $eventModel = w_obj(self::class)->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->field(self::schema_fields_EVENT)
            ->group(self::schema_fields_EVENT);
        
        if ($startDate !== null) {
            $eventModel->where(self::schema_fields_CREATED_AT, $startDate, '>=');
        }
        if ($endDate !== null) {
            $eventModel->where(self::schema_fields_CREATED_AT, $endDate, '<=');
        }
        
        $eventResult = $eventModel->select()->fetchArray();
        $eventList = array_column($eventResult, self::schema_fields_EVENT);
        
        // 统计每个事件的数量
        $events = [];
        foreach ($eventList as $event) {
            $eventCountModel = w_obj(self::class)->reset()
                ->where(self::schema_fields_WEBSITE_ID, $websiteId)
                ->where(self::schema_fields_EVENT, $event);
            
            if ($startDate !== null) {
                $eventCountModel->where(self::schema_fields_CREATED_AT, $startDate, '>=');
            }
            if ($endDate !== null) {
                $eventCountModel->where(self::schema_fields_CREATED_AT, $endDate, '<=');
            }
            
            $events[$event] = (int)$eventCountModel->count();
        }
        
        return [
            'website_id' => $websiteId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_count' => $totalCount,
            'events' => $events,
            'event_list' => $eventList
        ];
    }
    
    /**
     * 获取商业价值分析数据（按时间维度）
     * 
     * @param int $websiteId 站点ID
     * @param string $period 时间维度：daily, weekly, monthly, quarterly, yearly
     * @param string|null $startDate 开始日期
     * @param string|null $endDate 结束日期
     * @return array
     */
    public static function getBusinessValueByPeriod(int $websiteId, string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        // 如果没有指定时间范围，根据时间维度设置默认范围
        if ($startDate === null || $endDate === null) {
            $endDate = date('Y-m-d 23:59:59');
            switch ($period) {
                case 'daily':
                    $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
                    break;
                case 'weekly':
                    $startDate = date('Y-m-d 00:00:00', strtotime('-12 weeks'));
                    break;
                case 'monthly':
                    $startDate = date('Y-m-d 00:00:00', strtotime('-12 months'));
                    break;
                case 'quarterly':
                    $startDate = date('Y-m-d 00:00:00', strtotime('-4 quarters'));
                    break;
                case 'yearly':
                    $startDate = date('Y-m-d 00:00:00', strtotime('-5 years'));
                    break;
            }
        }
        
        // 获取模型实例
        $model = w_obj(self::class);
        $connector = $model->getConnection()->getConnector();
        $prefix = $model->getConnection()->getConfigProvider()->getPrefix();
        $tableName = $prefix . $model->getTable();
        
        // 根据时间维度构建SQL日期格式化
        // 注意：MySQL的QUARTER函数返回1-4，需要特殊处理
        if ($period === 'quarterly') {
            $selectDate = "CONCAT(YEAR(created_at), '-Q', QUARTER(created_at)) as period_date";
            $groupBy = "YEAR(created_at), QUARTER(created_at)";
        } else {
            $dateFormat = match($period) {
                'daily' => '%Y-%m-%d',
                'weekly' => '%Y-%u', // 周数
                'monthly' => '%Y-%m',
                'yearly' => '%Y',
                default => '%Y-%m-%d'
            };
            $selectDate = "DATE_FORMAT(created_at, '{$dateFormat}') as period_date";
            $groupBy = "DATE_FORMAT(created_at, '{$dateFormat}')";
        }
        
        // 构建SQL查询
        $sql = "SELECT 
                    {$selectDate},
                    SUM(value) as total_value,
                    COUNT(*) as total_events,
                    COUNT(DISTINCT event) as event_types,
                    AVG(value) as avg_value
                FROM `{$tableName}`
                WHERE website_id = :website_id
                AND created_at >= :start_date
                AND created_at <= :end_date
                GROUP BY {$groupBy}
                ORDER BY period_date ASC";
        
        try {
            $pdo = $connector->getLink();
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':website_id' => $websiteId,
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ]);
            
            $dataPoints = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // 计算总价值
            $totalValue = array_sum(array_column($dataPoints, 'total_value'));
            $totalEvents = array_sum(array_column($dataPoints, 'total_events'));
            
            // 格式化数据点
            $formattedDataPoints = [];
            foreach ($dataPoints as $point) {
                $formattedDataPoints[] = [
                    'date' => $point['period_date'],
                    'value' => (float)$point['total_value'],
                    'events' => (int)$point['total_events'],
                    'event_types' => (int)$point['event_types'],
                    'avg_value' => (float)$point['avg_value'],
                    'conversion_rate' => $point['total_events'] > 0 ? (float)$point['total_value'] / (int)$point['total_events'] : 0
                ];
            }
            
            return [
                'period' => $period,
                'website_id' => $websiteId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_value' => (float)$totalValue,
                'total_events' => (int)$totalEvents,
                'data_points' => $formattedDataPoints
            ];
            
        } catch (\Exception $e) {
            throw new \Exception(__('获取商业价值数据失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 获取大屏展示数据（实时数据）
     * 
     * @param int $websiteId 站点ID
     * @param int $interval 时间间隔（分钟）：10或30
     * @param int $hours 获取最近N小时的数据
     * @return array
     */
    public static function getDashboardData(int $websiteId, int $interval = 10, int $hours = 24): array
    {
        $endTime = date('Y-m-d H:i:s');
        $startTime = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        
        // 根据时间间隔构建SQL时间格式化
        // 10分钟：将时间向下取整到10分钟
        // 30分钟：将时间向下取整到30分钟
        if ($interval === 10) {
            $timeFormat = "DATE_FORMAT(DATE_SUB(created_at, INTERVAL MINUTE(created_at) % 10 MINUTE), '%Y-%m-%d %H:%i:00')";
        } else {
            $timeFormat = "DATE_FORMAT(DATE_SUB(created_at, INTERVAL MINUTE(created_at) % 30 MINUTE), '%Y-%m-%d %H:%i:00')";
        }
        
        $model = w_obj(self::class);
        $connector = $model->getConnection()->getConnector();
        $prefix = $model->getConnection()->getConfigProvider()->getPrefix();
        $tableName = $prefix . $model->getTable();
        
        $sql = "SELECT 
                    {$timeFormat} as time_slot,
                    SUM(value) as total_value,
                    COUNT(*) as total_events
                FROM `{$tableName}`
                WHERE website_id = :website_id
                AND created_at >= :start_time
                AND created_at <= :end_time
                GROUP BY time_slot
                ORDER BY time_slot ASC";
        
        try {
            $pdo = $connector->getLink();
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':website_id' => $websiteId,
                ':start_time' => $startTime,
                ':end_time' => $endTime
            ]);
            
            $dataPoints = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // 获取当前时间段的数据
            $currentTimeSlot = date('Y-m-d H:i:00');
            if ($interval === 10) {
                $currentMinute = (int)date('i');
                $currentTimeSlot = date('Y-m-d H:i:00', strtotime("-" . ($currentMinute % 10) . " minutes"));
            } else {
                $currentMinute = (int)date('i');
                $currentTimeSlot = date('Y-m-d H:i:00', strtotime("-" . ($currentMinute % 30) . " minutes"));
            }
            
            $currentPeriod = null;
            foreach ($dataPoints as $point) {
                if ($point['time_slot'] === $currentTimeSlot) {
                    $currentPeriod = [
                        'value' => (float)$point['total_value'],
                        'events' => (int)$point['total_events'],
                        'timestamp' => $point['time_slot']
                    ];
                    break;
                }
            }
            
            // 计算变化百分比（当前时间段相比上一个时间段）
            $changePercentage = 0;
            if (count($dataPoints) >= 2) {
                $current = end($dataPoints);
                $previous = $dataPoints[count($dataPoints) - 2];
                if ($previous['total_value'] > 0) {
                    $changePercentage = (($current['total_value'] - $previous['total_value']) / $previous['total_value']) * 100;
                }
            }
            
            // 格式化数据点
            $formattedDataPoints = [];
            foreach ($dataPoints as $point) {
                $formattedDataPoints[] = [
                    'timestamp' => $point['time_slot'],
                    'value' => (float)$point['total_value'],
                    'events' => (int)$point['total_events']
                ];
            }
            
            return [
                'interval' => $interval,
                'website_id' => $websiteId,
                'hours' => $hours,
                'current_period' => $currentPeriod,
                'data_points' => $formattedDataPoints,
                'change_percentage' => round($changePercentage, 2)
            ];
            
        } catch (\Exception $e) {
            throw new \Exception(__('获取大屏数据失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 获取数据变化百分比
     * 
     * @param int $websiteId 站点ID
     * @param int $interval 时间间隔（分钟）
     * @param int $hours 获取最近N小时的数据
     * @return array
     */
    public static function getChangePercentageData(int $websiteId, int $interval = 10, int $hours = 24): array
    {
        $endTime = date('Y-m-d H:i:s');
        $startTime = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        
        // 根据时间间隔构建SQL时间格式化
        if ($interval === 10) {
            $timeFormat = "DATE_FORMAT(DATE_SUB(created_at, INTERVAL MINUTE(created_at) % 10 MINUTE), '%Y-%m-%d %H:%i:00')";
        } else {
            $timeFormat = "DATE_FORMAT(DATE_SUB(created_at, INTERVAL MINUTE(created_at) % 30 MINUTE), '%Y-%m-%d %H:%i:00')";
        }
        
        $model = w_obj(self::class);
        $connector = $model->getConnection()->getConnector();
        $prefix = $model->getConnection()->getConfigProvider()->getPrefix();
        $tableName = $prefix . $model->getTable();
        
        $sql = "SELECT 
                    {$timeFormat} as time_slot,
                    SUM(value) as total_value,
                    COUNT(*) as total_events
                FROM `{$tableName}`
                WHERE website_id = :website_id
                AND created_at >= :start_time
                AND created_at <= :end_time
                GROUP BY time_slot
                ORDER BY time_slot ASC";
        
        try {
            $pdo = $connector->getLink();
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':website_id' => $websiteId,
                ':start_time' => $startTime,
                ':end_time' => $endTime
            ]);
            
            $dataPoints = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // 计算每个时刻的变化百分比
            $formattedDataPoints = [];
            $previousValue = null;
            $previousEvents = null;
            
            foreach ($dataPoints as $point) {
                $currentValue = (float)$point['total_value'];
                $currentEvents = (int)$point['total_events'];
                
                $changePercentage = 0;
                if ($previousValue !== null && $previousValue > 0) {
                    $changePercentage = (($currentValue - $previousValue) / $previousValue) * 100;
                }
                
                $formattedDataPoints[] = [
                    'timestamp' => $point['time_slot'],
                    'value' => $currentValue,
                    'events' => $currentEvents,
                    'change_percentage' => round($changePercentage, 2),
                    'previous_value' => $previousValue
                ];
                
                $previousValue = $currentValue;
                $previousEvents = $currentEvents;
            }
            
            return [
                'website_id' => $websiteId,
                'interval' => $interval,
                'hours' => $hours,
                'data_points' => $formattedDataPoints
            ];
            
        } catch (\Exception $e) {
            throw new \Exception(__('获取变化百分比数据失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 获取每日对比分析（相比昨天）
     * 
     * @param int $websiteId 站点ID
     * @param int $days 获取最近N天的对比数据
     * @return array
     */
    public static function getDailyComparisonData(int $websiteId, int $days = 7): array
    {
        $endDate = date('Y-m-d 23:59:59');
        $startDate = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        
        $model = w_obj(self::class);
        $connector = $model->getConnection()->getConnector();
        $prefix = $model->getConnection()->getConfigProvider()->getPrefix();
        $tableName = $prefix . $model->getTable();
        
        $sql = "SELECT 
                    DATE(created_at) as date,
                    SUM(value) as total_value,
                    COUNT(*) as total_events
                FROM `{$tableName}`
                WHERE website_id = :website_id
                AND created_at >= :start_date
                AND created_at <= :end_date
                GROUP BY DATE(created_at)
                ORDER BY date ASC";
        
        try {
            $pdo = $connector->getLink();
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':website_id' => $websiteId,
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ]);
            
            $dailyData = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // 构建日期索引数组
            $dataByDate = [];
            foreach ($dailyData as $data) {
                $dataByDate[$data['date']] = [
                    'value' => (float)$data['total_value'],
                    'events' => (int)$data['total_events']
                ];
            }
            
            // 生成对比数据
            $comparisons = [];
            for ($i = 0; $i < $days; $i++) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $yesterday = date('Y-m-d', strtotime("-" . ($i + 1) . " days"));
                
                $today = $dataByDate[$date] ?? ['value' => 0, 'events' => 0];
                $yesterdayData = $dataByDate[$yesterday] ?? ['value' => 0, 'events' => 0];
                
                $changeValue = $today['value'] - $yesterdayData['value'];
                $changePercentage = 0;
                if ($yesterdayData['value'] > 0) {
                    $changePercentage = ($changeValue / $yesterdayData['value']) * 100;
                } elseif ($today['value'] > 0) {
                    $changePercentage = 100; // 昨天为0，今天有值，增长100%
                }
                
                $comparisons[] = [
                    'date' => $date,
                    'today' => $today,
                    'yesterday' => $yesterdayData,
                    'change_percentage' => round($changePercentage, 2),
                    'change_value' => round($changeValue, 2)
                ];
            }
            
            // 反转数组，使日期从旧到新
            $comparisons = array_reverse($comparisons);
            
            return [
                'website_id' => $websiteId,
                'days' => $days,
                'comparisons' => $comparisons
            ];
            
        } catch (\Exception $e) {
            throw new \Exception(__('获取每日对比数据失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 获取A/B测试数据
     * 
     * @param int $websiteId 站点ID
     * @param string $testId 测试ID
     * @param string|null $variant 测试变体（A或B），如果为null则返回所有变体
     * @return array
     */
    public static function getAbTestData(int $websiteId, string $testId, ?string $variant = null): array
    {
        // A/B测试数据存储在PixelAdditional的total_event_data中
        // 假设total_event_data JSON中包含test_id和variant字段
        $model = w_obj(self::class);
        $connector = $model->getConnection()->getConnector();
        $prefix = $model->getConnection()->getConfigProvider()->getPrefix();
        $tableName = $prefix . $model->getTable();
        $additionalTableName = $prefix . 'w_pixel_additional';
        
        // 构建SQL查询（需要JOIN PixelAdditional表）
        // 注意：MySQL的JSON_EXTRACT需要正确的JSON路径格式
        $sql = "SELECT 
                    p.value,
                    p.event,
                    pa.total_event_data
                FROM `{$tableName}` p
                INNER JOIN `{$additionalTableName}` pa ON p.pixel_id = pa.pixel_id
                WHERE p.website_id = :website_id
                AND JSON_EXTRACT(pa.total_event_data, '$.testId') = :test_id";
        
        if ($variant !== null) {
            $sql .= " AND JSON_EXTRACT(pa.total_event_data, '$.variant') = :variant";
        }
        
        try {
            $pdo = $connector->getLink();
            $stmt = $pdo->prepare($sql);
            
            // JSON_EXTRACT返回的是JSON值，需要正确转义
            // 对于字符串值，JSON_EXTRACT会返回带引号的JSON字符串
            // 所以我们需要匹配JSON字符串格式
            $testIdJson = json_encode($testId);  // 这会自动添加引号和转义
            $params = [
                ':website_id' => $websiteId,
                ':test_id' => $testIdJson
            ];
            if ($variant !== null) {
                $params[':variant'] = json_encode($variant);  // 自动添加引号和转义
            }
            $stmt->execute($params);
            
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // 按变体分组统计
            $variants = [];
            foreach ($results as $row) {
                $eventData = json_decode($row['total_event_data'], true);
                if (!is_array($eventData)) {
                    continue;  // 跳过无效的JSON数据
                }
                $var = $eventData['variant'] ?? $eventData['testVariant'] ?? 'unknown';
                
                if (!isset($variants[$var])) {
                    $variants[$var] = [
                        'value' => 0,
                        'events' => 0,
                        'conversion_rate' => 0
                    ];
                }
                
                $variants[$var]['value'] += (float)$row['value'];
                $variants[$var]['events']++;
            }
            
            // 计算转化率
            foreach ($variants as $var => &$data) {
                if ($data['events'] > 0) {
                    $data['conversion_rate'] = round($data['value'] / $data['events'], 4);
                }
            }
            
            // 确定获胜者（转化率最高的）
            $winner = null;
            $maxConversionRate = 0;
            foreach ($variants as $var => $data) {
                if ($data['conversion_rate'] > $maxConversionRate) {
                    $maxConversionRate = $data['conversion_rate'];
                    $winner = $var;
                }
            }
            
            // 计算改进百分比（相比基准变体A）
            $improvement = 0;
            if (isset($variants['A']) && isset($variants['B'])) {
                if ($variants['A']['conversion_rate'] > 0) {
                    $improvement = (($variants['B']['conversion_rate'] - $variants['A']['conversion_rate']) / $variants['A']['conversion_rate']) * 100;
                }
            }
            
            return [
                'test_id' => $testId,
                'website_id' => $websiteId,
                'variants' => $variants,
                'winner' => $winner,
                'improvement' => round($improvement, 2)
            ];
            
        } catch (\Exception $e) {
            throw new \Exception(__('获取A/B测试数据失败：%{1}', [$e->getMessage()]));
        }
    }
public function getPixelId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }
    public function getUrl(): string
    {
        return (string)$this->getData(self::schema_fields_URL);
    }
    public function getModule(): string
    {
        return (string)$this->getData(self::schema_fields_MODULE);
    }
    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }
    public function getReferer(): string
    {
        return (string)$this->getData(self::schema_fields_REFERER);
    }
    public function getSource(): string
    {
        return (string)$this->getData(self::schema_fields_SOURCE);
    }
    public function getUserId(): int
    {
        return (int)$this->getData(self::schema_fields_USER_ID);
    }
    public function getUserAgent(): string
    {
        return (string)$this->getData(self::schema_fields_USER_AGENT);
    }
    public function getIp(): string
    {
        return (string)$this->getData(self::schema_fields_IP);
    }
    public function getEvent(): string
    {
        return (string)$this->getData(self::schema_fields_EVENT);
    }
    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_WEBSITE_ID);
    }
    public function getLang(): string
    {
        return (string)$this->getData(self::schema_fields_LANG);
    }
    public function getCurrency(): string
    {
        return (string)$this->getData(self::schema_fields_CURRENCY);
    }
    public function getValue(): int
    {
        return (int)$this->getData(self::schema_fields_VALUE);
    }
    public function getBrowserInfo(): array
    {
        return (array)$this->getData(self::schema_fields_BROWSER_INFO);
    }
    public function getCronDeal(): int
    {
        return (int)$this->getData(self::schema_fields_CRON_DEAL);
    }
    public function setPixelId(int $pixel_id): static
    {
        return $this->setData(self::schema_fields_ID, $pixel_id);
    }
    public function setUrl(string $url): static
    {
        return $this->setData(self::schema_fields_URL, $url);
    }
    public function setModule(string $module): static
    {
        return $this->setData(self::schema_fields_MODULE, $module);
    }
    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }
    public function setReferer(string $referer): static
    {
        return $this->setData(self::schema_fields_REFERER, $referer);
    }
    public function setSource(string $source): static
    {
        return $this->setData(self::schema_fields_SOURCE, $source);
    }
    public function setUserId(int $user_id): static
    {
        return $this->setData(self::schema_fields_USER_ID, $user_id);
    }
    public function setUserAgent(string $user_agent): static
    {
        return $this->setData(self::schema_fields_USER_AGENT, $user_agent);
    }
    public function setIp(string $ip): static
    {
        return $this->setData(self::schema_fields_IP, $ip);
    }
    public function setEvent(string $event): static
    {
        return $this->setData(self::schema_fields_EVENT, $event);
    }
    public function setWebsiteId(int $website_id): static
    {
        return $this->setData(self::schema_fields_WEBSITE_ID, $website_id);
    }
    public function setLang(string $lang): static
    {
        return $this->setData(self::schema_fields_LANG, $lang);
    }
    public function setCurrency(string $currency): static
    {
        return $this->setData(self::schema_fields_CURRENCY, $currency);
    }
    public function setValue(int $value): static
    {
        return $this->setData(self::schema_fields_VALUE, $value);
    }
    public function setBrowserInfo(array $browser_info): static
    {
        return $this->setData(self::schema_fields_BROWSER_INFO, $browser_info);
    }
    public function setCronDeal(int $cron_deal): static
    {
        return $this->setData(self::schema_fields_CRON_DEAL, $cron_deal);
    }
    public function getCreatedAt(): string
    {
        return (string)$this->getData(self::schema_fields_CREATED_AT);
    }
    public function setCreatedAt(string $created_at): static
    {
        return $this->setData(self::schema_fields_CREATED_AT, $created_at);
    }
}