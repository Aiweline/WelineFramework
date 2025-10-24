<?php

namespace Weline\Framework\Console\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

class Status implements CommandInterface
{
    function __construct(
        private Printing $printer
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $env = Env::getInstance();
        $serverConfig = $env->get('server') ?? [];
        
        $host = $serverConfig['host'] ?? '127.0.0.1';
        $port = $serverConfig['port'] ?? 9981;
        $pid = $serverConfig['pid'] ?? null;
        $startTime = $serverConfig['start_time'] ?? null;
        $status = $serverConfig['status'] ?? 'unknown';
        
        // 首先检查配置中的进程ID
        if ($pid && $this->isProcessRunning($pid)) {
            $this->displayServerStatus($host, $port, $pid, $startTime, true);
            return;
        }
        
        // 如果配置中的进程ID无效，检查端口是否被占用
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if (is_resource($connection)) {
            fclose($connection);
            
            // 尝试获取占用端口的进程ID
            $actualPid = $this->getProcessIdByPort($port);
            
            if ($actualPid) {
                $this->displayServerStatus($host, $port, $actualPid, $startTime, true);
                $this->printer->warning(__('注意：检测到服务器运行，但配置信息可能不完整。'));
                
                // 自动更新配置信息
                $this->updateServerConfig($host, $port, $actualPid);
                $this->printer->note(__('已自动更新配置信息。'));
            } else {
                $this->printer->warning(__('端口 %{1}:%{2} 被占用，但无法确定进程ID。', [$host, $port]));
            }
            return;
        }
        
        // 服务器未运行
        if (empty($serverConfig)) {
            $this->printer->warning(__('服务器未运行。'));
        } else {
            $this->printer->error(__('服务器进程不存在'));
            if ($pid) {
                $this->printer->note(__('配置的进程ID：%{1}', [$pid]));
            }
            $this->printer->note(__('配置的监听地址：%{1}:%{2}', [$host, $port]));
            $this->printer->warning(__('建议清理配置信息：php bin/w server:stop'));
        }
    }

    /**
     * 显示服务器状态信息
     */
    private function displayServerStatus(string $host, int $port, int $pid, ?int $startTime, bool $isRunning): void
    {
        if ($isRunning) {
            $this->printer->success(__('服务器正在运行'));
            $this->printer->note(__('进程ID：%{1}', [$pid]));
            $this->printer->note(__('监听地址：%{1}:%{2}', [$host, $port]));
            
            if ($startTime) {
                $runningTime = time() - $startTime;
                $hours = floor($runningTime / 3600);
                $minutes = floor(($runningTime % 3600) / 60);
                $seconds = $runningTime % 60;
                $this->printer->note(__('运行时间：%{1}小时%{2}分钟%{3}秒', [$hours, $minutes, $seconds]));
            }
            
            $this->printer->note(__('后端地址：http://%{1}:%{2}/%{3}/admin/login', [$host, $port, Env::get('admin')]));
            $this->printer->note(__('后端API地址：http://%{1}:%{2}/%{3}/rest', [$host, $port, Env::get('api_admin')]));
        }
    }

    /**
     * 检查进程是否正在运行
     */
    private function isProcessRunning(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        } else {
            // Windows系统检查
            $output = [];
            exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
            return !empty($output) && count($output) > 1;
        }
    }

    /**
     * 通过端口获取进程ID
     */
    private function getProcessIdByPort(int $port): ?int
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows系统
            $output = [];
            exec("netstat -ano | findstr :{$port}", $output);
            
            foreach ($output as $line) {
                if (strpos($line, 'LISTENING') !== false) {
                    $parts = preg_split('/\s+/', trim($line));
                    if (count($parts) >= 5) {
                        $pid = (int)$parts[count($parts) - 1];
                        if ($pid > 0) {
                            return $pid;
                        }
                    }
                }
            }
        } else {
            // Unix/Linux系统
            $output = [];
            exec("lsof -ti:{$port}", $output);
            
            if (!empty($output)) {
                $pid = (int)trim($output[0]);
                if ($pid > 0) {
                    return $pid;
                }
            }
        }
        
        return null;
    }

    /**
     * 更新服务器配置信息
     */
    private function updateServerConfig(string $host, int $port, int $pid): void
    {
        $env = Env::getInstance();
        $serverConfig = $env->get('server') ?? [];
        
        $serverConfig['host'] = $host;
        $serverConfig['port'] = $port;
        $serverConfig['pid'] = $pid;
        $serverConfig['start_time'] = time();
        $serverConfig['status'] = 'running';
        
        $env->set('server', $serverConfig);
        $env->save();
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('查看PHP内置本地WebServer服务状态。');
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}
