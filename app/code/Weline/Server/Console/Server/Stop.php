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

        // 先停止该实例的 Master 进程（如果存在）
        $this->stopMasterProcess($name);

        $httpRedirectPort = (int)($instanceData['http_redirect_port'] ?? 0);

        $sslEnabled = !empty($instanceData['ssl_enabled']);
        
        $this->printer->note(__('停止子进程...'));
        echo "\n";
        $stoppedCount = 0;
        $killedPids = [];

        // 1) 按进程名前缀批量停止该实例下所有 Worker（weline-master-{name}-worker-*）
        $workerPrefix = 'weline-master-' . $name . '-';
        $batchKilled = Processer::killByProcessNamePrefix($workerPrefix);
        if ($batchKilled > 0) {
            $stoppedCount += $batchKilled;
            $this->printer->success(__('  ├─ Worker 进程：已批量停止 %{1} 个 ✓', [$batchKilled]));
        } else {
            // 检查 Worker 端口是否有进程在运行
            $runningWorkers = 0;
            for ($i = 0; $i < $count; $i++) {
                $wPort = $workerPortBase + $i;
                if (Processer::isPortInUse($wPort)) {
                    $runningWorkers++;
                    $pidByPort = Processer::getPidByPort($wPort);
                    if ($pidByPort > 0 && Processer::isProcessManagerCreated($pidByPort) && !isset($killedPids[$pidByPort])) {
                        Processer::killByPid($pidByPort);
                        $killedPids[$pidByPort] = true;
                        $stoppedCount++;
                    }
                }
            }
            if ($runningWorkers > 0) {
                $this->printer->success(__('  ├─ Worker 进程：已按端口停止 %{1} 个 ✓', [$runningWorkers]));
            } else {
                $this->printer->note(__('  ├─ Worker 进程 (%{1} 个)：已随 Master 退出', [$count]));
            }
        }

        // 2) 停止 Dispatcher（用进程名杀死）
        if ($dispatcherEnabled) {
            // 统一进程名
            $dispatcherName = 'weline-dispatcher-' . $name;
            
            // 用进程名获取 PID 并杀死（最准确的方式）
            $dispatcherPid = (int) Processer::getData('--name=' . $dispatcherName, 'pid');
            if ($dispatcherPid > 0 && Processer::isRunningByPid($dispatcherPid)) {
                Processer::killByPid($dispatcherPid);
                $killedPids[$dispatcherPid] = true;
                $stoppedCount++;
                $this->printer->success(__('  ├─ Dispatcher (端口: %{1}) - 已停止 ✓', [$port]));
            } else {
                // 进程已不存在，说明已随 Master 退出
                $this->printer->note(__('  ├─ Dispatcher (端口: %{1}) - 已随 Master 退出', [$port]));
            }
            Processer::removePidFile('--name=' . $dispatcherName);
        }

        // 3) 停止 HTTP 重定向进程（HTTPS 模式专用，用进程名杀死）
        if ($sslEnabled) {
            // 如果实例文件没有记录端口，智能计算（仅用于显示）
            if ($httpRedirectPort <= 0) {
                $httpRedirectPort = $port - 463;
                if ($httpRedirectPort <= 0 || $httpRedirectPort > 65535) {
                    $httpRedirectPort = 80;
                }
            }
            $redirectName = MasterProcess::HTTP_REDIRECT_PROCESS_NAME;
            // 用进程名获取 PID 并杀死（最准确的方式）
            $redirectPid = (int) Processer::getData('--name=' . $redirectName, 'pid');
            if ($redirectPid > 0 && Processer::isRunningByPid($redirectPid)) {
                Processer::killByPid($redirectPid);
                $killedPids[$redirectPid] = true;
                $stoppedCount++;
                $this->printer->success(__('  ├─ HTTP 重定向 (端口: %{1}) - 已停止 ✓', [$httpRedirectPort]));
            } else {
                // 进程已不存在，说明已随 Master 退出
                $this->printer->note(__('  ├─ HTTP 重定向 (端口: %{1}) - 已随 Master 退出', [$httpRedirectPort]));
            }
            Processer::removePidFile('--name=' . $redirectName);
        }

        // 4) 按实例文件中的 PID：仍在运行的也结束（避免漏掉残留进程）
        $pidsFromFile = [];
        if (!empty($instanceData['dispatcher_pid'])) {
            $pidsFromFile[(int)$instanceData['dispatcher_pid']] = true;
        }
        if (!empty($instanceData['workers']) && \is_array($instanceData['workers'])) {
            foreach ($instanceData['workers'] as $w) {
                $pid = (int)($w['pid'] ?? 0);
                if ($pid > 0) {
                    $pidsFromFile[$pid] = true;
                }
            }
        }
        if (!empty($instanceData['worker_pids']) && \is_array($instanceData['worker_pids'])) {
            foreach ($instanceData['worker_pids'] as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) {
                    $pidsFromFile[$pid] = true;
                }
            }
        }
        if ($httpRedirectPort > 0 && !empty($instanceData['http_redirect_pid'])) {
            $pidsFromFile[(int)$instanceData['http_redirect_pid']] = true;
        }
        foreach (\array_keys($pidsFromFile) as $pid) {
            if ($pid <= 0 || isset($killedPids[$pid])) {
                continue;
            }
            if (Processer::isRunningByPid($pid)) {
                Processer::killByPid($pid);
                $killedPids[$pid] = true;
                $stoppedCount++;
                $this->printer->success(__('  ├─ 进程 PID %{1} - 已停止 ✓', [$pid]));
            }
        }

        // 5) 清理本实例所有进程的 PID 文件
        Processer::removePidFile('--name=weline-dispatcher-' . $name);
        for ($i = 1; $i <= $count; $i++) {
            Processer::removePidFile('--name=weline-master-' . $name . '-worker-' . $i);
        }
        if ($sslEnabled) {
            Processer::removePidFile('--name=' . MasterProcess::HTTP_REDIRECT_PROCESS_NAME);
        }

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
