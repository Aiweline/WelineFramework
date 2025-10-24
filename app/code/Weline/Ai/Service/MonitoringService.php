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

use Weline\Ai\Model\AiModelMonitoring;
use Weline\Ai\Model\AiModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Event\EventsManager;

/**
 * 监控和告警服务
 * 
 * 功能：
 * - 性能监控
 * - 安全监控
 * - 异常检测
 * - 告警管理
 * - 日志分析
 * - 系统诊断
 * - 默认通知方式：短信、钉钉、飞书
 */
class MonitoringService
{
    /**
     * @var AiModelMonitoring
     */
    private AiModelMonitoring $monitoring;

    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var EventsManager
     */
    private EventsManager $eventsManager;

    /**
     * 告警阈值配置
     */
    private const ALERT_THRESHOLDS = [
        'error_rate' => 5.0,          // 错误率超过5%触发告警
        'response_time_p95' => 3000,  // P95响应时间超过3秒触发告警
        'response_time_p99' => 5000,  // P99响应时间超过5秒触发告警
        'success_rate' => 95.0,        // 成功率低于95%触发告警
        'cost_per_request' => 1.0,     // 单次请求成本超过1元触发告警
    ];

    /**
     * 告警级别
     */
    private const ALERT_LEVEL_INFO = 'info';
    private const ALERT_LEVEL_WARNING = 'warning';
    private const ALERT_LEVEL_ERROR = 'error';
    private const ALERT_LEVEL_CRITICAL = 'critical';

    /**
     * 构造函数
     * 
     * @param AiModelMonitoring $monitoring
     * @param AiModel $aiModel
     * @param CacheService $cacheService
     */
    public function __construct(
        AiModelMonitoring $monitoring,
        AiModel $aiModel,
        CacheService $cacheService
    ) {
        $this->monitoring = $monitoring;
        $this->aiModel = $aiModel;
        $this->cacheService = $cacheService;
        $this->eventsManager = ObjectManager::getInstance(EventsManager::class);
    }

    /**
     * 记录请求监控数据
     * 
     * @param string $modelCode
     * @param bool $success
     * @param float $responseTime 响应时间（毫秒）
     * @param float $cost 请求成本
     * @param int|null $tenantId
     * @return void
     */
    public function recordRequest(
        string $modelCode, 
        bool $success, 
        float $responseTime, 
        float $cost,
        ?int $tenantId = null
    ): void {
        try {
            // 获取今天的监控记录
            $today = date('Y-m-d');
            $monitoring = $this->getOrCreateTodayMonitoring($modelCode, $tenantId);

            // 更新监控数据
            $monitoring->incrementRequest($success, $responseTime, $cost);
            $monitoring->setData('monitoring_date', $today);
            $monitoring->save();

            // 检查是否需要触发告警
            $this->checkAlerts($monitoring);

            // 清除相关缓存
            $this->clearMonitoringCache($modelCode, $tenantId);

        } catch (\Exception $e) {
            // 记录错误但不影响主流程
            error_log("Monitoring record failed: " . $e->getMessage());
        }
    }

    /**
     * 获取或创建今天的监控记录
     * 
     * @param string $modelCode
     * @param int|null $tenantId
     * @return AiModelMonitoring
     */
    private function getOrCreateTodayMonitoring(string $modelCode, ?int $tenantId = null): AiModelMonitoring
    {
        $today = date('Y-m-d');
        
        /** @var AiModelMonitoring $monitoring */
        $monitoring = ObjectManager::getInstance(AiModelMonitoring::class);
        
        $query = $monitoring->where('model_code', $modelCode)
            ->where('monitoring_date', $today);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $existing = $query->select()->fetch();

        if ($existing->getId()) {
            return $existing;
        }

        // 创建新记录
        $monitoring->setData([
            'model_code' => $modelCode,
            'monitoring_date' => $today,
            'tenant_id' => $tenantId,
            'request_count' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'total_cost' => 0.0,
            'avg_response_time' => 0.0,
            'created_time' => time(),
            'updated_time' => time(),
        ]);

        return $monitoring;
    }

