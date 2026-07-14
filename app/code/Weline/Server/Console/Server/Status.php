<?php
declare(strict_types=1);

/**
 * Weline Server - 状态命令
 * 
 * 树形显示服务器实例和所有 Worker 进程状态
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Framework\App\Env;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Policy\RuntimePolicyStore;
use Weline\Server\Service\Runtime\RuntimeEndpointMetadata;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:status - 查看服务器状态
 */
class Status extends CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 解析参数
        $instanceName = $this->parseInstanceName($args);
        $showAll = isset($args['all']) || isset($args['a']) || $instanceName === '';
        $watchMode = isset($args['w']) || isset($args['watch']);
        $doctorMode = isset($args['doctor']);
        $jsonMode = isset($args['json']);

        if ($doctorMode) {
            $diagnostics = (new Doctor())->buildDiagnostics($instanceName);
            if ($jsonMode) {
                echo \json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                return;
            }
            (new Doctor())->execute(['server:doctor', $instanceName], []);
            return;
        }

        if ($watchMode) {
            $this->runWatchMode($instanceName, $showAll);
            return;
        }

        if ($showAll || ($instanceName === 'default' && !$this->instanceExists('default'))) {
            $this->showAllInstances();
            return;
        }

        $this->showInstanceStatus($instanceName);
    }

    /**
     * Watch 模式：每 3 秒清屏刷新状态
     */
    protected function runWatchMode(string $instanceName, bool $showAll): void
    {
        $supportsAnsi = \function_exists('stream_isatty') && \stream_isatty(\STDOUT);

        while (true) {
            if ($supportsAnsi) {
                echo "\033[2J\033[H";
            } else {
                \strtoupper(\substr(\PHP_OS, 0, 3)) === 'WIN' ? \system('cls') : \system('clear');
            }

            if ($showAll || ($instanceName === 'default' && !$this->instanceExists('default'))) {
                $this->showAllInstances();
            } else {
                $this->showInstanceStatus($instanceName);
            }

            echo "\n  [ " . __('每 3 秒刷新') . " | Ctrl+C " . __('退出') . " ]\n";
            \Weline\Framework\Runtime\SchedulerSystem::sleep(3);
        }
    }
    
    /**
     * 解析实例名称
     */
    protected function parseInstanceName(array $args): string
    {
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        array_shift($positionalArgs);
        
        return $positionalArgs[0] ?? 'default';
    }
    
    /**
     * 检查实例是否存在
     */
    protected function instanceExists(string $name): bool
    {
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        return $manager->hasInstance($name);
    }
    
    /**
     * 显示所有实例
     */
    protected function showAllInstances(): void
    {
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $allInstances = $manager->getAllPersistedInstanceInfo();
        $processInfoMap = $this->buildProcessInfoMap($allInstances);
        $allInstances = $this->filterActiveInstances($allInstances, $processInfoMap);

        $this->printer->setup(__('Weline Server 状态'));
        echo "\n";

        if (empty($allInstances)) {
            $this->printer->note(__('没有运行中的服务器实例'));
            echo "\n";
            $this->showStartTip();
            return;
        }

        $this->printer->note(__('╔══════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║                    服务器实例列表                              ║'));
        $this->printer->note(__('╚══════════════════════════════════════════════════════════════╝'));
        echo "\n";

        $total = \count($allInstances);
        $index = 0;
        foreach ($allInstances as $name => $info) {
            /** @var ServerInstanceInfo $info */
            $isLast = ($index === $total - 1);
            $prefix = $isLast ? '└─' : '├─';
            $childPrefix = $isLast ? '   ' : '│  ';
            
            $port = $info->port;
            $count = $info->workerCount;
            $host = $info->host;
            $startedAt = $info->startedAt;
            $dispatcherEnabled = $info->dispatcherEnabled;
            
            // 从实际服务列表获取 Worker 信息
            $workers = $info->getWorkers();
            $runningCount = 0;
            foreach ($workers as $worker) {
                if ($this->isServiceRunning($worker, $processInfoMap)) {
                    $runningCount++;
                }
            }
            
            $status = $runningCount === $count ? __('● 运行中') : ($runningCount > 0 ? __('◐ 部分运行') : __('○ 已停止'));
            $statusColor = $runningCount === $count ? 'success' : ($runningCount > 0 ? 'warning' : 'error');
            
            // 实例名称行
            $this->printer->$statusColor($prefix . ' [' . $name . '] ' . $status . ' (' . $runningCount . '/' . $count . ' ' . __('workers') . ')');
            
            // 详细信息
            $scheme = $info->sslEnabled ? 'https' : 'http';
            $this->printer->note($childPrefix . '  ├─ ' . __('地址：') . $scheme . '://' . $host . ':' . $port);
            $this->showRuntimeMetadata($name, $childPrefix . '  ├─ ', true);
            
            // 显示实际 Worker 端口范围
            $workerPorts = [];
            foreach ($workers as $worker) {
                if ($worker->port !== null && $worker->port > 0) {
                    $workerPorts[] = $worker->port;
                }
            }
            $workerPortStr = !empty($workerPorts) ? \implode(',', $workerPorts) : __('(未知)');
            $portRangeStr = $dispatcherEnabled ? 'Dispatcher:' . $port . ', Workers:' . $workerPortStr : $workerPortStr;
            $this->printer->note($childPrefix . '  ├─ ' . __('端口范围：') . $portRangeStr);
            $this->printer->note($childPrefix . '  ├─ ' . __('启动时间：') . $startedAt);
            
            // 所有服务进程列表
            $this->printer->note($childPrefix . '  └─ ' . __('服务：'));
            
            $services = $this->filterVisibleServices($info->services);
            $serviceCount = \count($services);
            $svcIndex = 0;
            foreach ($services as $service) {
                /** @var ServiceInfo $service */
                $isLastSvc = ($svcIndex === $serviceCount - 1);
                $svcPrefix = $isLastSvc ? '└─' : '├─';
                
                $isRunning = $this->isServiceRunning($service, $processInfoMap);
                $svcStatus = $isRunning ? __('● 运行中') : __('○ 已停止');
                $svcColor = $isRunning ? 'success' : 'error';
                $portDisplay = $service->port !== null && $service->port > 0 ? ':' . $service->port : '';
                $trackingPid = $service->getTrackingPid();
                $pidDisplay = $trackingPid > 0 ? $trackingPid : $service->pid;
                $pidSuffix = ($service->pid > 0 && $trackingPid > 0 && $trackingPid !== $service->pid)
                    ? ', child:' . $service->pid
                    : '';

                $this->printer->$svcColor($childPrefix . '       ' . $svcPrefix . ' ' . $service->displayName . '#' . $service->instanceId . ' (PID:' . $pidDisplay . $pidSuffix . $portDisplay . ') ' . $svcStatus);
                $svcIndex++;
            }
            
            echo "\n";
            $index++;
        }


        $this->printer->note(__('使用 server:status <name> 查看详细状态'));
        $this->printer->note(__('使用 server:stop <name> 停止实例'));
        $this->printer->note(__('如有残留 endpoint 文件，可执行 server:clean 清理未运行记录'));
    }
    
    /**
     * 显示单个实例状态
     *
     * 使用 ServerInstanceManager 获取统一的实例信息
     */
    protected function showInstanceStatus(string $name): void
    {
        $manager = $this->getInstanceManager();
        $info = $manager->getInstanceInfoWithIpcTimeout($name, false, 0.5);

        if ($info === null) {
            $this->printer->warning(__('实例 [%{1}] 不存在', [$name]));
            echo "\n";
            $this->showAllInstances();
            return;
        }
        
        $processInfoMap = $this->buildProcessInfoMap([$name => $info]);
        $masterRuntimeState = $this->resolveMasterRuntimeState($info, $processInfoMap);
        $masterRunning = (bool) $masterRuntimeState['running'];
        
        $this->printer->setup(__('实例 [%{1}] 状态', [$name]));
        echo "\n";
        
        $this->printer->note(__('╔══════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║                    实例详细信息                                ║'));
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  ' . __('实例名称：') . '%-50s║', $info->name));
        $this->printer->note(\sprintf('║  ' . __('监听地址：') . '%-50s║', $info->getListenAddress()));
        $this->printer->note(\sprintf('║  ' . __('端口范围：') . '%-50s║', $info->getPortRangeDescription()));
        $this->printer->note(\sprintf('║  ' . __('Worker 数：') . '%-49s║', (string)$info->workerCount));
        $this->printer->note(\sprintf('║  ' . __('启动时间：') . '%-50s║', $info->startedAt ?: __('unknown')));
        $masterPidStr = $info->masterPid > 0 ? (string)$info->masterPid : '-';
        $masterStatusStr = $masterRunning ? __('● 运行中') : __('○ 已停止');
        $this->printer->note(\sprintf('║  Master PID：%-47s║', $masterPidStr));
        $this->printer->note(\sprintf('║  ' . __('Master 状态：') . '%-46s║', $masterStatusStr));
        $selfHealMode = $this->resolveSelfHealMode();
        $this->printer->note(\sprintf('║  ' . __('Master 自愈：') . '%-46s║', $selfHealMode));
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
        $this->showRuntimeMetadata($name);
        if ((string) ($masterRuntimeState['message'] ?? '') !== '') {
            $this->printer->warning((string) $masterRuntimeState['message']);
        }
        $this->showStartupFailureSummary($info);
        echo "\n";
        
        // 进程架构展示
        $this->printer->note(__('进程架构：'));
        echo "\n";
        
        // Master 进程状态
        $masterColor = $masterRunning ? 'success' : 'error';
        $masterIcon = $masterRunning ? '●' : '○';
        $masterStatus = $masterRunning ? __('运行中') : __('已停止');
        $this->printer->$masterColor("  {$masterIcon} Master (PID: {$masterPidStr}) {$masterStatus}");
        if ($masterRunning && isset($processInfoMap[$info->masterPid])) {
            $memory = (string) ($processInfoMap[$info->masterPid]['memory'] ?? '');
            if ($memory !== '') {
                $this->printer->note('  │  └─ ' . __('内存：') . $memory);
            }
        }
        
        // 显示所有服务实例（按优先级排序，使用预取的内存信息）
        $this->showServicesTree($info, $processInfoMap);

        // 总结
        $stats = $this->getServiceStats($info, $processInfoMap);
        $runningCount = $stats['running'];
        $totalCount = $stats['total'];
        
        if ($runningCount === $totalCount && $totalCount > 0) {
            $this->printer->success(__('状态：全部运行中 (%{1}/%{2})', [$runningCount, $totalCount]));
        } elseif ($runningCount > 0) {
            $this->printer->warning(__('状态：部分运行 (%{1}/%{2})', [$runningCount, $totalCount]));
        } else {
            $this->printer->error(__('状态：全部停止 (%{1}/%{2})', [$runningCount, $totalCount]));
        }
        
        echo "\n";
        $this->printer->note(__('测试请求：curl %{1}/', [$this->buildTestCurlTarget($info)]));
        $this->printer->note(__('停止服务：php bin/w server:stop %{1}', [$name]));
    }

    private function showRuntimeMetadata(string $instanceName, string $prefix = '  ', bool $compact = false): void
    {
        $raw = $this->getInstanceManager()->getRawInstanceData($instanceName);
        if (!\is_array($raw)) {
            return;
        }

        $runtime = RuntimeEndpointMetadata::fromEndpoint($raw)->toArray();
        $schemaVersion = (int)($runtime['endpoint_schema_version'] ?? 0);
        $selectionValid = $runtime['runtime_selection_valid'] ?? null;
        if ($schemaVersion >= 3 && $selectionValid !== true) {
            $message = __('运行时选择：endpoint schema v%{1} 校验失败，已拒绝从兼容字段重新推导拓扑。', [$schemaVersion]);
            $error = \trim((string)($runtime['runtime_selection_error'] ?? ''));
            $this->printer->warning($prefix . $message . ($error !== '' ? ' ' . $error : ''));
            return;
        }

        $effective = (string)($runtime['effective_topology'] ?? '');
        $listener = (string)($runtime['listener_strategy'] ?? '');
        $eventLoop = (string)($runtime['event_loop_driver'] ?? '');
        $sslEngine = (string)($runtime['ssl_engine'] ?? '');
        $httpProtocols = \is_array($runtime['http_protocols'] ?? null)
            ? \array_values(\array_filter(\array_map('strval', $runtime['http_protocols'])))
            : [];
        $httpPreferredProtocol = \trim((string)($runtime['http_preferred_protocol'] ?? ''));
        $protocolEdgeEnabled = $runtime['protocol_edge_enabled'] ?? null;
        $protocolEdge = \trim((string)($runtime['protocol_edge'] ?? ''));
        $tlsSessionResumption = $runtime['tls_session_resumption'] ?? null;
        $digest = \strtolower(\trim((string)($runtime['policy_digest'] ?? '')));
        $digestSource = 'endpoint';
        try {
            // RuntimeSelection in endpoint metadata is the immutable startup
            // topology fact. Policy activation is independently hot-swappable,
            // so its current digest must come from the authoritative policy
            // store instead of the startup snapshot.
            $activePolicy = (new RuntimePolicyStore())->active($instanceName);
            if ($activePolicy !== null) {
                $digest = $activePolicy->digest;
                $digestSource = 'active-store';
            }
        } catch (\Throwable) {
            // Status stays available during a partial policy-store failure and
            // explicitly labels the endpoint fallback below.
        }
        $digestShort = $digest !== '' ? \substr($digest, 0, 12) : '-';
        $containerDigest = \strtolower(\trim((string)($runtime['container_registry_digest'] ?? '')));
        $containerDigestShort = $containerDigest !== '' ? \substr($containerDigest, 0, 12) : '-';

        if ($compact) {
            if ($effective === '' && $listener === '' && $eventLoop === '' && $sslEngine === '') {
                return;
            }
            $summary = ($effective !== '' ? $effective : '-')
                . ' / ' . ($listener !== '' ? $listener : '-')
                . ' / ' . ($eventLoop !== '' ? $eventLoop : '-')
                . ' / ' . ($sslEngine !== '' ? $sslEngine : '-')
                . ($httpProtocols !== [] ? ' / http=' . \implode('>', $httpProtocols) : '')
                . ' / policy=' . $digestShort
                . ' / container=' . $containerDigestShort;
            $this->printer->note($prefix . __('实际运行时：') . $summary);
            return;
        }

        echo "\n";
        $this->printer->note(__('实际运行时选择：'));
        $requested = (string)($runtime['requested_topology'] ?? '');
        $source = (string)($runtime['topology_source'] ?? '');
        $this->printer->note($prefix . __('拓扑：')
            . ($requested !== '' ? $requested : '-')
            . ' -> '
            . ($effective !== '' ? $effective : '-')
            . ($source !== '' ? ' (source=' . $source . ')' : ''));
        $this->printer->note($prefix . __('数据面：')
            . 'listener=' . ($listener !== '' ? $listener : '-')
            . ', event=' . ($eventLoop !== '' ? $eventLoop : '-')
            . ', ssl=' . ($sslEngine !== '' ? $sslEngine : '-'));
        if ($httpProtocols !== [] || $protocolEdgeEnabled !== null || $tlsSessionResumption !== null) {
            $this->printer->note($prefix . __('HTTP 协议：')
                . 'auto=' . ($httpProtocols !== [] ? \implode(' -> ', $httpProtocols) : '-')
                . ', preferred=' . ($httpPreferredProtocol !== '' ? $httpPreferredProtocol : '-')
                . ', edge=' . ($protocolEdge !== ''
                    ? $protocolEdge
                    : ($protocolEdgeEnabled === null ? '-' : ($protocolEdgeEnabled ? 'enabled' : 'disabled')))
                . ', tls-resumption=' . ($tlsSessionResumption === null ? '-' : ($tlsSessionResumption ? 'enabled' : 'disabled')));
        }
        $compatible = $runtime['policy_compatible'] ?? null;
        $compatibleText = \is_bool($compatible) ? ($compatible ? 'true' : 'false') : '-';
        $this->printer->note($prefix . __('策略：')
            . 'compatible=' . $compatibleText
            . ', digest=' . ($digest !== '' ? $digest : '-')
            . ', source=' . $digestSource);
        $this->printer->note($prefix . __('Endpoint：')
            . 'schema=v' . $schemaVersion
            . ', metadata=' . (string)($runtime['metadata_source'] ?? '-')
            . ', container=' . ($containerDigest !== '' ? $containerDigest : '-'));

        $reasonCodes = \is_array($runtime['topology_reason_codes'] ?? null)
            ? \array_values($runtime['topology_reason_codes'])
            : [];
        $reason = \trim((string)($runtime['topology_reason'] ?? ''));
        if ($reasonCodes !== [] || $reason !== '') {
            $this->printer->note($prefix . __('选择原因：')
                . ($reasonCodes !== [] ? '[' . \implode(', ', $reasonCodes) . '] ' : '')
                . $reason);
        }
    }

    protected function showStartupFailureSummary(ServerInstanceInfo $info): void
    {
        if ($info->startupFailureReason === ''
            && $info->startupFailureCode === ''
            && $info->startupFailureDiagnostics === []) {
            return;
        }

        echo "\n";
        $this->printer->error(__('启动失败详情：'));
        if ($info->startupFailureCode !== '') {
            $this->printer->warning('  code: ' . $info->startupFailureCode);
        }
        if ($info->startupFailureClass !== '') {
            $this->printer->note('  class: ' . $info->startupFailureClass);
        }
        if ($info->startupFailureReason !== '') {
            $this->printer->warning('  reason: ' . $info->startupFailureReason);
        }

        $context = $this->formatStartupFailureContextSummary($info->startupFailureContext);
        if ($context !== '') {
            $this->printer->note('  context: ' . $context);
        }

        $diagnostics = \array_slice($info->startupFailureDiagnostics, 0, 8);
        foreach ($diagnostics as $diagnostic) {
            $diagnostic = \trim((string)$diagnostic);
            if ($diagnostic !== '') {
                $this->printer->note('  diagnostic: ' . $diagnostic);
            }
        }
    }

    protected function formatStartupFailureContextSummary(array $context): string
    {
        $parts = [];
        foreach ([
            'instance',
            'main_port',
            'control_port',
            'worker_count',
            'dispatcher_enabled',
            'ssl_enabled',
            'startup_timeout_sec',
            'elapsed_sec',
        ] as $key) {
            if (!\array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if (\is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (\is_array($value)) {
                continue;
            }
            $parts[] = $key . '=' . (string)$value;
        }

        return \implode(', ', $parts);
    }

    protected function buildTestCurlTarget(ServerInstanceInfo $info): string
    {
        $scheme = $info->sslEnabled ? 'https' : 'http';
        $host = \strtolower(\trim($info->host));
        if ($host === '' || $host === '0.0.0.0' || $host === '*') {
            $host = '127.0.0.1';
        } elseif ($host === '::') {
            $host = '[::1]';
        } elseif (\str_contains($host, ':') && !\str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $target = $scheme . '://' . $host . ':' . $info->port;
        return $info->sslEnabled ? '-k ' . $target : $target;
    }

    /**
     * 读取当前 Master 自愈开关的展示字符串
     *
     * - 默认开启：'● on'
     * - 显式关闭：'○ off (env.php)'
     */
    protected function resolveSelfHealMode(): string
    {
        try {
            $config = Env::getInstance()->getConfig() ?: [];
            $raw = $config['wls']['orchestrator']['allow_child_resurrection'] ?? null;
            if ($raw === null) {
                return __('● on (默认)');
            }
            return ((bool)$raw) ? __('● on (env.php)') : __('○ off (env.php)');
        } catch (\Throwable) {
            return __('● on (默认)');
        }
    }

    /**
     * 显示服务实例树形结构
     * 
     * @param ServerInstanceInfo $info 实例信息
     * @param array<int, array> $processInfoMap 预取的进程信息映射（PID => info）
     */
    protected function showServicesTree(ServerInstanceInfo $info, array $processInfoMap = []): void
    {
        // 按角色分组
        $servicesByRole = [];
        foreach ($this->filterVisibleServices($info->services) as $service) {
            $servicesByRole[$service->role][] = $service;
        }

        // 使用服务优先级排序角色，避免新增角色需要在此硬编码维护
        \uasort($servicesByRole, static function (array $left, array $right): int {
            $leftPriority = isset($left[0]) ? (int)$left[0]->priority : 99;
            $rightPriority = isset($right[0]) ? (int)$right[0]->priority : 99;
            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }
            $leftRole = isset($left[0]) ? (string)$left[0]->role : '';
            $rightRole = isset($right[0]) ? (string)$right[0]->role : '';
            return \strcmp($leftRole, $rightRole);
        });

        $roleKeys = \array_keys($servicesByRole);
        $lastRoleIndex = \count($roleKeys) - 1;
        
        foreach ($servicesByRole as $roleIndex => $services) {
            $roleKeyIndex = \array_search($roleIndex, $roleKeys, true);
            $isLastRole = ($roleKeyIndex === $lastRoleIndex);
            
            foreach ($services as $i => $service) {
                $isLastService = ($i === \count($services) - 1) && $isLastRole;
                $this->showServiceStatus($service, $isLastService, $processInfoMap);
            }
        }
        
        echo "\n";
    }
    
    /**
     * 显示单个服务实例状态
     * 
     * @param ServiceInfo $service 服务信息
     * @param bool $isLast 是否最后一个
     * @param array<int, array> $processInfoMap 预取的进程信息映射
     */
    protected function showServiceStatus(ServiceInfo $service, bool $isLast, array $processInfoMap = []): void
    {
        $isRunning = $this->isServiceRunning($service, $processInfoMap);
        $icon = $isRunning ? '●' : '○';
        $statusStr = $isRunning ? __('运行中') : __('已停止');
        $color = $isRunning ? 'success' : 'error';
        $prefix = $isLast ? '└─' : '├─';
        
        $details = [];
        $trackingPid = $service->getTrackingPid();
        if ($trackingPid > 0) {
            $details[] = 'PID: ' . $trackingPid;
        }
        if ($service->pid > 0 && $trackingPid > 0 && $service->pid !== $trackingPid) {
            $details[] = 'child: ' . $service->pid;
        }
        if ($service->port !== null && $service->port > 0) {
            $details[] = __('端口：') . $service->port;
        }

        $detailStr = $details === [] ? '' : ' (' . \implode(', ', $details) . ')';
        $label = $service->displayName . ' #' . $service->instanceId . $detailStr;
        
        $this->printer->$color("  │");
        $this->printer->$color("  {$prefix} {$label} {$icon} {$statusStr}");
        
        $runtimeDetails = [];
        if ($isRunning && $trackingPid > 0) {
            $memPrefix = $isLast ? '   ' : '│  ';
            // 使用预取的内存信息（避免逐个查询）
            $memory = (string) ($processInfoMap[$trackingPid]['memory'] ?? '');
            if ($memory !== '') {
                $runtimeDetails[] = __('内存：') . $memory;
            }
        }

        if ($service->role === ControlMessage::ROLE_WORKER) {
            $proof = \is_array($service->metadata['dynamic_first_render'] ?? null)
                ? $service->metadata['dynamic_first_render']
                : [];
            if ($proof !== []) {
                $runtimeDetails[] = __('动态首渲染：')
                    . 'ready=' . (($proof['ready'] ?? false) === true ? 'true' : 'false')
                    . ', elapsed=' . \round((float)($proof['elapsed_ms'] ?? 0.0), 2)
                    . '/' . \round((float)($proof['target_ms'] ?? 0.0), 2) . 'ms'
                    . ', status=' . (int)($proof['status_code'] ?? 0)
                    . ', body=' . (int)($proof['body_length'] ?? 0)
                    . ', attempts=' . (int)($proof['attempts'] ?? 0)
                    . ', fpc=' . (string)($proof['fpc_status'] ?? '-')
                    . ', route=' . (string)($proof['host'] ?? '-') . (string)($proof['path'] ?? '')
                    . ', reason=' . (string)($proof['reason'] ?? '-');
            }
        }

        $runtimeDetailCount = \count($runtimeDetails);
        foreach ($runtimeDetails as $runtimeDetailIndex => $runtimeDetail) {
            $memPrefix = $isLast ? '   ' : '│  ';
            $connector = $runtimeDetailIndex === $runtimeDetailCount - 1 ? '└─ ' : '├─ ';
            $this->printer->note('  ' . $memPrefix . '  ' . $connector . $runtimeDetail);
        }
    }
    
    /**
     * @param array<string, ServerInstanceInfo> $instances
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     * @return array<string, ServerInstanceInfo>
     */
    protected function filterActiveInstances(array $instances, array $processInfoMap): array
    {
        $active = [];
        foreach ($instances as $name => $info) {
            if ($this->isMasterRunning($info, $processInfoMap)) {
                $active[$name] = $info;
            }
        }

        return $active;
    }

    /**
     * @param array<string, ServerInstanceInfo> $instances
     * @return array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}>
     */
    protected function buildProcessInfoMap(array $instances): array
    {
        $pids = [];
        foreach ($instances as $info) {
            if (!$info instanceof ServerInstanceInfo) {
                continue;
            }
            if ($info->masterPid > 0) {
                $pids[$info->masterPid] = true;
            }
            foreach ($info->services as $service) {
                foreach ($service->getManagedPids() as $pid) {
                    if ($pid > 0) {
                        $pids[$pid] = true;
                    }
                }
                $trackingPid = $service->getTrackingPid();
                if ($trackingPid > 0) {
                    $pids[$trackingPid] = true;
                }
            }
        }

        if ($pids === []) {
            return [];
        }

        return Processer::batchGetProcessInfo(\array_map('intval', \array_keys($pids)));
    }

    /**
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     */
    protected function isMasterRunning(ServerInstanceInfo $info, array $processInfoMap): bool
    {
        return (bool) $this->resolveMasterRuntimeState($info, $processInfoMap)['running'];
    }

    /**
     * IPC is the primary health signal. If it times out, fall back to the
     * managed Master PID check so a busy control plane is not reported as
     * stopped while the process is still alive.
     *
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     * @return array{running: bool, ipc_ok: bool, source: string, message: string}
     */
    protected function resolveMasterRuntimeState(ServerInstanceInfo $info, array $processInfoMap): array
    {
        if ($info->masterPid <= 0) {
            return [
                'running' => false,
                'ipc_ok' => false,
                'source' => 'metadata',
                'message' => '',
            ];
        }

        $pidRunning = (bool) ($processInfoMap[$info->masterPid]['exists'] ?? false);
        if (!$pidRunning && $processInfoMap === []) {
            $pidRunning = $info->isMasterRunning();
        }

        if ($info->controlPort > 0) {
            $gateway = new IpcControlGateway();
            $status = $gateway->getStatusBrief($info->name, 0.5);
            if ($status['success'] && (bool)($status['data']['running'] ?? false)) {
                return [
                    'running' => true,
                    'ipc_ok' => true,
                    'source' => 'ipc',
                    'message' => '',
                ];
            }
        }

        if ($pidRunning) {
            return [
                'running' => true,
                'ipc_ok' => false,
                'source' => 'pid',
                'message' => __(
                    'Master PID is running, but IPC status did not respond within 0.5s; the control plane may be busy or in an orchestrator full-restart cycle.'
                ),
            ];
        }

        return [
            'running' => false,
            'ipc_ok' => false,
            'source' => 'pid',
            'message' => '',
        ];
    }

    /**
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     */
    protected function isServiceRunning(ServiceInfo $service, array $processInfoMap): bool
    {
        $trackingPid = $service->getTrackingPid();
        if ($trackingPid > 0) {
            if ($processInfoMap === []) {
                return $service->isExpectedRunningState();
            }

            if ((bool) ($processInfoMap[$trackingPid]['exists'] ?? false)) {
                return true;
            }

            if ($service->pid > 0 && $service->pid !== $trackingPid) {
                return (bool) ($processInfoMap[$service->pid]['exists'] ?? false);
            }

            return false;
        }

        if (!$service->isExpectedRunningState()) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     * @return array{total: int, running: int, stopped: int}
     */
    protected function getServiceStats(ServerInstanceInfo $info, array $processInfoMap): array
    {
        $total = 0;
        $running = 0;
        foreach ($info->services as $service) {
            if ($this->isSharedDependencyService($service)) {
                continue;
            }

            $total++;
            if ($this->isServiceRunning($service, $processInfoMap)) {
                $running++;
            }
        }

        return [
            'total' => $total,
            'running' => $running,
            'stopped' => $total - $running,
        ];
    }

    /**
     * @param ServiceInfo[] $services
     * @return ServiceInfo[]
     */
    protected function filterVisibleServices(array $services): array
    {
        return \array_values($services);
    }

    protected function isSharedDependencyService(ServiceInfo $service): bool
    {
        return $service->role === ControlMessage::ROLE_SESSION_SERVER
            || $service->role === ControlMessage::ROLE_MEMORY_SERVER;
    }

    /**
     * 获取实例管理器
     */
    protected function getInstanceManager(): ServerInstanceManager
    {
        return ObjectManager::getInstance(ServerInstanceManager::class);
    }
    
    /**
     * 显示启动提示
     */
    protected function showStartTip(): void
    {
        $this->printer->note(__('启动服务器：php bin/w server:start'));
        $this->printer->note(__('启动命名实例：php bin/w server:start api-server -p 9000'));
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('查看 Weline Server 运行状态');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:status [name]',
            __('查看服务器实例状态，树形展示所有 Worker 进程'),
            [
                '[name]' => __('实例名称（默认显示所有实例）'),
                '-a, --all' => __('显示所有实例'),
                '-w, --watch' => __('Watch 模式：每 3 秒自动刷新，Ctrl+C 退出'),
                '--doctor' => __('显示 WLS 运行时策略、降级原因和优化建议'),
                '--json' => __('与 --doctor 同用时输出 JSON'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('查看所有实例') => 'php bin/w server:status',
                __('查看指定实例') => 'php bin/w server:status api-server',
                __('实时监控（watch 模式）') => 'php bin/w server:status -w',
                __('运行时诊断') => 'php bin/w server:status --doctor --json',
                __('清理未运行实例记录') => 'php bin/w server:clean',
            ]
        );
    }
}
