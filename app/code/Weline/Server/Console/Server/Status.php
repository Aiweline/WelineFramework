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
use Weline\Framework\System\Process\Processer;
use Weline\Framework\App\Env;

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
        
        if ($showAll || $instanceName === 'default' && !$this->instanceExists('default')) {
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
        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $name . '.json';
        return \is_file($instanceFile);
    }
    
    /**
     * 显示所有实例
     */
    protected function showAllInstances(): void
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances' . DS;
        
        $this->printer->setup(__('Weline Server 状态'));
        echo "\n";
        
        if (!\is_dir($instanceDir)) {
            $this->printer->note(__('没有运行中的服务器实例'));
            echo "\n";
            $this->showStartTip();
            return;
        }
        
        $instances = \glob($instanceDir . '*.json');
        
        if (empty($instances)) {
            $this->printer->note(__('没有运行中的服务器实例'));
            echo "\n";
            $this->showStartTip();
            return;
        }
        
        $this->printer->note(__('╔══════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║                    服务器实例列表                              ║'));
        $this->printer->note(__('╚══════════════════════════════════════════════════════════════╝'));
        echo "\n";
        
        foreach ($instances as $index => $instanceFile) {
            $name = \basename($instanceFile, '.json');
            $data = \json_decode(\file_get_contents($instanceFile), true) ?: [];
            
            $isLast = ($index === \count($instances) - 1);
            $prefix = $isLast ? '└─' : '├─';
            $childPrefix = $isLast ? '   ' : '│  ';
            
            $port = $data['port'] ?? Start::DEFAULT_PORT;
            $count = $data['count'] ?? 4;
            $host = $data['host'] ?? '127.0.0.1';
            $startedAt = $data['started_at'] ?? 'unknown';
            
            // 检查有多少进程在运行
            $runningCount = 0;
            for ($i = 0; $i < $count; $i++) {
                if (Processer::isPortInUse($port + $i)) {
                    $runningCount++;
                }
            }
            
            $status = $runningCount === $count ? '● 运行中' : ($runningCount > 0 ? '◐ 部分运行' : '○ 已停止');
            $statusColor = $runningCount === $count ? 'success' : ($runningCount > 0 ? 'warning' : 'note');
            
            // 实例名称行
            $this->printer->$statusColor("{$prefix} [{$name}] {$status} ({$runningCount}/{$count} workers)");
            
            // 详细信息
            $this->printer->note("{$childPrefix}  ├─ 地址：http://{$host}:{$port}");
            $this->printer->note("{$childPrefix}  ├─ 端口范围：{$port} - " . ($port + $count - 1));
            $this->printer->note("{$childPrefix}  ├─ 启动时间：{$startedAt}");
            
            // Worker 进程列表（树形展开）
            $this->printer->note("{$childPrefix}  └─ Workers:");
            
            for ($i = 0; $i < $count; $i++) {
                $workerPort = $port + $i;
                $workerId = $i + 1;
                $isLastWorker = ($i === $count - 1);
                $workerPrefix = $isLastWorker ? '└─' : '├─';
                
                $isRunning = Processer::isPortInUse($workerPort);
                $workerStatus = $isRunning ? '● 运行中' : '○ 已停止';
                $workerColor = $isRunning ? 'success' : 'note';
                
                $this->printer->$workerColor("{$childPrefix}       {$workerPrefix} Worker #{$workerId} (:{$workerPort}) {$workerStatus}");
            }
            
            echo "\n";
        }
        
        $this->printer->note(__('使用 server:status <name> 查看详细状态'));
        $this->printer->note(__('使用 server:stop <name> 停止实例'));
    }
    
    /**
     * 显示单个实例状态
     */
    protected function showInstanceStatus(string $name): void
    {
        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $name . '.json';
        
        if (!\is_file($instanceFile)) {
            $this->printer->warning(__('实例 [%{1}] 不存在', [$name]));
            echo "\n";
            $this->showAllInstances();
            return;
        }
        
        $data = \json_decode(\file_get_contents($instanceFile), true) ?: [];
        
        $port = $data['port'] ?? Start::DEFAULT_PORT;
        $count = $data['count'] ?? 4;
        $host = $data['host'] ?? '127.0.0.1';
        $startedAt = $data['started_at'] ?? 'unknown';
        
        $this->printer->setup(__('实例 [%{1}] 状态', [$name]));
        echo "\n";
        
        $this->printer->note(__('╔══════════════════════════════════════════════════════════════╗'));
        $this->printer->note(__('║                    实例详细信息                                ║'));
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $name));
        $this->printer->note(\sprintf('║  监听地址：%-50s║', "http://{$host}:{$port}"));
        $this->printer->note(\sprintf('║  端口范围：%-50s║', "{$port} - " . ($port + $count - 1)));
        $this->printer->note(\sprintf('║  Worker 数：%-49s║', $count));
        $this->printer->note(\sprintf('║  启动时间：%-50s║', $startedAt));
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
        echo "\n";
        
        // Worker 进程列表
        $this->printer->note(__('Worker 进程状态：'));
        echo "\n";
        
        $runningCount = 0;
        
        for ($i = 0; $i < $count; $i++) {
            $workerPort = $port + $i;
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
                $this->printer->note("  {$prefix} Worker #{$workerId} (端口: {$workerPort}) ○ 已停止");
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
        $this->printer->note(__('测试请求：curl http://%{1}:%{2}/', [$host, $port]));
        $this->printer->note(__('停止服务：php bin/w server:stop %{1}', [$name]));
    }
    
    /**
     * 显示 Worker 内存占用
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
