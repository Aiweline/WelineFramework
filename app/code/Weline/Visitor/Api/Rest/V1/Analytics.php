<?php

namespace Weline\Visitor\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\AbTest;

/**
 * 像素数据分析API
 * 提供像素数据的商业价值分析和统计功能
 */
class Analytics extends FrontendRestController
{
    /**
     * 获取商业价值分析数据
     * 
     * @return string
     * @Document(summary='获取商业价值分析数据', description='获取指定时间维度的商业价值分析数据，支持每天、每周、每月、每季度、每年', tags=['像素', '分析'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/analytics/business-value
     * Request Parameters:
     * - websiteId: 1 (可选，从URL参数、GET参数或SERVER变量获取)
     * - period: daily|weekly|monthly|quarterly|yearly (必填，时间维度)
     * - startDate: 2025-01-01 (可选，开始日期)
     * - endDate: 2025-01-31 (可选，结束日期)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取商业价值分析成功",
     *   "data": {
     *     "period": "daily",
     *     "website_id": 1,
     *     "total_value": 10000,
     *     "total_events": 1000,
     *     "data_points": [
     *       {
     *         "date": "2025-01-01",
     *         "value": 1000,
     *         "events": 100,
     *         "conversion_rate": 0.1
     *       }
     *     ]
     *   }
     * }
     * @example-end
     */
    public function getBusinessValue(): string
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
            
