<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
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
                            Pixel::fields_CREATED_AT => [
                                'operator' => '>=',
                                'value' => $dayStart
                            ]
                        ]);
                        
                        $pixels = array_filter($pixels, function($pixel) use ($dayEnd) {
                            return ($pixel[Pixel::fields_CREATED_AT] ?? '') <= $dayEnd;
                        });
                        
                        foreach ($pixels as $pixel) {
                            $dayValue += (float)($pixel[Pixel::fields_VALUE] ?? 0);
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
                        ->where(Pixel::fields_WEBSITE_ID, $websiteId)
                        ->where(Pixel::fields_EVENT, $event);
                    
                    if ($startDate) {
                        $model->where(Pixel::fields_CREATED_AT, $startDate, '>=');
                    }
                    if ($endDate) {
                        $model->where(Pixel::fields_CREATED_AT, $endDate, '<=');
                    }
                    
                    return (int)$model->count();
                } else {
                    // 所有事件统计
                    $eventList = Pixel::getEventsByWebsiteId($websiteId);
                    $eventStats = [];
                    
                    foreach ($eventList as $evt) {
                        $model = w_obj(Pixel::class)->reset()
                            ->where(Pixel::fields_WEBSITE_ID, $websiteId)
                            ->where(Pixel::fields_EVENT, $evt);
                        
                        if ($startDate) {
                            $model->where(Pixel::fields_CREATED_AT, $startDate, '>=');
                        }
                        if ($endDate) {
                            $model->where(Pixel::fields_CREATED_AT, $endDate, '<=');
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
                    ->where(Pixel::fields_WEBSITE_ID, $websiteId)
                    ->where(Pixel::fields_EVENT, $event);
                
                if ($startDate) {
                    $model->where(Pixel::fields_CREATED_AT, $startDate, '>=');
                }
                if ($endDate) {
                    $model->where(Pixel::fields_CREATED_AT, $endDate, '<=');
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
}

