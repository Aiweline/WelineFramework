<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/11
 */

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiUsageLog;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiModelMonitoring;
use Weline\Framework\Manager\ObjectManager;

/**
 * 商业洞察报表服务
 * 
 * 功能：
 * - 多时间维度报表（日/周/月/季/年）
 * - 用户活跃度分析
 * - 模型使用情况统计
 * - 收入分析
 * - 性能指标监控
 * - 业务洞察和趋势预测
 */
class BusinessInsightService
{
    /**
     * @var AiUsageLog
     */
    private AiUsageLog $usageLog;

    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var AiModelMonitoring
     */
    private AiModelMonitoring $monitoring;

    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * 构造函数
     * 
     * @param AiUsageLog $usageLog
     * @param AiModel $aiModel
     * @param AiModelMonitoring $monitoring
     * @param CacheService $cacheService
     */
    public function __construct(
        AiUsageLog $usageLog,
        AiModel $aiModel,
        AiModelMonitoring $monitoring,
        CacheService $cacheService
    ) {
        $this->usageLog = $usageLog;
        $this->aiModel = $aiModel;
        $this->monitoring = $monitoring;
        $this->cacheService = $cacheService;
    }

    /**
     * 获取总体统计数据
     * 
     * @param int $startDate
     * @param int $endDate
     * @param int|null $tenantId
     * @return array
     */
    public function getOverallStats(int $startDate, int $endDate, ?int $tenantId = null): array
    {
        $cacheKey = "insights_overall_{$startDate}_{$endDate}_" . ($tenantId ?? 'all');
        
        return $this->cacheService->remember($cacheKey, 300, function() use ($startDate, $endDate, $tenantId) {
            $usageLogs = $this->usageLog->where('created_time', '>=', (string)$startDate)
                ->where('created_time', '<=', (string)$endDate);

            if ($tenantId) {
                $usageLogs->where('tenant_id', $tenantId);
            }

            $logs = $usageLogs->select()->fetchArray();

            $totalRequests = 0;
            $totalTokens = 0;
            $totalCost = 0.0;
            $uniqueUsers = [];
            $successCount = 0;

            foreach ($logs as $log) {
                $totalRequests++;
                $totalTokens += (int)($log['total_tokens'] ?? 0);
                $totalCost += (float)($log['total_cost'] ?? 0);
                
                if (!empty($log['user_id'])) {
                    $uniqueUsers[$log['user_id']] = true;
                }
                
                if (($log['status'] ?? '') === 'success') {
                    $successCount++;
                }
            }

            $successRate = $totalRequests > 0 ? ($successCount / $totalRequests) * 100 : 0;

            return [
                'total_requests' => $totalRequests,
                'total_tokens' => $totalTokens,
                'total_cost' => $totalCost,
                'unique_users' => count($uniqueUsers),
                'success_rate' => round($successRate, 2),
                'avg_tokens_per_request' => $totalRequests > 0 ? round($totalTokens / $totalRequests, 2) : 0,
                'avg_cost_per_request' => $totalRequests > 0 ? round($totalCost / $totalRequests, 4) : 0,
            ];
        });
    }

    /**
     * 获取模型使用统计
     * 
     * @param int $startDate
     * @param int $endDate
     * @param int|null $tenantId
     * @return array
     */
    public function getModelStats(int $startDate, int $endDate, ?int $tenantId = null): array
    {
        $cacheKey = "insights_model_stats_{$startDate}_{$endDate}_" . ($tenantId ?? 'all');
        
        return $this->cacheService->remember($cacheKey, 300, function() use ($startDate, $endDate, $tenantId) {
            $usageLogs = $this->usageLog->where('created_time', '>=', (string)$startDate)
                ->where('created_time', '<=', (string)$endDate);

            if ($tenantId) {
                $usageLogs->where('tenant_id', $tenantId);
            }

            $logs = $usageLogs->select()->fetchArray();
            $modelStats = [];

            foreach ($logs as $log) {
                $modelCode = $log['model_code'] ?? 'unknown';
                
                if (!isset($modelStats[$modelCode])) {
                    $modelStats[$modelCode] = [
                        'model_code' => $modelCode,
                        'request_count' => 0,
                        'total_tokens' => 0,
                        'total_cost' => 0.0,
                        'success_count' => 0,
                        'error_count' => 0,
                    ];
                }

                $modelStats[$modelCode]['request_count']++;
                $modelStats[$modelCode]['total_tokens'] += (int)($log['total_tokens'] ?? 0);
                $modelStats[$modelCode]['total_cost'] += (float)($log['total_cost'] ?? 0);
                
                if (($log['status'] ?? '') === 'success') {
                    $modelStats[$modelCode]['success_count']++;
                } else {
                    $modelStats[$modelCode]['error_count']++;
                }
            }

            // 计算每个模型的成功率
            foreach ($modelStats as &$stat) {
                $stat['success_rate'] = $stat['request_count'] > 0 
                    ? round(($stat['success_count'] / $stat['request_count']) * 100, 2) 
                    : 0;
            }

            // 按请求数排序
            usort($modelStats, function($a, $b) {
                return $b['request_count'] - $a['request_count'];
            });

            return array_values($modelStats);
        });
    }

