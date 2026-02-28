<?php
declare(strict_types=1);

/**
 * Weline Server - 停止命令
 * 
 * 支持按实例名称停止服务器，树形显示停止的子进程
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Console\Console\Server\Stop as CliStop;
use Weline\Server\Service\CliServerService;
use Weline\Server\Service\MasterProcess;

/**
 * server:stop - 停止常驻内存服务器
 */
class Stop extends CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 解析参数
        $instanceName = $this->parseInstanceName($args);
        $stopAll = isset($args['all']) || isset($args['a']);
        $force = isset($args['force']) || isset($args['f']);
        
        if ($stopAll) {
            $this->stopAllInstances($force);
            return;
        }
        
        $this->stopInstance($instanceName, $force);
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
     * 按端口查找占用该端口的 Weline Server 实例名（端口落在实例的 port ~ port+count-1 内）
     */
    public function findWelineServerInstanceNameByPort(int $port): ?string
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        if (!\is_dir($instanceDir)) {
            return null;
        }
        $files = \glob($instanceDir . '*.json');
        foreach ($files as $file) {
            $name = \basename($file, '.json');
            $data = \json_decode(\file_get_contents($file), true);
            if (!\is_array($data)) {
                continue;
            }
            $instancePort = (int) ($data['port'] ?? 0);
            $count = (int) ($data['count'] ?? 4);
            if ($instancePort <= $port && $port < $instancePort + $count) {
                return $name;
            }
            $httpRedirectPort = (int) ($data['http_redirect_port'] ?? 0);
            if ($httpRedirectPort > 0 && $port === $httpRedirectPort) {
                return $name;
            }
        }
        return null;
    }

    /**
     * 若指定端口被 Weline Server 占用则停止该实例（同端口只能存在一个服务器，供 CLI 启动前调用）
     *
     * @return bool 是否停止了某个 WLS 实例
     */
    public function stopWelineServerOnPort(int $port): bool
    {
        $name = $this->findWelineServerInstanceNameByPort($port);
        if ($name === null) {
            return false;
        }
        $this->stopInstance($name, true);
        return true;
    }
    
    /**
     * 停止单个实例（含 cli/cli-server 委托给 PHP 内置服务器停止逻辑）
     */
    protected function stopInstance(string $name, bool $force = false): void
    {
        $nameLower = strtolower($name);
        if ($nameLower === 'cli' || $nameLower === 'cli-server') {
            $this->stopCliServer($force);
            return;
        }

        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $name . '.json';
        
        if (!\is_file($instanceFile)) {
            $this->printer->warning(__('实例 [%{1}] 不存在', [$name]));
            $this->printer->note(__('使用 server:listing 查看所有实例'));
            return;
        }
        
        $instanceData = \json_decode(\file_get_contents($instanceFile), true) ?: [];
        $port = $instanceData['port'] ?? Start::DEFAULT_PORT;
        $count = $instanceData['count'] ?? 4;
        $host = $instanceData['host'] ?? '127.0.0.1';
        $dispatcherEnabled = !empty($instanceData['dispatcher_enabled']);
        $workerPortBase = (int)($instanceData['worker_port'] ?? $port);
        
        $this->printer->setup(__('停止 Weline Server'));
        echo "\n";
        
        $this->printer->note(__('╔══════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║                   停止服务器实例                               ║'));
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $name));
        $portRange = $dispatcherEnabled ? ($workerPortBase . ' - ' . ($workerPortBase + $count - 1) . ' (Worker)') : "{$port} - " . ($port + $count - 1);
        $this->printer->note(\sprintf('║  端口范围：%-50s║', $portRange));
        $this->printer->note(\sprintf('║  进程数量：%-50s║', $count));
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
        echo "\n";

        // 先停止该实例的 Master 进程（Master 通过 IPC 广播 shutdown，子进程收后不复活）
        $this->stopMasterProcess($name);

        $sslEnabled = !empty($instanceData['ssl_enabled']);
        $httpRedirectPort = (int)($instanceData['http_redirect_port'] ?? 0);
        if ($sslEnabled && $httpRedirectPort <= 0) {
            $httpRedirectPort = $port - 463;
            if ($httpRedirectPort <= 0 || $httpRedirectPort > 65535) {
                $httpRedirectPort = 80;
            }
        }

        $this->printer->note(__('停止子进程...'));
        echo "\n";
        
        // 使用批量杀死策略
        $stoppedCount = $this->batchKillChildProcesses(
            $name,
            $count,
            $workerPortBase,
            $port,
            $dispatcherEnabled,
            $sslEnabled,
            $httpRedirectPort,
            $instanceData
        );

        echo "\n";

        // 删除实例文件
        @\unlink($instanceFile);

        // 清理日志文件
        for ($i = 0; $i < $count; $i++) {
            $workerPort = $workerPortBase + $i;
            $logFile = Env::VAR_DIR . 'log' . DS . "worker-{$workerPort}.log";
            if (\is_file($logFile)) {
                @\unlink($logFile);
            }
        }
        $dispatcherLogFile = Env::VAR_DIR . 'log' . DS . "dispatcher-{$name}.log";
        if (\is_file($dispatcherLogFile)) {
            @\unlink($dispatcherLogFile);
        }
        if ($sslEnabled && $httpRedirectPort > 0) {
            $logFile = Env::VAR_DIR . 'log' . DS . "http_redirect-{$httpRedirectPort}.log";
            if (\is_file($logFile)) {
                @\unlink($logFile);
            }
        }

        $staleRemoved = Processer::cleanupStalePidFiles();
        if ($staleRemoved > 0) {
            $this->printer->note(__('  （已清理 %{1} 个残留 PID 映射文件）', [$staleRemoved]));
        }

        $this->printer->success(__('实例 [%{1}] 已停止 ✓', [$name]));
    }
    
    /**
     * 批量杀死子进程
     * 
     * 核心思路：
     * 1. 收集所有需要清理的进程 PID
     * 2. 批量发送 kill 信号（而不是逐个等待）
     * 3. 统一等待一次
     * 4. 验证并清理残留
     * 
     * @return int 成功停止的进程数量
     */
    protected function batchKillChildProcesses(
        string $name,
        int $count,
        int $workerPortBase,
        int $dispatcherPort,
        bool $dispatcherEnabled,
        bool $sslEnabled,
        int $httpRedirectPort,
        array $instanceData
    ): int {
        $workerPrefix = 'weline-master-' . $name . '-';
        $dispatcherName = 'weline-dispatcher-' . $name;
        $redirectName = MasterProcess::HTTP_REDIRECT_PROCESS_NAME;
        $masterPrefix = MasterProcess::MASTER_PROCESS_NAME_PREFIX . $name;
        
        // ========== 阶段 1：收集所有需要清理的进程 PID ==========
        $processesToKill = []; // ['label' => string, 'pid' => int, 'port' => int, 'processName' => string]
        
        // 收集 Worker 进程
        for ($i = 0; $i < $count; $i++) {
            $workerId = $i + 1;
            $wPort = $workerPortBase + $i;
            $processName = '--name=weline-master-' . $name . '-worker-' . $workerId;
            
            // 尝试从进程名获取 PID
            $pid = (int) Processer::getData($processName, 'pid');
            
            // 如果进程名无 PID，尝试从端口获取
            if ($pid <= 0 && Processer::isPortInUse($wPort)) {
                $pid = Processer::getPidByPort($wPort);
            }
            
            // 从实例数据获取
            if ($pid <= 0) {
                $workers = $instanceData['workers'] ?? [];
                foreach ($workers as $w) {
                    if ((int)($w['port'] ?? 0) === $wPort) {
                        $pid = (int)($w['pid'] ?? 0);
                        break;
                    }
                }
            }
            
            $stillRunning = ($pid > 0 && Processer::isRunningByPid($pid)) 
                || Processer::isPortInUse($wPort);
            
            if ($stillRunning && $pid > 0) {
                $processesToKill[] = [
                    'label' => "Worker #{$workerId}",
                    'pid' => $pid,
                    'port' => $wPort,
                    'processName' => $processName,
                ];
            }
        }
        
        // 收集 Dispatcher 进程
        if ($dispatcherEnabled) {
            $dProcessName = '--name=' . $dispatcherName;
            $dispatcherPid = (int) Processer::getData($dProcessName, 'pid');
            
            if ($dispatcherPid <= 0 && !empty($instanceData['dispatcher_pid'])) {
                $dispatcherPid = (int) $instanceData['dispatcher_pid'];
            }
            
            if ($dispatcherPid <= 0 && Processer::isPortInUse($dispatcherPort)) {
                $dispatcherPid = Processer::getPidByPort($dispatcherPort);
            }
            
            $stillRunning = ($dispatcherPid > 0 && Processer::isRunningByPid($dispatcherPid))
                || Processer::isPortInUse($dispatcherPort);
            
            if ($stillRunning && $dispatcherPid > 0) {
                $processesToKill[] = [
                    'label' => 'Dispatcher',
                    'pid' => $dispatcherPid,
                    'port' => $dispatcherPort,
                    'processName' => $dProcessName,
                ];
            }
        }
        
        // 收集 HTTP 重定向进程
        if ($sslEnabled && $httpRedirectPort > 0) {
            $rProcessName = '--name=' . $redirectName;
            $redirectPid = (int) Processer::getData($rProcessName, 'pid');
            
            if ($redirectPid <= 0 && !empty($instanceData['http_redirect_pid'])) {
                $redirectPid = (int) $instanceData['http_redirect_pid'];
            }
            
            if ($redirectPid <= 0 && Processer::isPortInUse($httpRedirectPort)) {
                $redirectPid = Processer::getPidByPort($httpRedirectPort);
            }
            
            $stillRunning = ($redirectPid > 0 && Processer::isRunningByPid($redirectPid))
                || Processer::isPortInUse($httpRedirectPort);
            
            if ($stillRunning && $redirectPid > 0) {
                $processesToKill[] = [
                    'label' => 'HTTP Redirect',
                    'pid' => $redirectPid,
                    'port' => $httpRedirectPort,
                    'processName' => $rProcessName,
                ];
            }
        }
        
        // 如果没有需要清理的进程，先尝试按前缀批量清理
        if (empty($processesToKill)) {
            $prefixKilled = Processer::killByProcessNamePrefix($workerPrefix);
            if ($prefixKilled > 0) {
                $this->printer->success(__('  ├─ 按前缀清理 %{1} 个 Worker 进程 ✓', [$prefixKilled]));
                return $prefixKilled;
            }
            $this->printer->success(__('所有子进程已停止 ✓'));
            return 0;
        }
        
        // ========== 阶段 2：批量发送 kill 信号 ==========
        $labels = [];
        $pidsToWait = [];
        $isWin = \defined('IS_WIN') && IS_WIN;
        
        foreach ($processesToKill as $proc) {
            $pid = $proc['pid'];
            $label = $proc['label'];
            $port = $proc['port'];
            $labels[] = "{$label}(PID:{$pid})";
            $pidsToWait[$pid] = ['label' => $label, 'port' => $port, 'processName' => $proc['processName']];
            
            // Mac/Linux: 使用 posix_kill 发送 SIGTERM
            if (!$isWin) {
                if (\function_exists('posix_kill')) {
                    @\posix_kill($pid, \SIGTERM);
                }
            } else {
                // Windows: 使用 taskkill（不等待）
                @\exec("taskkill /PID {$pid} /F 2>NUL", $output, $code);
            }
        }
        
        $this->printer->note(__('批量发送终止信号: %{1}', [\implode(', ', $labels)]));
        
        // ========== 阶段 3：统一等待进程退出 ==========
        $waitTimeout = 3; // 批量杀死后的等待时间（秒）
        $startTime = \time();
        
        while (!empty($pidsToWait) && (\time() - $startTime) < $waitTimeout) {
            \usleep(200000); // 200ms
            
            foreach ($pidsToWait as $pid => $info) {
                if (!Processer::isRunningByPid($pid)) {
                    $this->printer->success(__('  ├─ %{1} 已停止 (端口: %{2}) ✓', [$info['label'], $info['port']]));
                    unset($pidsToWait[$pid]);
                }
            }
        }
        
        $stoppedCount = \count($processesToKill) - \count($pidsToWait);
        
        // ========== 阶段 4：强制清理残留进程 ==========
        if (!empty($pidsToWait)) {
            $this->printer->warning(__('部分进程未响应 SIGTERM，批量发送 SIGKILL...'));
            
            // 批量发送 SIGKILL
            foreach ($pidsToWait as $pid => $info) {
                if (!$isWin) {
                    if (\function_exists('posix_kill')) {
                        @\posix_kill($pid, \SIGKILL);
                    }
                }
                Processer::killProcessTreeByPid($pid, true);
            }
            
            // 等待强制杀死生效
            \usleep(500000); // 500ms
            
            // 验证是否已停止
            foreach ($pidsToWait as $pid => $info) {
                if (!Processer::isRunningByPid($pid)) {
                    $this->printer->success(__('  ├─ %{1} 已强制停止 (PID: %{2}) ✓', [$info['label'], $pid]));
                    $stoppedCount++;
                    unset($pidsToWait[$pid]);
                }
            }
        }
        
        // ========== 阶段 5：按端口最终清理 ==========
        $stillRunning = [];
        foreach ($processesToKill as $proc) {
            $port = $proc['port'];
            if ($port > 0 && Processer::isPortInUse($port)) {
                $stillRunning[] = "{$proc['label']} (端口: {$port})";
                Processer::killProcessByPort($port);
            }
        }
        
        if (!empty($stillRunning)) {
            $this->printer->warning(__('按端口清理: %{1}', [\implode(', ', $stillRunning)]));
        }
        
        // ========== 阶段 6：逃逸进程扫杀（按前缀） ==========
        $allPrefixes = [
            $masterPrefix,
            $workerPrefix,
            $dispatcherName,
            $redirectName,
        ];
        $sysKilled = 0;
        foreach ($allPrefixes as $pfx) {
            $sysKilled += Processer::killByProcessNamePrefix($pfx);
        }
        if ($sysKilled > 0) {
            $this->printer->note(__('  系统级扫杀：额外清理 %{1} 个残留进程', [$sysKilled]));
            $stoppedCount += $sysKilled;
        }
        
        // ========== 阶段 7：清理 PID 文件 ==========
        foreach ($processesToKill as $proc) {
            if (!empty($proc['processName'])) {
                Processer::removePidFile($proc['processName']);
            }
        }
        Processer::removePidFile('--name=' . $dispatcherName);
        Processer::removePidFile('--name=' . MasterProcess::getMasterProcessName($name));
        for ($i = 1; $i <= $count; $i++) {
            Processer::removePidFile('--name=weline-master-' . $name . '-worker-' . $i);
        }
        if ($sslEnabled) {
            Processer::removePidFile('--name=' . MasterProcess::HTTP_REDIRECT_PROCESS_NAME);
        }
        
        $this->printer->success(__('批量停止完成，共处理 %{1} 个进程', [$stoppedCount]));
        
        return $stoppedCount;
    }
    
    /**
     * 停止指定实例的 Master 进程（如果存在）
     */
    protected function stopMasterProcess(string $instanceName = 'default'): void
    {
        $masterInfo = MasterProcess::getMasterInfo($instanceName);
        if (!$masterInfo || empty($masterInfo['master_pid'])) {
            return;
        }
        
        $masterPid = (int)$masterInfo['master_pid'];
        if (!Processer::processExists($masterPid)) {
            return;
        }
        
        $this->printer->note(__('停止 Master 进程 (PID: %{1})...', [$masterPid]));
        
        // 通过 IPC 控制通道发送停止命令
        MasterProcess::sendStopCommand($instanceName);
        
        // 等待 Master 退出
        $maxWait = 10;
        for ($i = 0; $i < $maxWait; $i++) {
            \sleep(1);
            if (!Processer::processExists($masterPid)) {
                $this->printer->success(__('  └─ Master 进程已停止 ✓'));
                echo "\n";
                return;
            }
        }
        
        // 强制终止（统一委托 Processer 驱动）
        Processer::killByPid($masterPid, true);
        
        $this->printer->warning(__('  └─ Master 进程已强制终止'));
        echo "\n";
    }
    
    /**
     * 停止所有实例（含 Weline Server 与 PHP 内置 CLI 服务器）
     */
    protected function stopAllInstances(bool $force = false): void
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        $instances = \is_dir($instanceDir) ? \glob($instanceDir . '*.json') : [];
        $cliService = ObjectManager::getInstance(CliServerService::class);
        $cliStatus = $cliService->getCliServerStatus();

        if (empty($instances) && !$cliStatus) {
            $this->printer->warning(__('没有正在运行的实例'));
            return;
        }

        $this->printer->setup(__('停止所有服务器实例'));
        echo "\n";

        if (!empty($instances)) {
            $totalInstances = \count($instances);
            $this->printer->note(__('发现 %{1} 个 Weline Server 实例', [$totalInstances]));
            echo "\n";
            foreach ($instances as $instanceFile) {
                $name = \basename($instanceFile, '.json');
                $this->printer->note(__('正在停止实例 [%{1}]...', [$name]));
                $this->stopInstance($name, $force);
                echo "\n";
            }
            $this->printer->success(__('所有 Weline Server 实例已停止'));
        }

        if ($cliStatus) {
            echo "\n";
            $this->printer->note(__('正在停止 PHP 内置服务器 (cli-server)...'));
            $this->stopCliServer($force);
            $this->printer->success(__('PHP 内置服务器已停止'));
        }
    }

    /**
     * 停止 PHP 内置 CLI 服务器（委托给 Console\Console\Server\Stop）
     */
    protected function stopCliServer(bool $force = false): void
    {
        $args = $force ? ['force' => true, 'f' => true] : [];
        $cliStop = ObjectManager::getInstance(CliStop::class);
        $cliStop->execute($args, []);
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('停止 Weline Server 或 PHP 内置服务器实例');
    }
    
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:stop [name]',
            __('停止正在运行的 Weline Server 或 PHP 内置服务器实例'),
            [
                '[name]' => __('实例名称（默认：default；cli/cli-server 表示 PHP 内置服务器）'),
                '-a, --all' => __('停止所有运行中的实例（含 Weline Server 与 CLI 服务器）'),
                '-f, --force' => __('强制停止'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('停止默认实例') => 'php bin/w server:stop',
                __('停止指定实例') => 'php bin/w server:stop api-server',
                __('停止 PHP 内置服务器') => 'php bin/w server:stop cli-server',
                __('停止所有实例') => 'php bin/w server:stop --all',
                __('强制停止') => 'php bin/w server:stop -f',
            ]
        );
    }
}