    /**
     * 检查告警条件
     * 
     * @param AiModelMonitoring $monitoring
     * @return void
     */
    private function checkAlerts(AiModelMonitoring $monitoring): void
    {
        $alerts = [];

        // 检查错误率
        $errorRate = $monitoring->getErrorRate();
        if ($errorRate > self::ALERT_THRESHOLDS['error_rate']) {
            $alerts[] = [
                'level' => self::ALERT_LEVEL_ERROR,
                'type' => 'error_rate',
                'message' => "模型 {$monitoring->getData('model_code')} 错误率过高: {$errorRate}%",
                'value' => $errorRate,
                'threshold' => self::ALERT_THRESHOLDS['error_rate'],
            ];
        }

        // 检查成功率
        $successRate = $monitoring->getSuccessRate();
        if ($successRate < self::ALERT_THRESHOLDS['success_rate']) {
            $alerts[] = [
                'level' => self::ALERT_LEVEL_WARNING,
                'type' => 'success_rate',
                'message' => "模型 {$monitoring->getData('model_code')} 成功率过低: {$successRate}%",
                'value' => $successRate,
                'threshold' => self::ALERT_THRESHOLDS['success_rate'],
            ];
        }

        // 检查平均响应时间
        $avgResponseTime = $monitoring->getData('avg_response_time');
        if ($avgResponseTime > self::ALERT_THRESHOLDS['response_time_p95']) {
            $alerts[] = [
                'level' => self::ALERT_LEVEL_WARNING,
                'type' => 'response_time',
                'message' => "模型 {$monitoring->getData('model_code')} 平均响应时间过长: {$avgResponseTime}ms",
                'value' => $avgResponseTime,
                'threshold' => self::ALERT_THRESHOLDS['response_time_p95'],
            ];
        }

        // 检查平均成本
        $requestCount = $monitoring->getData('request_count');
        if ($requestCount > 0) {
            $avgCost = $monitoring->getData('total_cost') / $requestCount;
            if ($avgCost > self::ALERT_THRESHOLDS['cost_per_request']) {
                $alerts[] = [
                    'level' => self::ALERT_LEVEL_INFO,
                    'type' => 'cost',
                    'message' => "模型 {$monitoring->getData('model_code')} 平均请求成本过高: ¥{$avgCost}",
                    'value' => $avgCost,
                    'threshold' => self::ALERT_THRESHOLDS['cost_per_request'],
                ];
            }
        }

        // 触发告警事件
        foreach ($alerts as $alert) {
            $this->triggerAlert($alert, $monitoring);
        }
    }

    /**
     * 触发告警
     * 
     * @param array $alert
     * @param AiModelMonitoring $monitoring
     * @return void
     */
    private function triggerAlert(array $alert, AiModelMonitoring $monitoring): void
    {
        // 触发事件，通知订阅者（短信、钉钉、飞书）
        $eventData = [
            'alert' => $alert,
            'monitoring' => $monitoring,
            'model_code' => $monitoring->getData('model_code'),
            'tenant_id' => $monitoring->getData('tenant_id'),
        ];
        $this->eventsManager->dispatch('ai_monitoring_alert', $eventData);

        // 记录告警日志
        error_log(sprintf(
            "[AI Monitoring Alert] Level: %s, Type: %s, Message: %s",
            $alert['level'],
            $alert['type'],
            $alert['message']
        ));
    }