    /**
     * 获取每日趋势
     * 
     * @param int $startDate
     * @param int $endDate
     * @param int|null $tenantId
     * @return array
     */
    public function getDailyTrend(int $startDate, int $endDate, ?int $tenantId = null): array
    {
        $cacheKey = "insights_daily_trend_{$startDate}_{$endDate}_" . ($tenantId ?? 'all');
        
        return $this->cacheService->remember($cacheKey, 300, function() use ($startDate, $endDate, $tenantId) {
            $usageLogs = $this->usageLog->where('created_time', '>=', (string)$startDate)
                ->where('created_time', '<=', (string)$endDate);

            if ($tenantId) {
                $usageLogs->where('tenant_id', $tenantId);
            }

            $logs = $usageLogs->select()->fetchArray();
            $dailyData = [];

            foreach ($logs as $log) {
                $date = date('Y-m-d', (int)($log['created_time'] ?? 0));
                
                if (!isset($dailyData[$date])) {
                    $dailyData[$date] = [
                        'date' => $date,
                        'request_count' => 0,
                        'total_tokens' => 0,
                        'total_cost' => 0.0,
                        'unique_users' => [],
                    ];
                }

                $dailyData[$date]['request_count']++;
                $dailyData[$date]['total_tokens'] += (int)($log['total_tokens'] ?? 0);
                $dailyData[$date]['total_cost'] += (float)($log['total_cost'] ?? 0);
                
                if (!empty($log['user_id'])) {
                    $dailyData[$date]['unique_users'][$log['user_id']] = true;
                }
            }

            // 转换 unique_users 为计数
            foreach ($dailyData as &$data) {
                $data['unique_users'] = count($data['unique_users']);
            }

            // 按日期排序
            ksort($dailyData);

            return array_values($dailyData);
        });
    }

    /**
     * 获取热门场景
     * 
     * @param int $startDate
     * @param int $endDate
     * @param int|null $tenantId
     * @param int $limit
     * @return array
     */
    public function getTopScenarios(int $startDate, int $endDate, ?int $tenantId = null, int $limit = 10): array
    {
        $cacheKey = "insights_top_scenarios_{$startDate}_{$endDate}_" . ($tenantId ?? 'all') . "_{$limit}";
        
        return $this->cacheService->remember($cacheKey, 300, function() use ($startDate, $endDate, $tenantId, $limit) {
            $usageLogs = $this->usageLog->where('created_time', '>=', (string)$startDate)
                ->where('created_time', '<=', (string)$endDate);

            if ($tenantId) {
                $usageLogs->where('tenant_id', $tenantId);
            }

            $logs = $usageLogs->select()->fetchArray();
            $scenarioStats = [];

            foreach ($logs as $log) {
                $scenarioCode = $log['scenario_code'] ?? 'default';
                
                if (!isset($scenarioStats[$scenarioCode])) {
                    $scenarioStats[$scenarioCode] = [
                        'scenario_code' => $scenarioCode,
                        'request_count' => 0,
                        'total_tokens' => 0,
                        'total_cost' => 0.0,
                    ];
                }

                $scenarioStats[$scenarioCode]['request_count']++;
                $scenarioStats[$scenarioCode]['total_tokens'] += (int)($log['total_tokens'] ?? 0);
                $scenarioStats[$scenarioCode]['total_cost'] += (float)($log['total_cost'] ?? 0);
            }

            // 按请求数排序
            usort($scenarioStats, function($a, $b) {
                return $b['request_count'] - $a['request_count'];
            });

            return array_slice(array_values($scenarioStats), 0, $limit);
        });
    }

