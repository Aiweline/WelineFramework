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
use Weline\Server\Service\WlsInstanceRegistry;

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
        
        if ($showAll || ($instanceName === 'default' && !$this->instanceExists('default'))) {
            $this->showAllInstances();
            return;
        }
        
        $this->showInstanceStatus($instanceName);
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
        /** @var WlsInstanceRegistry $registry */
        $registry = ObjectManager::getInstance(WlsInstanceRegistry::class);
        return $registry->hasInstance($name);
    }
    
    /**
     * 显示所有实例
     */
    protected function showAllInstances(): void
    {
        /** @var WlsInstanceRegistry $registry */
        $registry = ObjectManager::getInstance(WlsInstanceRegistry::class);
        $allData = $registry->getAllInstanceData();

        $this->printer->setup(__('Weline Server 状态'));
        echo "\n";

        if (empty($allData)) {
            $this->printer->note(__('没有运行中的服务器实例'));
            echo "\n";
            $this->showStartTip();
            return;
        }

        $this->printer->note(__('╔══════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║                    服务器实例列表                              ║'));
        $this->printer->note(__('╚══════════════════════════════════════════════════════════════╝'));
        echo "\n";

        $total = \count($allData);
        $index = 0;
        foreach ($allData as $name => $data) {
            $isLast = ($index === $total - 1);
            $prefix = $isLast ? '└─' : '├─';
            $childPrefix = $isLast ? '   ' : '│  ';
            
            $port = (int)($data['port'] ?? Start::DEFAULT_PORT);
            $count = (int)($data['count'] ?? 4);
            $host = $data['host'] ?? '127.0.0.1';
            $startedAt = $data['started_at'] ?? 'unknown';
            $dispatcherEnabled = !empty($data['dispatcher_enabled']);
            $workerPortBase = (int)($data['worker_port'] ?? $port);
            
            // 检查有多少 Worker 在运行（使用实际 Worker 端口）
            $runningCount = 0;
            for ($i = 0; $i < $count; $i++) {
                if (Processer::isPortInUse($workerPortBase + $i)) {
                    $runningCount++;
                }
            }
            
            $status = $runningCount === $count ? '● 运行中' : ($runningCount > 0 ? '◐ 部分运行' : '○ 已停止');
            $statusColor = $runningCount === $count ? 'success' : ($runningCount > 0 ? 'warning' : 'error');
            
            // 实例名称行
            $this->printer->$statusColor("{$prefix} [{$name}] {$status} ({$runningCount}/{$count} workers)");
            
            // 详细信息
            $scheme = !empty($data['ssl_enabled']) ? 'https' : 'http';
            $this->printer->note("{$childPrefix}  ├─ 地址：{$scheme}://{$host}:{$port}");
            $portRangeStr = $dispatcherEnabled ? "Dispatcher:{$port}, Workers:{$workerPortBase}-" . ($workerPortBase + $count - 1) : "{$port} - " . ($port + $count - 1);
            $this->printer->note("{$childPrefix}  ├─ 端口范围：{$portRangeStr}");
            $this->printer->note("{$childPrefix}  ├─ 启动时间：{$startedAt}");
            
            // Worker 进程列表（树形展开）
            $this->printer->note("{$childPrefix}  └─ Workers:");
            
            for ($i = 0; $i < $count; $i++) {
                $workerPort = $workerPortBase + $i;
                $workerId = $i + 1;
                $isLastWorker = ($i === $count - 1);
                $workerPrefix = $isLastWorker ? '└─' : '├─';
                
                $isRunning = Processer::isPortInUse($workerPort);
                $workerStatus = $isRunning ? '● 运行中' : '○ 已停止';
                $workerColor = $isRunning ? 'success' : 'error';
                
                $this->printer->$workerColor("{$childPrefix}       {$workerPrefix} Worker #{$workerId} (:{$workerPort}) {$workerStatus}");
            }
            
            echo "\n";
            $index++;
        }

        $this->printer->note(__('使用 server:status <name> 查看详细状态'));
        $this->printer->note(__('使用 server:stop <name> 停止实例'));
    }
    
    /**
     * 显示单个实例状态
     */
    protected function showInstanceStatus(string $name): void
    {
        /** @var WlsInstanceRegistry $registry */
        $registry = ObjectManager::getInstance(WlsInstanceRegistry::class);
        $data = $registry->getInstanceData($name);

        if ($data === null) {
            $this->printer->warning(__('实例 [%{1}] 不存在', [$name]));
            echo "\n";
            $this->showAllInstances();
            return;
        }
        
        $port = (int)($data['port'] ?? Start::DEFAULT_PORT);
        $count = (int)($data['count'] ?? 4);
        $host = $data['host'] ?? '127.0.0.1';
        $startedAt = $data['started_at'] ?? 'unknown';
        $dispatcherEnabled = !empty($data['dispatcher_enabled']);
        $workerPortBase = (int)($data['worker_port'] ?? $port);
        $sslEnabled = !empty($data['ssl_enabled']);
        $masterPid = (int)($data['master_pid'] ?? 0);
        $masterRunning = $masterPid > 0 && Processer::processExists($masterPid);
        
        $this->printer->setup(__('实例 [%{1}] 状态', [$name]));
        echo "\n";
        
        $this->printer->note(__('╔══════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║                    实例详细信息                                ║'));
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $name));
        $scheme = $sslEnabled ? 'https' : 'http';
        $this->printer->note(\sprintf('║  监听地址：%-50s║', "{$scheme}://{$host}:{$port}"));
        $portRangeStr = $dispatcherEnabled
            ? ("Dispatcher:{$port}, Workers:{$workerPortBase}-" . ($workerPortBase + $count - 1))
            : "{$port} - " . ($port + $count - 1);
        $this->printer->note(\sprintf('║  端口范围：%-50s║', $portRangeStr));
        $this->printer->note(\sprintf('║  Worker 数：%-49s║', $count));
        $this->printer->note(\sprintf('║  启动时间：%-50s║', $startedAt));
        $masterPidStr = $masterPid > 0 ? (string)$masterPid : '-';
        $masterStatusStr = $masterRunning ? '● 运行中' : '○ 已停止';
        $this->printer->note(\sprintf('║  Master PID：%-47s║', $masterPidStr));
        $this->printer->note(\sprintf('║  Master 状态：%-46s║', $masterStatusStr));
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
        if ($masterRunning) {
            $this->showProcessMemory($masterPid, '  │  ');
        }
        
        // HTTP 重定向状态（若有）
        $httpRedirectPort = (int)($data['http_redirect_port'] ?? 0);
        if ($httpRedirectPort > 0) {
            $httpRedirectPid = (int)($data['http_redirect_pid'] ?? 0);
            $httpRedirectRunning = Processer::isPortInUse($httpRedirectPort);
            $icon = $httpRedirectRunning ? '●' : '○';
            $statusStr = $httpRedirectRunning ? __('运行中') : __('已停止');
            $color = $httpRedirectRunning ? 'success' : 'error';
            $this->printer->$color("  │");
            $this->printer->$color("  ├─ HTTP 重定向 (端口: {$httpRedirectPort}) {$icon} {$statusStr}");
        }
        
        // Dispatcher 状态（若有）
        if ($dispatcherEnabled) {
            $dispatcherPid = (int)($data['dispatcher_pid'] ?? 0);
            $dispatcherRunning = Processer::isPortInUse($port);
            $dIcon = $dispatcherRunning ? '●' : '○';
            $dStatus = $dispatcherRunning ? __('运行中') : __('已停止');
            $dColor = $dispatcherRunning ? 'success' : 'error';
            $protocol = $sslEnabled ? 'SSL' : 'TCP';
            $this->printer->$dColor("  │");
            $this->printer->$dColor("  ├─ Dispatcher (端口: {$port}, {$protocol}) {$dIcon} {$dStatus}");
            if ($dispatcherRunning && $dispatcherPid > 0) {
                $this->showProcessMemory($dispatcherPid, '  │     ');
            }
        }
        
        // Worker 进程列表（Dispatcher 模式下使用 worker_port 作为实际监听端口）
        $this->printer->note("  │");
        $this->printer->note(__('  └─ Workers:'));
        echo "\n";
        
        $runningCount = 0;
        
        for ($i = 0; $i < $count; $i++) {
            $workerPort = $workerPortBase + $i;
            $workerId = $i + 1;
            $isLast = ($i === $count - 1);
            $prefix = $isLast ? '└─' : '├─';
            
            $isRunning = Processer::isPortInUse($workerPort);
            
            if ($isRunning) {
                $runningCount++;
                $this->printer->success("  {$prefix} Worker #{$workerId} (端口: {$workerPort}) ● 运行中");
                
                // 显示内存占用（如果可以获取）
                $this->showWorkerMemory($workerPort, $isLast ? '   ' : '│  ');
            } else {
                $this->printer->error("  {$prefix} Worker #{$workerId} (端口: {$workerPort}) ○ 已停止");
            }
        }
        
        echo "\n";
        
        // 总结
        if ($runningCount === $count) {
            $this->printer->success(__('状态：全部运行中 (%{1}/%{2})', [$runningCount, $count]));
        } elseif ($runningCount > 0) {
            $this->printer->warning(__('状态：部分运行 (%{1}/%{2})', [$runningCount, $count]));
        } else {
            $this->printer->error(__('状态：全部停止 (%{1}/%{2})', [$runningCount, $count]));
        }
        
        echo "\n";
        $scheme = $sslEnabled ? 'https' : 'http';
        $this->printer->note(__('测试请求：curl %{1}://%{2}:%{3}/', [$scheme, $host, $port]));
        $this->printer->note(__('停止服务：php bin/w server:stop %{1}', [$name]));
    }
    
    /**
     * 显示 Worker 内存占用（通过端口查找 PID）
     */
    protected function showWorkerMemory(int $port, string $prefix): void
    {
        $isWindows = \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($isWindows) {
            // Windows: 通过端口找到 PID，然后获取内存
            $output = [];
            @\exec("netstat -ano | findstr :{$port} 2>NUL", $output);
            
            foreach ($output as $line) {
                if (\preg_match('/LISTENING\s+(\d+)$/', \trim($line), $matches)) {
                    $pid = (int) $matches[1];
                    $memOutput = [];
                    $psCmd = "powershell -NoProfile -Command \"(Get-Process -Id {$pid} -ErrorAction SilentlyContinue).WorkingSet64\" 2>NUL";
                    @\exec($psCmd, $memOutput);
                    
                    if (!empty($memOutput[0]) && \is_numeric(\trim($memOutput[0]))) {
                        $memory = (int) \trim($memOutput[0]);
                        $memoryMB = \round($memory / 1024 / 1024, 2);
                        $this->printer->note("  {$prefix}  └─ 内存：{$memoryMB} MB (PID: {$pid})");
                    }
                    break;
                }
            }
        } else {
            // Linux/Mac: 类似方式
            $output = [];
            @\exec("lsof -ti:{$port} 2>/dev/null", $output);
            
            if (!empty($output[0]) && \is_numeric(\trim($output[0]))) {
                $pid = (int) \trim($output[0]);
                $memOutput = [];
                @\exec("ps -p {$pid} -o rss= 2>/dev/null", $memOutput);
                
                if (!empty($memOutput[0])) {
                    $memoryKB = (int) \trim($memOutput[0]);
                    $memoryMB = \round($memoryKB / 1024, 2);
                    $this->printer->note("  {$prefix}  └─ 内存：{$memoryMB} MB (PID: {$pid})");
                }
            }
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
        
        $isWindows = \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($isWindows) {
            $memOutput = [];
            $psCmd = "powershell -NoProfile -Command \"(Get-Process -Id {$pid} -ErrorAction SilentlyContinue).WorkingSet64\" 2>NUL";
            @\exec($psCmd, $memOutput);
            
            if (!empty($memOutput[0]) && \is_numeric(\trim($memOutput[0]))) {
                $memory = (int) \trim($memOutput[0]);
                $memoryMB = \round($memory / 1024 / 1024, 2);
                $this->printer->note("{$prefix}└─ 内存：{$memoryMB} MB");
            }
        } else {
            $memOutput = [];
            @\exec("ps -p {$pid} -o rss= 2>/dev/null", $memOutput);
            
            if (!empty($memOutput[0])) {
                $memoryKB = (int) \trim($memOutput[0]);
                $memoryMB = \round($memoryKB / 1024, 2);
                $this->printer->note("{$prefix}└─ 内存：{$memoryMB} MB");
            }
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
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('查看所有实例') => 'php bin/w server:status',
                __('查看指定实例') => 'php bin/w server:status api-server',
            ]
        );
    }
}