            // 获取时间维度
            $period = $this->request->getParam('period') ?? $this->request->getGet('period') ?? 'daily';
            $allowedPeriods = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];
            if (!in_array($period, $allowedPeriods)) {
                return $this->error(__('时间维度参数错误，支持：%{1}', [implode(', ', $allowedPeriods)]), '', 400);
            }
            
            // 获取时间范围
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            // 根据时间维度获取数据
            $data = Pixel::getBusinessValueByPeriod($websiteId, $period, $startDate, $endDate);
            
            return $this->success(__('获取商业价值分析成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取商业价值分析失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取大屏展示数据（实时数据）
     * 
     * @return string
     * @Document(summary='获取大屏展示数据', description='获取实时数据用于大屏展示，支持10分钟和30分钟粒度', tags=['像素', '分析', '大屏'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/analytics/dashboard
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - interval: 10|30 (可选，时间间隔（分钟），默认10)
     * - hours: 24 (可选，获取最近N小时的数据，默认24)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取大屏数据成功",
     *   "data": {
     *     "interval": 10,
     *     "website_id": 1,
     *     "current_period": {
     *       "value": 1000,
     *       "events": 100,
     *       "timestamp": "2025-01-01 12:00:00"
     *     },
     *     "data_points": [
     *       {
     *         "timestamp": "2025-01-01 12:00:00",
     *         "value": 100,
     *         "events": 10
     *       }
     *     ],
     *     "change_percentage": 5.5
     *   }
     * }
     * @example-end
     */
    public function getDashboard(): string
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
            
            // 获取时间间隔（分钟）
            $interval = (int)($this->request->getParam('interval') ?? $this->request->getGet('interval') ?? 10);
            if (!in_array($interval, [10, 30])) {
                $interval = 10;
            }
            
            // 获取时间范围（小时）
            $hours = (int)($this->request->getParam('hours') ?? $this->request->getGet('hours') ?? 24);
            
            // 获取大屏数据
            $data = Pixel::getDashboardData($websiteId, $interval, $hours);
            
            return $this->success(__('获取大屏数据成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取大屏数据失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取数据变化百分比
     * 
     * @return string
     * @Document(summary='获取数据变化百分比', description='获取每个时刻数据相比前一时刻的变化百分比', tags=['像素', '分析'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/analytics/change-percentage
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - interval: 10|30 (可选，时间间隔（分钟），默认10)
     * - hours: 24 (可选，获取最近N小时的数据，默认24)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取变化百分比成功",
     *   "data": {
     *     "website_id": 1,
     *     "interval": 10,
     *     "data_points": [
     *       {
     *         "timestamp": "2025-01-01 12:00:00",
     *         "value": 100,
     *         "events": 10,
     *         "change_percentage": 5.5,
     *         "previous_value": 95
     *       }
     *     ]
     *   }
     * }
     * @example-end
     */
    public function getChangePercentage(): string
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
            
            // 获取时间间隔
            $interval = (int)($this->request->getParam('interval') ?? $this->request->getGet('interval') ?? 10);
            if (!in_array($interval, [10, 30])) {
                $interval = 10;
            }
            
            // 获取时间范围
            $hours = (int)($this->request->getParam('hours') ?? $this->request->getGet('hours') ?? 24);
            
            // 获取变化百分比数据
            $data = Pixel::getChangePercentageData($websiteId, $interval, $hours);
            
            return $this->success(__('获取变化百分比成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取变化百分比失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取每日对比分析（相比昨天）
     * 
     * @return string
     * @Document(summary='获取每日对比分析', description='获取每天相比昨天的数据分析', tags=['像素', '分析'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/analytics/daily-comparison
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - days: 7 (可选，获取最近N天的对比数据，默认7)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取每日对比分析成功",
     *   "data": {
     *     "website_id": 1,
     *     "comparisons": [
     *       {
     *         "date": "2025-01-01",
     *         "today": {
     *           "value": 1000,
     *           "events": 100
     *         },
     *         "yesterday": {
     *           "value": 950,
     *           "events": 95
     *         },
     *         "change_percentage": 5.26,
     *         "change_value": 50
     *       }
     *     ]
     *   }
     * }
     * @example-end
     */
    public function getDailyComparison(): string
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
            
            // 获取天数
            $days = (int)($this->request->getParam('days') ?? $this->request->getGet('days') ?? 7);
            
            // 获取每日对比数据
            $data = Pixel::getDailyComparisonData($websiteId, $days);
            
            return $this->success(__('获取每日对比分析成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取每日对比分析失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取A/B测试数据
     * 
     * @return string
     * @Document(summary='获取A/B测试数据', description='获取A/B测试的对比分析数据', tags=['像素', '分析', 'A/B测试'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/analytics/ab-test
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - testId: test_001 (必填，测试ID)
     * - variant: A|B (可选，测试变体，不传则返回所有变体)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取A/B测试数据成功",
     *   "data": {
     *     "test_id": "test_001",
     *     "website_id": 1,
     *     "variants": {
     *       "A": {
     *         "value": 1000,
     *         "events": 100,
     *         "conversion_rate": 0.1
     *       },
     *       "B": {
     *         "value": 1200,
     *         "events": 120,
     *         "conversion_rate": 0.12
     *       }
     *     },
     *     "winner": "B",
     *     "improvement": 20
     *   }
     * }
     * @example-end
     */
    public function getAbTest(): string
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
            
            // 获取测试ID
            $testId = $this->request->getParam('testId') ?? $this->request->getGet('testId') ?? '';
            if (empty($testId)) {
                return $this->error(__('测试ID不能为空'), '', 400);
            }
            
            // 获取测试变体
            $variant = $this->request->getParam('variant') ?? $this->request->getGet('variant');
            
            // 获取A/B测试数据
            $data = Pixel::getAbTestData($websiteId, $testId, $variant);
            
            return $this->success(__('获取A/B测试数据成功'), $data);
            
        } catch (\Exception $e) {
            return $this->error(__('获取A/B测试数据失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 创建A/B测试
     * 
     * @return string
     * @Document(summary='创建A/B测试', description='创建新的A/B测试配置', tags=['像素', '分析', 'A/B测试'], category='像素接口')
     * @example
     * Method: POST
     * Path: /visitor/rest/v1/analytics/ab-test/create
     * Body:
     * {
     *   "testId": "test_001",
     *   "name": "首页按钮颜色测试",
     *   "description": "测试不同按钮颜色对转化率的影响",
     *   "websiteId": 1,
     *   "variantA": {"color": "blue"},
     *   "variantB": {"color": "red"},
     *   "trafficSplit": "50:50"
     * }
     * @example-end
     */
    public function postAbTestCreate(): string
    {
        try {
            $post = $this->request->getBodyParams();
            
            $testId = $post['testId'] ?? '';
            $name = $post['name'] ?? '';
            $websiteId = (int)($post['websiteId'] ?? \Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            
            if (empty($testId)) {
                return $this->error(__('测试ID不能为空'), '', 400);
            }
            
            if (empty($name)) {
                return $this->error(__('测试名称不能为空'), '', 400);
            }
            
            // 检查测试ID是否已存在
            $existing = AbTest::getByTestId($testId);
            if ($existing) {
                return $this->error(__('测试ID已存在'), '', 400);
            }
            
            /** @var AbTest $abTest */
            $abTest = ObjectManager::getInstance(AbTest::class);
            $abTest->setTestId($testId)
                ->setWebsiteId($websiteId)
                ->setName($name)
                ->setDescription($post['description'] ?? '')
                ->setStatus($post['status'] ?? AbTest::status_DRAFT)
                ->setStartDate($post['startDate'] ?? null)
                ->setEndDate($post['endDate'] ?? null)
                ->setData(AbTest::schema_fields_VARIANT_A, json_encode($post['variantA'] ?? []))
                ->setData(AbTest::schema_fields_VARIANT_B, json_encode($post['variantB'] ?? []))
                ->setData(AbTest::schema_fields_TRAFFIC_SPLIT, $post['trafficSplit'] ?? '50:50');
            
            $id = $abTest->save();
            
            if (!$id) {
                return $this->error(__('创建A/B测试失败'), '', 500);
            }
            
            return $this->success(__('创建A/B测试成功'), [
                'test_id' => $testId,
                'id' => $id
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('创建A/B测试失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取A/B测试列表
     * 
     * @return string
     * @Document(summary='获取A/B测试列表', description='获取A/B测试配置列表', tags=['像素', '分析', 'A/B测试'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/analytics/ab-test/list
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - status: active|paused|completed|draft (可选)
     * @example-end
     */
    public function getAbTestList(): string
    {
        try {
            $websiteId = 0;
            $paramWebsiteId = $this->request->getParam('websiteId') ?? $this->request->getGet('websiteId');
            if ($paramWebsiteId !== null && $paramWebsiteId !== '') {
                $websiteId = (int)$paramWebsiteId;
            } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }
            
            $status = $this->request->getParam('status') ?? $this->request->getGet('status');
            
            /** @var AbTest $abTest */
            $abTest = ObjectManager::getInstance(AbTest::class);
            $model = $abTest->reset();
            
            if ($websiteId > 0) {
                $model->where(AbTest::schema_fields_WEBSITE_ID, $websiteId);
            }
            
            if ($status) {
                $model->where(AbTest::schema_fields_STATUS, $status);
            }
            
            $tests = $model->select()->fetchArray();
            
            return $this->success(__('获取A/B测试列表成功'), [
                'tests' => $tests,
                'count' => count($tests)
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取A/B测试列表失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 导出数据（CSV格式）
     * 
     * @return string
     * @Document(summary='导出数据', description='导出像素数据为CSV格式', tags=['像素', '分析', '导出'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/analytics/export
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - startDate: 2025-01-01 (可选)
     * - endDate: 2025-01-31 (可选)
     * - format: csv|json (可选，默认csv)
     * Response: CSV文件或JSON数据
     * @example-end
     */
    public function getExport(): string
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
            
            // 获取时间范围
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            // 获取导出格式
            $format = $this->request->getParam('format') ?? $this->request->getGet('format') ?? 'csv';
            
            // 获取数据
            $model = w_obj(Pixel::class);
            $query = $model->reset();
            
            if ($websiteId > 0) {
                $query->where(Pixel::schema_fields_WEBSITE_ID, $websiteId);
            }
            
            if ($startDate) {
                $query->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=');
            }
            
            if ($endDate) {
                $query->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');
            }
            
            // 限制导出数量，避免内存溢出
            $limit = 10000;
            $data = $query->limit($limit)->select()->fetchArray();
            
            // 如果数据为空，返回提示
            if (empty($data)) {
                if ($format === 'json') {
                    return $this->error(__('没有可导出的数据'), '', 404);
                }
                // CSV格式：输出空文件
            } elseif (count($data) >= $limit) {
                // 如果数据量达到限制，返回警告
                if ($format === 'json') {
                    return $this->error(__('数据量过大，已限制导出前%{1}条记录。请缩小时间范围或使用分页导出', [$limit]), '', 400);
                }
                // CSV格式：继续导出，但文件名中标注限制
            }
            
            if ($format === 'json') {
                return $this->success(__('导出数据成功'), $data);
            }
            
            // CSV格式
            header('Content-Type: text/csv; charset=UTF-8');
            $filename = 'pixel_data_' . date('Y-m-d');
            if (count($data) >= $limit) {
                $filename .= '_limited_' . $limit;
            }
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // 添加BOM以支持Excel中文显示
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // 写入表头
            if (!empty($data)) {
                // 确保表头顺序一致
                $headers = array_keys($data[0]);
                fputcsv($output, $headers);
                
                // 写入数据
                foreach ($data as $row) {
                    // 确保数据顺序与表头一致
                    $orderedRow = [];
                    foreach ($headers as $header) {
                        $orderedRow[] = $row[$header] ?? '';
                    }
                    fputcsv($output, $orderedRow);
                }
            } else {
                // 如果没有数据，至少输出表头（使用Pixel模型的字段）
                $defaultHeaders = [
                    'pixel_id', 'url', 'module', 'name', 'referer', 'source',
                    'user_id', 'user_agent', 'ip', 'event', 'website_id',
                    'lang', 'currency', 'value', 'browser_info', 'cron_deal', 'created_at'
                ];
                fputcsv($output, $defaultHeaders);
            }
            
            fclose($output);
            return '';
            
        } catch (\Exception $e) {
            return $this->error(__('导出数据失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
    
    /**
     * 获取综合统计报告
     * 
     * @return string
     * @Document(summary='获取综合统计报告', description='获取包含多个维度的综合统计报告', tags=['像素', '分析', '报告'], category='像素接口')
     * @example
     * Method: GET
     * Path: /visitor/rest/v1/analytics/report
     * Request Parameters:
     * - websiteId: 1 (可选)
     * - startDate: 2025-01-01 (可选)
     * - endDate: 2025-01-31 (可选)
     * Response:
     * {
     *   "code": 200,
     *   "msg": "获取报告成功",
     *   "data": {
     *     "summary": {...},
     *     "daily_stats": [...],
     *     "event_stats": {...},
     *     "top_events": [...]
     *   }
     * }
     * @example-end
     */
    public function getReport(): string
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
            
            // 获取时间范围
            $startDate = $this->request->getParam('startDate') ?? $this->request->getGet('startDate');
            $endDate = $this->request->getParam('endDate') ?? $this->request->getGet('endDate');
            
            // 如果没有指定时间范围，默认最近30天
            if (!$startDate || !$endDate) {
                $endDate = date('Y-m-d 23:59:59');
                $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
            }
            
            // 获取统计摘要
            $summary = Pixel::getWebsiteSummary($websiteId);
            
            // 获取每日统计
            $dailyStats = Pixel::getBusinessValueByPeriod($websiteId, 'daily', $startDate, $endDate);
            
            // 获取事件统计（在时间范围内）
            // 先获取时间范围内的事件列表
            $model = w_obj(Pixel::class)->reset()
                ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                ->field(Pixel::schema_fields_EVENT)
                ->group(Pixel::schema_fields_EVENT);
            
            if ($startDate) {
                $model->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=');
            }
            if ($endDate) {
                $model->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');
            }
            
            $eventResult = $model->select()->fetchArray();
            $eventList = array_column($eventResult, Pixel::schema_fields_EVENT);
            
            $eventStats = [];
            foreach ($eventList as $event) {
                // 使用时间范围统计
                $eventModel = w_obj(Pixel::class)->reset()
                    ->where(Pixel::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(Pixel::schema_fields_EVENT, $event);
                
                if ($startDate) {
                    $eventModel->where(Pixel::schema_fields_CREATED_AT, $startDate, '>=');
                }
                if ($endDate) {
                    $eventModel->where(Pixel::schema_fields_CREATED_AT, $endDate, '<=');
                }
                
                $eventStats[$event] = (int)$eventModel->count();
            }
            
            // 获取热门事件（按事件数排序）
            arsort($eventStats);
            $topEvents = array_slice($eventStats, 0, 10, true);
            
            // 获取时间范围统计
            $timeRangeStats = Pixel::getWebsiteStatsByDateRange($websiteId, $startDate, $endDate);
            
            return $this->success(__('获取报告成功'), [
                'summary' => $summary,
                'daily_stats' => $dailyStats,
                'event_stats' => $eventStats,
                'top_events' => $topEvents,
                'time_range_stats' => $timeRangeStats,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取报告失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
}

