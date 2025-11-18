<?php

namespace Weline\Framework\Console\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;

class Status implements CommandInterface
{
    use TablePrinter;
    
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
        if ($connection !== false) {
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
            echo "\n";
            
            // 准备数据
            $runningTimeStr = '-';
            if ($startTime) {
                $runningTime = time() - $startTime;
                $hours = floor($runningTime / 3600);
                $minutes = floor(($runningTime % 3600) / 60);
                $seconds = $runningTime % 60;
                $runningTimeStr = sprintf('%d小时%d分钟%d秒', $hours, $minutes, $seconds);
            }
            
            // 服务器基本信息表格
            $this->printTable('服务器信息', [
                ['进程ID', $pid],
                ['监听地址', "{$host}:{$port}"],
                ['运行时间', $runningTimeStr],
            ]);
            
            echo "\n";
            
            // 访问地址表格
            $this->printTable('访问地址', [
                ['前端首页', "http://{$host}:{$port}/"],
                ['前端API', "http://{$host}:{$port}/api/rest"],
                ['后端管理', "http://{$host}:{$port}/" . Env::get('admin') . "/admin/login"],
                ['后端API', "http://{$host}:{$port}/" . Env::get('api_admin') . "/rest"],
            ], true, 0, false); // false 表示不截断URL，完整显示地址
            
            echo "\n";
        }
    }
    

    /**
     * 检查进程是否正在运行
     */
    private function isProcessRunning(int $pid): bool
    {
        return Processer::isRunningByPid($pid);
    }

    /**
     * 通过端口获取进程ID
     */
    private function getProcessIdByPort(int $port): ?int
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows系统
            @exec('netstat -ano | findstr ":' . $port . '"', $output);
            
            if (!empty($output)) {
                foreach ($output as $line) {
                    if (preg_match('/LISTENING\s+(\d+)/', $line, $matches)) {
                        $pid = (int)$matches[1];
                        if ($pid > 0) {
                            return $pid;
                        }
                    }
                }
            }
        } else {
            // Unix/Linux系统
            $output = [];
            @exec("lsof -ti:{$port} 2>/dev/null", $output);
            
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
