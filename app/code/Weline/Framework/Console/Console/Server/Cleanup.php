<?php

namespace Weline\Framework\Console\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

class Cleanup implements CommandInterface
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
        
        $this->printer->note(__('开始清理服务器进程...'));
        echo "\n";
        
        // 显示当前配置
        if (!empty($serverConfig)) {
            $configData = [];
            if ($host) $configData[] = ['主机', $host];
            if ($port) $configData[] = ['端口', $port];
            if ($pid) $configData[] = ['进程ID', $pid];
            
            if (!empty($configData)) {
                $this->printTable('当前服务器配置', $configData);
                echo "\n";
            }
        }
        
        // 检查配置中的进程
        if ($pid && $this->isProcessRunning($pid)) {
            if ($this->stopProcess($pid)) {
                $this->printer->success(__('已停止配置中的服务器进程，进程ID：%{1}', [$pid]));
            } else {
                $this->printer->error(__('停止配置中的服务器进程失败，进程ID：%{1}', [$pid]));
            }
        }
        
        // 检查端口占用
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if (is_resource($connection)) {
            fclose($connection);
            
            // 获取占用端口的进程ID
            $actualPid = $this->getProcessIdByPort($port);
            
            if ($actualPid && $actualPid != $pid) {
                if ($this->stopProcess($actualPid)) {
                    $this->printer->success(__('已停止占用端口的服务器进程，进程ID：%{1}', [$actualPid]));
                } else {
                    $this->printer->error(__('停止占用端口的服务器进程失败，进程ID：%{1}', [$actualPid]));
                }
            }
        }
        
        // 清理配置
        $this->clearServerConfig();
        $this->printer->success(__('已清理服务器配置信息。'));
        
        echo "\n";
        $this->printBox('清理完成', '服务器进程和配置已清理完毕', 'success');
        echo "\n";
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
     * 停止进程
     */
    private function stopProcess(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, SIGTERM);
        } else {
            // Windows系统
            $output = [];
            exec("taskkill /PID {$pid} /F 2>NUL", $output, $returnCode);
            return $returnCode === 0;
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
     * 清理服务器配置
     */
    private function clearServerConfig(): void
    {
        $env = Env::getInstance();
        $env->setConfig('server', null);
        $env->save();
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('清理残留的服务器进程和配置信息。');
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