    /**
     * 获取用户活跃度分析
     * 
     * @param int $startDate
     * @param int $endDate
     * @param int|null $tenantId
     * @return array
     */
    public function getUserActivityAnalysis(int $startDate, int $endDate, ?int $tenantId = null): array
    {
        $cacheKey = "insights_user_activity_{$startDate}_{$endDate}_" . ($tenantId ?? 'all');
        
        return $this->cacheService->remember($cacheKey, 300, function() use ($startDate, $endDate, $tenantId) {
            $usageLogs = $this->usageLog->where('created_time', '>=', (string)$startDate)
                ->where('created_time', '<=', (string)$endDate);

            if ($tenantId) {
                $usageLogs->where('tenant_id', $tenantId);
            }

            $logs = $usageLogs->select()->fetchArray();
            $userActivity = [];

            foreach ($logs as $log) {
                $userId = $log['user_id'] ?? 'anonymous';
                
                if (!isset($userActivity[$userId])) {
                    $userActivity[$userId] = [
                        'user_id' => $userId,
                        'request_count' => 0,
                        'total_tokens' => 0,
                        'total_cost' => 0.0,
                        'last_activity' => 0,
                    ];
                }

                $userActivity[$userId]['request_count']++;
                $userActivity[$userId]['total_tokens'] += (int)($log['total_tokens'] ?? 0);
                $userActivity[$userId]['total_cost'] += (float)($log['total_cost'] ?? 0);
                
                $createdTime = (int)($log['created_time'] ?? 0);
                if ($createdTime > $userActivity[$userId]['last_activity']) {
                    $userActivity[$userId]['last_activity'] = $createdTime;
                }
            }

            // 按请求数排序
            usort($userActivity, function($a, $b) {
                return $b['request_count'] - $a['request_count'];
            });

            return [
                'total_users' => count($userActivity),
                'top_users' => array_slice(array_values($userActivity), 0, 20),
                'avg_requests_per_user' => count($userActivity) > 0 
                    ? round(array_sum(array_column($userActivity, 'request_count')) / count($userActivity), 2) 
                    : 0,
            ];
        });
    }

    /**
     * 获取收入分析
     * 
     * @param int $startDate
     * @param int $endDate
     * @param int|null $tenantId
     * @return array
     */
    public function getRevenueAnalysis(int $startDate, int $endDate, ?int $tenantId = null): array
    {
        $cacheKey = "insights_revenue_{$startDate}_{$endDate}_" . ($tenantId ?? 'all');
        
        return $this->cacheService->remember($cacheKey, 300, function() use ($startDate, $endDate, $tenantId) {
            $usageLogs = $this->usageLog->where('created_time', '>=', (string)$startDate)
                ->where('created_time', '<=', (string)$endDate);

            if ($tenantId) {
                $usageLogs->where('tenant_id', $tenantId);
            }

            $logs = $usageLogs->select()->fetchArray();
            
            $totalRevenue = 0.0;
            $revenueByModel = [];
            $revenueByDay = [];

            foreach ($logs as $log) {
                $cost = (float)($log['total_cost'] ?? 0);
                $totalRevenue += $cost;

                // 按模型统计
                $modelCode = $log['model_code'] ?? 'unknown';
                if (!isset($revenueByModel[$modelCode])) {
                    $revenueByModel[$modelCode] = 0.0;
                }
                $revenueByModel[$modelCode] += $cost;

                // 按日期统计
                $date = date('Y-m-d', (int)($log['created_time'] ?? 0));
                if (!isset($revenueByDay[$date])) {
                    $revenueByDay[$date] = 0.0;
                }
                $revenueByDay[$date] += $cost;
            }

            // 排序
            arsort($revenueByModel);
            ksort($revenueByDay);

            return [
                'total_revenue' => round($totalRevenue, 2),
                'avg_revenue_per_day' => count($revenueByDay) > 0 
                    ? round($totalRevenue / count($revenueByDay), 2) 
                    : 0,
                'revenue_by_model' => $revenueByModel,
                'revenue_by_day' => $revenueByDay,
            ];
        });
    }

    /**
     * 获取性能指标
     * 
     * @param int $startDate
     * @param int $endDate
     * @param int|null $tenantId
     * @return array
     */
    public function getPerformanceMetrics(int $startDate, int $endDate, ?int $tenantId = null): array
    {
        $cacheKey = "insights_performance_{$startDate}_{$endDate}_" . ($tenantId ?? 'all');
        
        return $this->cacheService->remember($cacheKey, 300, function() use ($startDate, $endDate, $tenantId) {
            $usageLogs = $this->usageLog->where('created_time', '>=', (string)$startDate)
                ->where('created_time', '<=', (string)$endDate);

            if ($tenantId) {
                $usageLogs->where('tenant_id', $tenantId);
            }

            $logs = $usageLogs->select()->fetchArray();
            
            $responseTimes = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($logs as $log) {
                $responseTime = (float)($log['response_time'] ?? 0);
                if ($responseTime > 0) {
                    $responseTimes[] = $responseTime;
                }

                if (($log['status'] ?? '') === 'success') {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }

            sort($responseTimes);
            $count = count($responseTimes);

            return [
                'avg_response_time' => $count > 0 ? round(array_sum($responseTimes) / $count, 3) : 0,
                'p50_response_time' => $count > 0 ? $responseTimes[(int)($count * 0.5)] : 0,
                'p95_response_time' => $count > 0 ? $responseTimes[(int)($count * 0.95)] : 0,
                'p99_response_time' => $count > 0 ? $responseTimes[(int)($count * 0.99)] : 0,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'success_rate' => ($successCount + $errorCount) > 0 
                    ? round(($successCount / ($successCount + $errorCount)) * 100, 2) 
                    : 0,
            ];
        });
    }

    /**
     * 清除缓存
     * 
     * @return void
     */
    public function clearCache(): void
    {
        $this->cacheService->clear('insights_*');
    }
}

