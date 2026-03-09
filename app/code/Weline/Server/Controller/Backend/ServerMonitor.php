<?php
declare(strict_types=1);

/**
 * Weline Server - 服务器监控控制器
 * 
 * 提供服务器监控首页、状态查看、攻击日志等功能
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Security\AttackDetector;
use Weline\Server\Model\AttackLog;
use Weline\Server\Model\ServerStatusLog;
use Weline\Server\Service\Benchmark\ServerBenchmarkService;
use Weline\Server\Service\Control\BackendStatusService;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Telemetry\MetricsFlushScheduler;
use Weline\Server\Service\OptimizationGuideService;

/**
 * ServerMonitor - 服务器监控
 * 
 * 监控 Weline Server 运行状态、攻击日志等
 */
class ServerMonitor extends BackendController
{
    /**
     * 服务器状态日志模型
     */
    private ServerStatusLog $statusLog;
    
    /**
     * 攻击日志模型
     */
    private AttackLog $attackLog;
    
    /**
     * 优化指南服务
     */
    private OptimizationGuideService $guideService;
    private BackendStatusService $backendStatusService;
    private IpcControlGateway $ipcGateway;
    private ServerBenchmarkService $benchmarkService;
    private MetricsFlushScheduler $metricsFlushScheduler;
    
    /**
     * 构造函数
     */
    public function __construct(
        ServerStatusLog $statusLog,
        AttackLog $attackLog,
        OptimizationGuideService $guideService,
        BackendStatusService $backendStatusService,
        IpcControlGateway $ipcGateway,
        ServerBenchmarkService $benchmarkService,
        MetricsFlushScheduler $metricsFlushScheduler
    ) {
        $this->statusLog = $statusLog;
        $this->attackLog = $attackLog;
        $this->guideService = $guideService;
        $this->backendStatusService = $backendStatusService;
        $this->ipcGateway = $ipcGateway;
        $this->benchmarkService = $benchmarkService;
        $this->metricsFlushScheduler = $metricsFlushScheduler;
    }
    
    /**
     * 监控首页
     */
    public function getIndex(): string
    {
        $instance = $this->request->getGet('instance', 'default');
        
        // 获取服务器实时状态
        $serverStatus = $this->guideService->getServerStatus();
        
        // 获取攻击统计
        $attackStats = $this->attackLog->getStatistics($instance, 7);
        
        // 获取最新状态日志
        $statusLogs = $this->statusLog->getLatestStatus($instance);
        
        // 获取服务器统计
        $serverStats = $this->statusLog->getStatistics($instance);
        
        $this->assign('instance', $instance);
        $this->assign('serverStatus', $serverStatus);
        $this->assign('attackStats', $attackStats);
        $this->assign('statusLogs', $statusLogs);
        $this->assign('serverStats', $serverStats);
        $this->assign('title', __('服务器监控'));
        
        // Master API 文档
        $this->assign('masterApiDocs', $this->getMasterApiDocs());
        $this->assign('benchmarkList', $this->benchmarkService->list(1, 10));
        
        return $this->fetch('index');
    }
    
    /**
     * API: 获取实时状态
     */
    public function getStatus(): array
    {
        $instance = $this->request->getGet('instance', 'default');
        $statusDto = $this->backendStatusService->getStatusDto((string)$instance, true);
        $traffic = $this->ipcGateway->command(
            (string)$instance,
            ControlMessage::ACTION_TELEMETRY_QUERY,
            '',
            ['instance' => (string)$instance, 'window_sec' => 300],
            4.0
        );
        
        return [
            'success' => true,
            'data' => [
                'server_status' => $this->guideService->getServerStatus(),
                'status_logs' => $this->statusLog->getLatestStatus($instance),
                'server_stats' => $this->statusLog->getStatistics($instance),
                'status_dto' => $statusDto['data'] ?? [],
                'traffic' => $traffic['data'] ?? [],
                'timestamp' => \time(),
            ],
        ];
    }

