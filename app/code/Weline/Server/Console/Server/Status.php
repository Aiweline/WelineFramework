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
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\SharedStateServiceManager;

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
        $allInstances = $manager->getAllInstanceInfo(false);
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
                
                $this->printer->$svcColor($childPrefix . '       ' . $svcPrefix . ' ' . $service->displayName . '#' . $service->instanceId . ' (PID:' . $service->pid . $portDisplay . ') ' . $svcStatus);
                $svcIndex++;
            }
            
            echo "\n";
            $index++;
        }

        $this->showSharedDependencies();

        $this->printer->note(__('使用 server:status <name> 查看详细状态'));
        $this->printer->note(__('使用 server:stop <name> 停止实例'));
    }
    
    /**
     * 显示单个实例状态
     *
     * 使用 ServerInstanceManager 获取统一的实例信息
     */
    protected function showInstanceStatus(string $name): void
    {
        $manager = $this->getInstanceManager();
        $info = $manager->getInstanceInfo($name);

        if ($info === null) {
            $this->printer->warning(__('实例 [%{1}] 不存在', [$name]));
            echo "\n";
            $this->showAllInstances();
            return;
        }
        
        $processInfoMap = $this->buildProcessInfoMap([$name => $info]);
        $masterRunning = $this->isMasterRunning($info, $processInfoMap);
        
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
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
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
        $this->showSharedDependencies();

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
        $this->printer->note(__('测试请求：curl %{1}/', [$info->getListenAddress()]));
        $this->printer->note(__('停止服务：php bin/w server:stop %{1}', [$name]));
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
        
        $portStr = $service->port !== null && $service->port > 0 ? (__('端口：') . $service->port) : '';

        $label = $service->displayName . ' #' . $service->instanceId . ' (' . $portStr . ')';
        
        $this->printer->$color("  │");
        $this->printer->$color("  {$prefix} {$label} {$icon} {$statusStr}");
        
        if ($isRunning && $service->pid > 0) {
            $memPrefix = $isLast ? '   ' : '│  ';
            // 使用预取的内存信息（避免逐个查询）
            $memory = (string) ($processInfoMap[$service->pid]['memory'] ?? '');
            if ($memory !== '') {
                $this->printer->note('  ' . $memPrefix . '  └─ ' . __('内存：') . $memory);
            }
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
            if ($this->isMasterRunning($info, $processInfoMap) || $this->getServiceStats($info, $processInfoMap, false)['running'] > 0) {
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
            if ($info->masterPid > 0) {
                $pids[$info->masterPid] = $info->masterPid;
            }
            foreach ($info->services as $service) {
                if ($service->pid > 0) {
                    $pids[$service->pid] = $service->pid;
                }
            }
        }

        return $pids === [] ? [] : Processer::batchGetProcessInfo(\array_values($pids));
    }

    /**
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     */
    protected function isMasterRunning(ServerInstanceInfo $info, array $processInfoMap): bool
    {
        if ($info->masterPid <= 0) {
            return false;
        }

        return (bool) ($processInfoMap[$info->masterPid]['exists'] ?? false);
    }

    /**
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     */
    protected function isServiceRunning(ServiceInfo $service, array $processInfoMap): bool
    {
        if (!$service->isExpectedRunningState()) {
            return false;
        }

        if ($service->pid > 0) {
            return (bool) ($processInfoMap[$service->pid]['exists'] ?? false);
        }

        return true;
    }

    /**
     * @param array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}> $processInfoMap
     * @return array{total: int, running: int, stopped: int}
     */
    protected function getServiceStats(ServerInstanceInfo $info, array $processInfoMap, bool $includeSharedExternal = true): array
    {
        $total = 0;
        $running = 0;
        foreach ($info->services as $service) {
            if (!$includeSharedExternal && $this->isSharedExternalService($service)) {
                continue;
            }
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

    protected function isSharedExternalService(ServiceInfo $service): bool
    {
        return (bool) ($service->metadata['shared_external'] ?? false);
    }

    /**
     * @param ServiceInfo[] $services
     * @return ServiceInfo[]
     */
    protected function filterVisibleServices(array $services): array
    {
        return \array_values(\array_filter($services, fn(ServiceInfo $service): bool => !$this->isSharedDependencyService($service)));
    }

    protected function isSharedDependencyService(ServiceInfo $service): bool
    {
        return $service->role === ControlMessage::ROLE_SESSION_SERVER
            || $service->role === ControlMessage::ROLE_MEMORY_SERVER;
    }

    protected function showSharedDependencies(): void
    {
        $manager = new SharedStateServiceManager();

        echo "\n";
        $this->printer->note(__('全局共享依赖：'));
        $this->showSharedDependencyStatus(
            __('Session Server'),
            $manager->status(ControlMessage::ROLE_SESSION_SERVER)
        );
        $this->showSharedDependencyStatus(
            __('Memory Service'),
            $manager->status(ControlMessage::ROLE_MEMORY_SERVER)
        );
    }

    /**
     * @param array<string, mixed> $status
     */
    protected function showSharedDependencyStatus(string $label, array $status): void
    {
        if (($status['enabled'] ?? true) === false) {
            $this->printer->note('  ' . $label . ': ' . __('disabled'));

            return;
        }

        $healthy = (bool) ($status['healthy'] ?? false);
        $color = $healthy ? 'success' : 'warning';
        $summary = $label
            . ': '
            . ($healthy ? __('运行中') : __('不可用'))
            . ' '
            . (string) ($status['host'] ?? '127.0.0.1')
            . ':'
            . (int) ($status['port'] ?? 0)
            . ' (PID:'
            . (int) ($status['pid'] ?? 0)
            . ', token:'
            . (string) ($status['token_file_name'] ?? '')
            . ')';

        $this->printer->$color('  ' . $summary);
    }

    /**
     * 获取实例管理器
     */
    protected function getInstanceManager(): ServerInstanceManager
    {
        return ObjectManager::getInstance(ServerInstanceManager::class);
    }
    
    /**
     * 显示 Worker 内存占用（通过端口查找 PID）
     */
    protected function showWorkerMemory(int $port, string $prefix): void
    {
        $pid = Processer::getProcessIdByPort($port);
        if ($pid <= 0) {
            return;
        }
        $info = Processer::getProcessInfo($pid);
        $memory = (string)($info['memory'] ?? '');
        if ($memory !== '') {
            $this->printer->note('  ' . $prefix . '  └─ ' . __('内存：') . $memory . ' (PID: ' . $pid . ')');
        }
    }
    
    /**
     * 显示进程内存占用（通过 PID 直接获取）
     */
    protected function showProcessMemory(int $pid, string $prefix): void
    {
        if ($pid <= 0) {
            return;
        }
        $info = Processer::getProcessInfo($pid);
        $memory = (string)($info['memory'] ?? '');
        if ($memory !== '') {
            $this->printer->note($prefix . '└─ ' . __('内存：') . $memory);
        }
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
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('查看所有实例') => 'php bin/w server:status',
                __('查看指定实例') => 'php bin/w server:status api-server',
                __('实时监控（watch 模式）') => 'php bin/w server:status -w',
            ]
        );
    }
}
