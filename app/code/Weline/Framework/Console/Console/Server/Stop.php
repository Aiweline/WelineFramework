<?php

namespace Weline\Framework\Console\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Stop implements CommandInterface
{
    private array $stoppedPids = [];

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
        $force = $args['force'] ?? $args['f'] ?? false;
        
        $env = Env::getInstance();
        $serverConfig = $env->get('server') ?? [];
        
        $host = $serverConfig['host'] ?? '127.0.0.1';
        $port = $serverConfig['port'] ?? 9981;
        $pid = $serverConfig['pid'] ?? null;
        
        // 首先检查配置中的进程ID
        if ($pid && $this->isProcessRunning($pid)) {
            $this->printer->note(__('正在停止配置中的服务器进程，进程ID：%{1}', [$pid]));
            
            if ($this->stopProcess($pid, $force)) {
                $this->printer->success(__('配置中的服务器进程已停止！进程ID：%{1}', [$pid]));
                $this->stoppedPids[] = $pid;
            } else {
                $this->printer->error(__('停止配置中的服务器进程失败！进程ID：%{1}', [$pid]));
                if ($force) {
                    $this->printer->note(__('强制停止失败，请尝试手动停止进程。'));
                } else {
                    $this->printer->note(__('请尝试使用 -f 参数强制停止或手动停止进程。'));
                }
            }
        }
        
        // 总是检查端口是否被占用，确保所有相关进程都被停止
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if (is_resource($connection)) {
            fclose($connection);
            
            // 尝试获取占用端口的进程ID
            $actualPid = $this->getProcessIdByPort($port);
            
            if ($actualPid) {
                $this->printer->note(__('通过端口检测到进程，正在停止，进程ID：%{1}', [$actualPid]));
                
                if ($this->stopProcess($actualPid, $force)) {
                    $this->printer->success(__('端口监听进程已停止！进程ID：%{1}', [$actualPid]));
                    $this->stoppedPids[] = $actualPid;
                } else {
                    $this->printer->error(__('停止端口监听进程失败！进程ID：%{1}', [$actualPid]));
                    if ($force) {
                        $this->printer->note(__('强制停止失败，请尝试手动停止进程。'));
                    } else {
                        $this->printer->note(__('请尝试使用 -f 参数强制停止或手动停止进程。'));
                    }
                }
            } else {
                $this->printer->warning(__('端口 %{1}:%{2} 被占用，但无法确定进程ID。', [$host, $port]));
                $this->printer->note(__('请手动检查端口占用情况。'));
            }
        }
        
        // 触发服务器停止事件
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $eventData = [
            'host' => $host,
            'port' => $port,
            'force' => $force,
            'stopped_pids' => $this->getStoppedPids(),
            'stop_time' => time()
        ];
        $eventManager->dispatch('Framework_Server::stop_after', $eventData);
        
        // 清理配置
        $this->clearServerConfig();
        $this->printer->success(__('服务器停止操作完成！'));
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
    private function stopProcess(int $pid, bool $force = false): bool
    {
        if (function_exists('posix_kill')) {
            // Linux/Unix系统
            if ($force) {
                // 强制停止：先尝试SIGTERM，然后SIGKILL
                $killed = posix_kill($pid, SIGTERM);
                sleep(2);
                if ($this->isProcessRunning($pid)) {
                    $killed = posix_kill($pid, SIGKILL);
                    sleep(1);
                }
            } else {
                // 正常停止：只使用SIGTERM
                $killed = posix_kill($pid, SIGTERM);
                sleep(2);
            }
            return !$this->isProcessRunning($pid);
        } else {
            // Windows系统
            if ($force) {
                // 强制停止：使用多种方法
                $output = [];
                exec("taskkill /PID {$pid} /F 2>NUL", $output, $returnCode);
                sleep(2);
                
                // 如果进程还在运行，尝试通过进程名称停止
                if ($this->isProcessRunning($pid)) {
                    $output = [];
                    exec("tasklist /FI \"PID eq {$pid}\" /FO CSV", $output);
                    if (!empty($output) && count($output) > 1) {
                        if (preg_match('/"([^"]+)"/', $output[1], $matches)) {
                            $processName = $matches[1];
                            exec("taskkill /IM \"{$processName}\" /F 2>NUL", $output, $returnCode);
                            sleep(2);
                        }
                    }
                }
            } else {
                // 正常停止：先尝试温和的方式
                $output = [];
                exec("taskkill /PID {$pid} 2>NUL", $output, $returnCode);
                sleep(2);
                
                // 如果温和方式失败，再尝试强制停止
                if ($this->isProcessRunning($pid)) {
                    exec("taskkill /PID {$pid} /F 2>NUL", $output, $returnCode);
                    sleep(2);
                }
            }
            
            return !$this->isProcessRunning($pid);
        }
    }

    /**
     * 清理服务器配置
     */
    private function clearServerConfig(): void
    {
        $env = Env::getInstance();
        $config = $env->getConfig();
        
        if (isset($config['server'])) {
            unset($config['server']);
            // 重新设置整个配置，不传递null值
            $env->setConfig('server', []);
            $env->save();
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
     * 获取停止的进程ID列表
     */
    private function getStoppedPids(): array
    {
        return $this->stoppedPids;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('停止PHP内置本地WebServer服务。使用 -f 或 --force 参数强制停止。');
    }
}