    /**
     * 获取模型监控数据
     * 
     * @param string $modelCode
     * @param int $days 最近多少天
     * @param int|null $tenantId
     * @return array
     */
    public function getModelMonitoring(string $modelCode, int $days = 7, ?int $tenantId = null): array
    {
        $cacheKey = "monitoring_{$modelCode}_{$days}_" . ($tenantId ?? 'all');
        
        return $this->cacheService->remember($cacheKey, 300, function() use ($modelCode, $days, $tenantId) {
            $startDate = date('Y-m-d', strtotime("-{$days} days"));
            
            $query = $this->monitoring->where('model_code', $modelCode)
                ->where('monitoring_date', '>=', $startDate);

            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }

            $records = $query->select()->fetchOrigin();

            $totalRequests = 0;
            $totalSuccess = 0;
            $totalErrors = 0;
            $totalCost = 0.0;
            $responseTimes = [];
            $dailyData = [];

            foreach ($records as $record) {
                $totalRequests += (int)($record['request_count'] ?? 0);
                $totalSuccess += (int)($record['success_count'] ?? 0);
                $totalErrors += (int)($record['error_count'] ?? 0);
                $totalCost += (float)($record['total_cost'] ?? 0);
                
                $avgResponseTime = (float)($record['avg_response_time'] ?? 0);
                if ($avgResponseTime > 0) {
                    $responseTimes[] = $avgResponseTime;
                }

                $dailyData[] = [
                    'date' => $record['monitoring_date'],
                    'request_count' => (int)($record['request_count'] ?? 0),
                    'success_rate' => (int)($record['request_count'] ?? 0) > 0
                        ? round(((int)($record['success_count'] ?? 0) / (int)($record['request_count'] ?? 0)) * 100, 2)
                        : 0,
                    'avg_response_time' => $avgResponseTime,
                    'total_cost' => (float)($record['total_cost'] ?? 0),
                ];
            }

            $successRate = $totalRequests > 0 ? ($totalSuccess / $totalRequests) * 100 : 0;
            $errorRate = $totalRequests > 0 ? ($totalErrors / $totalRequests) * 100 : 0;

            return [
                'model_code' => $modelCode,
                'period' => "{$days} days",
                'summary' => [
                    'total_requests' => $totalRequests,
                    'success_rate' => round($successRate, 2),
                    'error_rate' => round($errorRate, 2),
                    'total_cost' => round($totalCost, 2),
                    'avg_response_time' => count($responseTimes) > 0 
                        ? round(array_sum($responseTimes) / count($responseTimes), 2) 
                        : 0,
                ],
                'daily_data' => $dailyData,
                'health_status' => $this->calculateHealthStatus($successRate, $errorRate, $responseTimes),
            ];
        });
    }

    /**
     * 计算健康状态
     * 
     * @param float $successRate
     * @param float $errorRate
     * @param array $responseTimes
     * @return string
     */
    private function calculateHealthStatus(float $successRate, float $errorRate, array $responseTimes): string
    {
        if ($errorRate > 10 || $successRate < 90) {
            return 'critical'; // 严重
        }

        if ($errorRate > 5 || $successRate < 95) {
            return 'warning'; // 警告
        }

        $avgResponseTime = count($responseTimes) > 0 
            ? array_sum($responseTimes) / count($responseTimes) 
            : 0;

        if ($avgResponseTime > 5000) {
            return 'warning'; // 响应时间过长
        }

        return 'healthy'; // 健康
    }

    /**
     * 获取所有模型的监控概览
     * 
     * @param int $days
     * @param int|null $tenantId
     * @return array
     */
    public function getOverviewMonitoring(int $days = 7, ?int $tenantId = null): array
    {
        $cacheKey = "monitoring_overview_{$days}_" . ($tenantId ?? 'all');
        
        return $this->cacheService->remember($cacheKey, 300, function() use ($days, $tenantId) {
            $startDate = date('Y-m-d', strtotime("-{$days} days"));
            
            $query = $this->monitoring->where('monitoring_date', '>=', $startDate);

            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }

            $records = $query->select()->fetchOrigin();
            $modelSummary = [];

            foreach ($records as $record) {
                $modelCode = $record['model_code'] ?? 'unknown';
                
                if (!isset($modelSummary[$modelCode])) {
                    $modelSummary[$modelCode] = [
                        'model_code' => $modelCode,
                        'total_requests' => 0,
                        'total_success' => 0,
                        'total_errors' => 0,
                        'total_cost' => 0.0,
                        'response_times' => [],
                    ];
                }

                $modelSummary[$modelCode]['total_requests'] += (int)($record['request_count'] ?? 0);
                $modelSummary[$modelCode]['total_success'] += (int)($record['success_count'] ?? 0);
                $modelSummary[$modelCode]['total_errors'] += (int)($record['error_count'] ?? 0);
                $modelSummary[$modelCode]['total_cost'] += (float)($record['total_cost'] ?? 0);
                
                $avgResponseTime = (float)($record['avg_response_time'] ?? 0);
                if ($avgResponseTime > 0) {
                    $modelSummary[$modelCode]['response_times'][] = $avgResponseTime;
                }
            }

            // 计算每个模型的指标
            foreach ($modelSummary as $modelCode => &$summary) {
                $summary['success_rate'] = $summary['total_requests'] > 0
                    ? round(($summary['total_success'] / $summary['total_requests']) * 100, 2)
                    : 0;
                
                $summary['error_rate'] = $summary['total_requests'] > 0
                    ? round(($summary['total_errors'] / $summary['total_requests']) * 100, 2)
                    : 0;

                $summary['avg_response_time'] = count($summary['response_times']) > 0
                    ? round(array_sum($summary['response_times']) / count($summary['response_times']), 2)
                    : 0;

                $summary['health_status'] = $this->calculateHealthStatus(
                    $summary['success_rate'],
                    $summary['error_rate'],
                    $summary['response_times']
                );

                unset($summary['response_times']);
            }

            // 按请求数排序
            usort($modelSummary, function($a, $b) {
                return $b['total_requests'] - $a['total_requests'];
            });

            return array_values($modelSummary);
        });
    }

    /**
     * 清除监控缓存
     * 
     * @param string|null $modelCode
     * @param int|null $tenantId
     * @return void
     */
    private function clearMonitoringCache(?string $modelCode = null, ?int $tenantId = null): void
    {
        if ($modelCode) {
            $this->cacheService->clear("monitoring_{$modelCode}_*");
        } else {
            $this->cacheService->clear('monitoring_*');
        }
    }

    /**
     * 获取系统健康检查
     * 
     * @return array
     */
    public function getSystemHealth(): array
    {
        $overview = $this->getOverviewMonitoring(1); // 最近1天
        
        $healthyCount = 0;
        $warningCount = 0;
        $criticalCount = 0;

        foreach ($overview as $model) {
            switch ($model['health_status']) {
                case 'healthy':
                    $healthyCount++;
                    break;
                case 'warning':
                    $warningCount++;
                    break;
                case 'critical':
                    $criticalCount++;
                    break;
            }
        }

        $overallStatus = 'healthy';
        if ($criticalCount > 0) {
            $overallStatus = 'critical';
        } elseif ($warningCount > 0) {
            $overallStatus = 'warning';
        }

        return [
            'overall_status' => $overallStatus,
            'total_models' => count($overview),
            'healthy_models' => $healthyCount,
            'warning_models' => $warningCount,
            'critical_models' => $criticalCount,
            'models' => $overview,
        ];
    }
}