    public function postBenchmark(): array
    {
        $instance = (string)$this->request->getPost('instance', 'default');
        $target = (string)$this->request->getPost('target', '');
        $concurrency = (int)$this->request->getPost('concurrency', 100);
        $requests = (int)$this->request->getPost('requests', 5000);
        if ($target === '') {
            return [
                'success' => false,
                'message' => __('请先输入压测目标 URL，例如 http://127.0.0.1:9981/_wls/health。'),
            ];
        }
        $result = $this->benchmarkService->runAndStore($instance, $target, $concurrency, $requests);
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (bool)($result['success'] ?? false) ? __('压测完成') : (string)($result['message'] ?? __('压测失败')),
            'data' => $result,
        ];
    }

    public function getBenchmarks(): array
    {
        $page = (int)$this->request->getGet('page', 1);
        $limit = (int)$this->request->getGet('limit', 20);
        return [
            'success' => true,
            'data' => $this->benchmarkService->list($page, $limit),
        ];
    }

    public function getBenchmarkDetail(): array
    {
        $id = (int)$this->request->getGet('id', 0);
        if ($id <= 0) {
            return ['success' => false, 'message' => __('请传入有效的压测记录 ID。')];
        }
        $detail = $this->benchmarkService->detail($id);
        if ($detail === null) {
            return ['success' => false, 'message' => __('未找到对应的压测记录。')];
        }
        return ['success' => true, 'data' => $detail];
    }

    public function getTraffic(): array
    {
        $instance = (string)$this->request->getGet('instance', 'default');
        $window = (int)$this->request->getGet('window_sec', 300);
        $host = (string)$this->request->getGet('host', '');
        $result = $this->ipcGateway->command(
            $instance,
            ControlMessage::ACTION_TELEMETRY_QUERY,
            '',
            ['instance' => $instance, 'window_sec' => $window, 'host' => $host],
            4.0
        );
        return [
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? ''),
            'data' => $result['data'] ?? [],
        ];
    }

    public function getTrafficHistory(): array
    {
        $instance = (string)$this->request->getGet('instance', 'default');
        $host = (string)$this->request->getGet('host', '');
        $fromTs = (int)$this->request->getGet('from_ts', \time() - 3600);
        $toTs = (int)$this->request->getGet('to_ts', \time());
        return [
            'success' => true,
            'data' => $this->metricsFlushScheduler->queryHistory($instance, $fromTs, $toTs, $host ?: null),
        ];
    }
    
    /**
     * API: 获取攻击日志
     */
    public function getAttacks(): array
    {
        $instance = $this->request->getGet('instance', '');
        $limit = (int) $this->request->getGet('limit', 50);
        $page = (int) $this->request->getGet('page', 1);
        
        $attacks = $this->attackLog->getRecentAttacks($limit, $instance);
        $stats = $this->attackLog->getStatistics($instance, 7);
        
        return [
            'success' => true,
            'data' => [
                'attacks' => $attacks,
                'statistics' => $stats,
                'page' => $page,
                'limit' => $limit,
                'total' => $stats['total_attacks'],
            ],
        ];
    }
    
    /**
     * API: 获取攻击统计
     */
    public function getAttackStats(): array
    {
        $instance = $this->request->getGet('instance', '');
        $days = (int) $this->request->getGet('days', 7);
        
        return [
            'success' => true,
            'data' => $this->attackLog->getStatistics($instance, $days),
        ];
    }
    
    /**
     * API: 获取 Worker 状态历史
     */
    public function getWorkerHistory(): array
    {
        $instance = $this->request->getGet('instance', 'default');
        $limit = (int) $this->request->getGet('limit', 100);
        
        return [
            'success' => true,
            'data' => $this->statusLog->getStatusHistory(
                $instance,
                ServerStatusLog::PROCESS_TYPE_WORKER,
                $limit
            ),
        ];
    }
    
    /**
     * 攻击日志页面
     */
    public function getAttackLog(): string
    {
        $instance = $this->request->getGet('instance', '');
        $page = (int) $this->request->getGet('page', 1);
        $limit = (int) $this->request->getGet('limit', 50);
        
        $attacks = $this->attackLog->clearQuery();
        
        if ($instance) {
            $attacks->where(AttackLog::schema_fields_INSTANCE, $instance);
        }
        
        $total = $attacks->count();
        
        $attacks = $attacks->clearQuery();
        if ($instance) {
            $attacks->where(AttackLog::schema_fields_INSTANCE, $instance);
        }
        
        $list = $attacks
            ->order(AttackLog::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $limit)
            ->select()
            ->fetchArray();
        
        $this->assign('attacks', $list);
        $this->assign('instance', $instance);
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('total', $total);
        $this->assign('totalPages', \ceil($total / $limit));
        $this->assign('attackStats', $this->attackLog->getStatistics($instance, 7));
        $this->assign('title', __('攻击日志'));
        
        return $this->fetch('attack-log');
    }
    
    /**
     * API 文档页面
     */
    public function getApiDoc(): string
    {
        $this->assign('apiDocs', $this->getMasterApiDocs());
        $this->assign('title', __('WLS API 文档'));
        return $this->fetch('api-doc');
    }
    
    /**
     * 获取 Master API 文档
     */
    private function getMasterApiDocs(): array
    {
        return [
            [
                'category' => __('健康检查'),
                'apis' => [
                    [
                        'name' => __('基础健康检查'),
                        'method' => 'GET',
                        'path' => '/_wls/health',
                        'description' => __('返回 Worker 基础健康状态'),
                        'response' => '{"status":"healthy","worker_id":0}',
                    ],
                    [
                        'name' => __('详细健康检查'),
                        'method' => 'GET',
                        'path' => '/_wls/health?detail=1',
                        'description' => __('返回 Worker 详细状态信息，包括内存、请求数、连接数等'),
                        'response' => '{"status":"healthy","instance":"default","worker_id":0,"port":9981,"connections":5,"active_requests":1,"total_requests":1234,"memory_usage":12345678,"memory_peak":23456789,"uptime":3600,"php_version":"8.4.0","ssl":false,"timestamp":1234567890}',
                    ],
                ],
            ],
            [
                'category' => __('进程管理'),
                'apis' => [
                    [
                        'name' => __('优雅停止'),
                        'method' => 'POST',
                        'path' => '/_wls/shutdown',
                        'description' => __('优雅停止当前 Worker 进程（仅本地访问）'),
                        'response' => '{"status":"shutdown"}',
                    ],
                    [
                        'name' => __('热重载'),
                        'method' => 'POST',
                        'path' => '/_wls/reload',
                        'description' => __('触发代码热重载（仅本地访问）'),
                        'response' => '{"status":"reloading"}',
                    ],
                ],
            ],
            [
                'category' => __('状态查询'),
                'apis' => [
                    [
                        'name' => __('Dispatcher 状态'),
                        'method' => 'GET',
                        'path' => '/_wls/dispatcher/status',
                        'description' => __('获取 Dispatcher 分流器状态（仅 Windows 模式）'),
                        'response' => '{"status":"running","workers":[...],"connections":10}',
                    ],
                    [
                        'name' => __('Workers 列表'),
                        'method' => 'GET',
                        'path' => '/_wls/workers',
                        'description' => __('获取所有 Worker 进程列表'),
                        'response' => '{"workers":[{"id":0,"port":9981,"pid":1234,"status":"running"},...]}',
                    ],
                ],
            ],
            [
                'category' => __('调试工具'),
                'apis' => [
                    [
                        'name' => __('请求回显'),
                        'method' => 'GET/POST',
                        'path' => '/_wls/echo',
                        'description' => __('回显请求信息，用于调试'),
                        'response' => '{"method":"GET","uri":"/","headers":{...},"body":"..."}',
                    ],
                    [
                        'name' => __('内存缓存状态'),
                        'method' => 'GET',
                        'path' => '/_wls/cache/status',
                        'description' => __('获取 WLS 内存缓存状态'),
                        'response' => '{"enabled":true,"entries":100,"memory":"10MB"}',
                    ],
                ],
            ],
        ];
    }
    
    /**
     * API: 清理过期日志
     */
    public function postCleanupLogs(): array
    {
        $statusDays = (int) $this->request->getPost('status_days', 7);
        $attackDays = (int) $this->request->getPost('attack_days', 30);
        
        $statusCleaned = $this->statusLog->cleanupOldLogs($statusDays);
        $attackCleaned = $this->attackLog->cleanupOldLogs($attackDays);
        
        return [
            'success' => true,
            'message' => __('日志清理完成'),
            'data' => [
                'status_logs_cleaned' => $statusCleaned,
                'attack_logs_cleaned' => $attackCleaned,
            ],
        ];
    }

    public function getSecurityRules(): string
    {
        $rules = AttackDetector::getInstance()->getRules();
        $this->assign('rulesJson', \json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assign('title', __('安全规则'));
        return $this->fetch('security-rules');
    }

    public function postSaveSecurityRules(): array
    {
        try {
            $body = $this->request->getBodyParams(true);
            if (!\is_array($body)) {
                $body = [];
            }
            $rulesRaw = $body['rules'] ?? $this->request->getPost('rules', '');
            if (\is_string($rulesRaw)) {
                $decoded = \json_decode($rulesRaw, true);
                if (!\is_array($decoded)) {
                    throw new \InvalidArgumentException((string)__('规则 JSON 格式不正确'));
                }
                $rules = $decoded;
            } elseif (\is_array($rulesRaw)) {
                $rules = $rulesRaw;
            } else {
                throw new \InvalidArgumentException((string)__('规则数据不能为空'));
            }
            AttackDetector::getInstance()->updateRules($rules);
            return [
                'success' => true,
                'message' => __('安全规则已保存'),
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => __('保存失败：%{1}', $throwable->getMessage()),
            ];
        }
    }
}
