<?php

namespace Weline\Visitor\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Visitor\Model\Pixel;

/**
 * 像素统计API
 * 提供像素数据的统计查询接口
 */
class Statistics extends FrontendRestController
{
    /**
     * 获取站点统计信息
     * 
     * @return string
     * @Document(summary='获取站点统计信息', description='获取指定站点的像素统计信息，包括总记录数、事件统计等', tags=['像素', '统计'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/website
     * Request Parameters:
     * - websiteId: 1 (可选，从URL参数、GET参数或SERVER变量获取，默认使用当前请求的站点ID)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取统计信息成功",
     *   "data": {
     *     "website_id": 1,
     *     "total_count": 1000,
     *     "events": {
     *       "click": 500,
     *       "login": 200,
     *       "register": 100
     *     },
     *     "event_list": ["click", "login", "register"]
     *   }
     * }
     * @example-end
     */
    public function getWebsite(): string
    {
        try {
            // 获取站点ID（优先从请求参数获取，其次从SERVER变量获取）
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }
            
            // 获取时间范围参数（可选）
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            // 如果提供了时间范围，使用时间范围统计
            if ($startDate !== null || $endDate !== null) {
                $stats = Pixel::getWebsiteStatsByDateRange($websiteId, $startDate, $endDate);
            } else {
                // 否则使用统计摘要
                $stats = Pixel::getWebsiteSummary($websiteId);
            }
            
            return $this->success(__('获取统计信息成功'), $stats);
            
        } catch (\Exception $e) {
            return $this->error(__('获取统计信息失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取事件统计信息（支持时间范围）
     * 
     * @return string
     * @Document(summary='获取事件统计信息', description='获取指定站点和事件的统计信息，支持时间范围筛选', tags=['像素', '统计'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/event
     * Request Parameters:
     * - websiteId: 1 (可选，从URL参数、GET参数或SERVER变量获取)
     * - event: login (必填，事件名称)
     * - startDate: 2025-01-01 00:00:00 (可选，开始日期)
     * - endDate: 2025-01-31 23:59:59 (可选，结束日期)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取事件统计成功",
     *   "data": {
     *     "website_id": 1,
     *     "event": "login",
     *     "count": 200
     *   }
     * }
     * @example-end
     */
    public function getEvent(): string
    {
        try {
            // 获取站点ID
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }
            
            // 获取事件名
            $event = $this->request->getParam('event') ?? $this->request->getGet('event') ?? '';
            if (empty($event)) {
                return $this->error(__('事件名称不能为空'), '', 400);
            }
            
            // 获取时间范围
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            // 如果有时间范围，使用条件查询
            if ($startDate || $endDate) {
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
            } else {
                // 否则使用原有方法
                $count = Pixel::countPixelsByWebsiteIdAndEvent($websiteId, $event);
            }
            
            return $this->success(__('获取事件统计成功'), [
                'website_id' => $websiteId,
                'event' => $event,
                'count' => $count,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取事件统计失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取所有站点列表
     * 
     * @return string
     * @Document(summary='获取所有站点列表', description='获取系统中所有有像素记录的站点ID列表', tags=['像素', '统计'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/websites
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取站点列表成功",
     *   "data": {
     *     "website_ids": [0, 1, 2, 3]
     *   }
     * }
     * @example-end
     */
    public function getWebsites(): string
    {
        try {
            $websiteIds = Pixel::getAllWebsiteIds();
            
            return $this->success(__('获取站点列表成功'), [
                'website_ids' => $websiteIds,
                'count' => count($websiteIds)
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取站点列表失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取商业价值分析数据
     * 
     * @return string
     * @Document(summary='获取商业价值分析数据', description='获取指定时间维度的商业价值分析数据', tags=['像素', '统计', '分析'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/business-value
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - period: daily|weekly|monthly|quarterly|yearly (必填)
     * - startDate: 2025-01-01 (可选)
     * - endDate: 2025-01-31 (可选)
     * @example-end
     */
    public function getBusinessValue(): string
    {
        try {
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }
            
            $period = $this->request->getParam('period') ?? $this->request->getGet('period') ?? 'daily';
            $allowedPeriods = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];
            if (!in_array($period, $allowedPeriods)) {
                return $this->error(__('时间维度参数错误，支持：%{1}', [implode(', ', $allowedPeriods)]), '', 400);
            }
            
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            $data = Pixel::getBusinessValueByPeriod($websiteId, $period, $startDate, $endDate);
            
            return $this->success(__('获取商业价值分析成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取商业价值分析失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取实时大屏数据
     * 
     * @return string
     * @Document(summary='获取实时大屏数据', description='获取实时数据用于大屏展示', tags=['像素', '统计', '实时'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/dashboard
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - interval: 10|30 (可选，时间间隔（分钟），默认10)
     * - hours: 24 (可选，获取最近N小时的数据，默认24)
     * @example-end
     */
    public function getDashboard(): string
    {
        try {
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }
            
            $interval = (int)($this->request->getParam('interval') ?? $this->request->getGet('interval') ?? 10);
            if (!in_array($interval, [10, 30])) {
                $interval = 10;
            }
            
            $hours = (int)($this->request->getParam('hours') ?? $this->request->getGet('hours') ?? 24);
            
            $data = Pixel::getDashboardData($websiteId, $interval, $hours);
            
            return $this->success(__('获取大屏数据成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取大屏数据失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取每日对比数据
     * 
     * @return string
     * @Document(summary='获取每日对比数据', description='获取每天相比昨天的数据分析', tags=['像素', '统计', '对比'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/daily-comparison
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - days: 7 (可选，获取最近N天的对比数据，默认7)
     * @example-end
     */
    public function getDailyComparison(): string
    {
        try {
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }
            
            $days = (int)($this->request->getParam('days') ?? $this->request->getGet('days') ?? 7);
            
            $data = Pixel::getDailyComparisonData($websiteId, $days);
            
            return $this->success(__('获取每日对比数据成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取每日对比数据失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取变化百分比数据
     * 
     * @return string
     * @Document(summary='获取变化百分比数据', description='获取每个时刻数据相比前一时刻的变化百分比', tags=['像素', '统计', '分析'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/change-percentage
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - interval: 10|30 (可选，时间间隔（分钟），默认10)
     * - hours: 24 (可选，获取最近N小时的数据，默认24)
     * @example-end
     */
    public function getChangePercentage(): string
    {
        try {
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }
            
            $interval = (int)($this->request->getParam('interval') ?? $this->request->getGet('interval') ?? 10);
            if (!in_array($interval, [10, 30])) {
                $interval = 10;
            }
            
            $hours = (int)($this->request->getParam('hours') ?? $this->request->getGet('hours') ?? 24);
            
            $data = Pixel::getChangePercentageData($websiteId, $interval, $hours);
            
            return $this->success(__('获取变化百分比成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取变化百分比失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取热门事件Top N
     * 
     * @return string
     * @Document(summary='获取热门事件Top N', description='获取指定站点的事件统计，按数量排序', tags=['像素', '统计'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/top-events
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - limit: 10 (可选，返回前N个事件，默认10)
     * - startDate: 2025-01-01 (可选，开始日期)
     * - endDate: 2025-01-31 (可选，结束日期)
     * @example-end
     */
    public function getTopEvents(): string
    {
        try {
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }
            
            $limit = (int)($this->request->getParam('limit') ?? $this->request->getGet('limit') ?? 10);
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            // 获取事件列表
            $eventList = Pixel::getEventsByWebsiteId($websiteId);
            
            // 统计每个事件的数量
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
            $topEvents = array_slice($eventStats, 0, $limit, true);
            
            return $this->success(__('获取热门事件成功'), [
                'website_id' => $websiteId,
                'top_events' => $topEvents,
                'limit' => $limit,
                'total_events' => count($eventStats)
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取热门事件失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取事件分布统计
     * 
     * @return string
     * @Document(summary='获取事件分布统计', description='获取事件分布统计，用于饼图展示', tags=['像素', '统计'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/event-distribution
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - startDate: 2025-01-01 (可选，开始日期)
     * - endDate: 2025-01-31 (可选，结束日期)
     * @example-end
     */
    public function getEventDistribution(): string
    {
        try {
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }
            
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            // 获取事件列表
            $eventList = Pixel::getEventsByWebsiteId($websiteId);
            
            // 统计每个事件的数量和占比
            $eventStats = [];
            $totalCount = 0;
            
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
                    $totalCount += $count;
                }
            }
            
            // 计算占比
            $distribution = [];
            foreach ($eventStats as $event => $count) {
                $distribution[$event] = [
                    'count' => $count,
                    'percentage' => $totalCount > 0 ? round(($count / $totalCount) * 100, 2) : 0
                ];
            }
            
            return $this->success(__('获取事件分布成功'), [
                'website_id' => $websiteId,
                'distribution' => $distribution,
                'total_count' => $totalCount
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取事件分布失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取时间范围统计（增强版）
     * 
     * @return string
     * @Document(summary='获取时间范围统计', description='获取指定时间范围的详细统计数据', tags=['像素', '统计'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/time-range
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - startDate: 2025-01-01 00:00:00 (必填，开始日期)
     * - endDate: 2025-01-31 23:59:59 (必填，结束日期)
     * @example-end
     */
    public function getTimeRangeStats(): string
    {
        try {
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }
            
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            if (empty($startDate) || empty($endDate)) {
                return $this->error(__('开始日期和结束日期不能为空'), '', 400);
            }
            
            // 获取时间范围统计
            $stats = Pixel::getWebsiteStatsByDateRange($websiteId, $startDate, $endDate);
            
            // 计算总价值
            $pixels = Pixel::getPixelsByWebsiteId($websiteId, [
                Pixel::schema_fields_CREATED_AT => [
                    'operator' => '>=',
                    'value' => $startDate
                ]
            ]);
            
            $pixels = array_filter($pixels, function($pixel) use ($endDate) {
                return ($pixel[Pixel::schema_fields_CREATED_AT] ?? '') <= $endDate;
            });
            
            $totalValue = 0;
            foreach ($pixels as $pixel) {
                $totalValue += (float)($pixel[Pixel::schema_fields_VALUE] ?? 0);
            }
            
            $stats['total_value'] = $totalValue;
            $stats['avg_value'] = $stats['total_count'] > 0 ? round($totalValue / $stats['total_count'], 2) : 0;
            
            return $this->success(__('获取时间范围统计成功'), $stats);
            
        } catch (\Exception $e) {
            return $this->error(__('获取时间范围统计失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取实时统计数据
     * 
     * @return string
     * @Document(summary='获取实时统计数据', description='获取最近一段时间的实时统计数据', tags=['像素', '统计', '实时'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/statistics/realtime
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - minutes: 60 (可选，获取最近N分钟的数据，默认60)
     * @example-end
     */
    public function getRealtimeStats(): string
    {
        try {
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }
            
            $minutes = (int)($this->request->getParam('minutes') ?? $this->request->getGet('minutes') ?? 60);
            
            $endTime = date('Y-m-d H:i:s');
            $startTime = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));
            
            // 获取最近N分钟的数据
            $model = w_obj(Pixel::class)->reset()
                ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Pixel::schema_fields_CREATED_AT, $startTime, '>=')
                ->where(Pixel::schema_fields_CREATED_AT, $endTime, '<=');
            
            $totalCount = (int)$model->count();
            
            // 计算总价值
            $pixels = $model->select()->fetchArray();
            $totalValue = 0;
            foreach ($pixels as $pixel) {
                $totalValue += (float)($pixel[Pixel::schema_fields_VALUE] ?? 0);
            }
            
            // 获取事件统计
            $eventModel = w_obj(Pixel::class)->reset()
                ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Pixel::schema_fields_CREATED_AT, $startTime, '>=')
                ->where(Pixel::schema_fields_CREATED_AT, $endTime, '<=')
                ->field(Pixel::schema_fields_EVENT)
                ->group(Pixel::schema_fields_EVENT);
            
            $eventResult = $eventModel->select()->fetchArray();
            $events = [];
            foreach ($eventResult as $row) {
                $event = $row[Pixel::schema_fields_EVENT] ?? '';
                if ($event) {
                    $events[$event] = Pixel::countPixelsByWebsiteIdAndEvent($websiteId, $event);
                }
            }
            
            return $this->success(__('获取实时统计成功'), [
                'website_id' => $websiteId,
                'time_range' => [
                    'start' => $startTime,
                    'end' => $endTime,
                    'minutes' => $minutes
                ],
                'total_count' => $totalCount,
                'total_value' => $totalValue,
                'avg_value' => $totalCount > 0 ? round($totalValue / $totalCount, 2) : 0,
                'events' => $events,
                'event_count' => count($events)
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取实时统计失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
}

