<?php
declare(strict_types=1);

/**
 * Weline Server - 多进程停止命令
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\System\Process\Processer;

/**
 * server:multi-stop - 停止多进程
 */
class MultiStop extends CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $basePort = (int) ($args['port'] ?? $args['p'] ?? 8080);
        $count = (int) ($args['count'] ?? $args['c'] ?? 4);
        
        $this->printer->setup(__('停止 Weline Server 多进程'));
        echo "\n";
        $this->printer->note(__('基础端口：%{1}', [$basePort]));
        $this->printer->note(__('进程数量：%{1}', [$count]));
        echo "\n";
        
        $stoppedCount = 0;
        $phpBinary = PHP_BINARY;
        $workerScript = BP . 'app/code/Weline/Server/bin/worker.php';
        
        for ($i = 0; $i < $count; $i++) {
            $port = $basePort + $i;
            $workerId = $i + 1;
            
            // 构建进程名
            $processName = "{$phpBinary} \"{$workerScript}\" 127.0.0.1 {$port} {$workerId} --name=weline-wls-worker-{$port}";
            
            // 尝试通过进程名停止
            if (Processer::running($processName)) {
                $pid = Processer::getPid($processName);
                Processer::destroy($processName);
                $this->printer->success(__('Worker #%{1} (PID: %{2}, 端口: %{3}) 已停止', [$workerId, $pid, $port]));
                $stoppedCount++;
                continue;
            }
            
            // 尝试通过端口停止
            if (Processer::isPortInUse($port)) {
                Processer::killProcessByPort($port);
                $this->printer->success(__('Worker #%{1} (端口: %{2}) 已停止', [$workerId, $port]));
                $stoppedCount++;
            } else {
                $this->printer->note(__('Worker #%{1} (端口: %{2}) 未运行', [$workerId, $port]));
            }
        }
        
        echo "\n";
        $this->printer->success(__('已停止 %{1}/%{2} 个进程', [$stoppedCount, $count]));
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('停止多进程 Weline Server');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:multi-stop',
            __('停止多个 Worker 进程'),
            [
                '-p, --port <port>' => __('基础端口（默认：8080）'),
                '-c, --count <n>' => __('进程数量（默认：4）'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('停止 4 个进程') => 'php bin/w server:multi-stop -p 8080 -c 4',
            ]
        );
    }
}
