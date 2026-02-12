<?php

declare(strict_types=1);

namespace Weline\Server\Console\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;

class Stop implements CommandInterface
{
    use TablePrinter;
    
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
        $hasConfig = !empty($serverConfig) && isset($serverConfig['pid']);
        
        $stoppedFromConfig = false;
        
        // 首先检查配置中的进程ID
        if ($pid && $this->isProcessRunning($pid)) {
            $this->printer->note(__('正在停止配置中的服务器进程，进程ID：%{1}', [$pid]));
            
            if ($this->stopProcess($pid, $force)) {
                $this->printer->success(__('配置中的服务器进程已停止！进程ID：%{1}', [$pid]));
                $this->stoppedPids[] = $pid;
                $stoppedFromConfig = true;
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
        // 即使配置为空，也应该检测端口
        $context = stream_context_create([
            'socket' => [
                'tcp_nodelay' => true,
            ]
        ]);
        $connection = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 0.5, STREAM_CLIENT_CONNECT, $context);
        if ($connection !== false && is_resource($connection)) {
            fclose($connection);
            
            // 尝试获取占用端口的进程ID
            $actualPid = $this->getProcessIdByPort($port);
            
            if ($actualPid) {
                // 如果配置中的 PID 和实际检测到的 PID 相同，且已经停止了，跳过
                if ($stoppedFromConfig && $actualPid == $pid) {
                    // 进程已停止，继续后续流程
                } else {
                    // 检测到端口被占用，但配置信息可能不完整或者 PID 不同
                    if (!$hasConfig) {
                        $this->printer->warning(__('检测到端口 %{1}:%{2} 被占用，但配置信息为空。', [$host, $port]));
                        $this->printer->note(__('检测到的进程ID：%{1}', [$actualPid]));
                    } else if ($actualPid != $pid) {
                        $this->printer->warning(__('检测到的进程ID（%{1}）与配置中的进程ID（%{2}）不一致。', [$actualPid, $pid]));
                    }
                    
                    // 如果使用了 -f 参数，直接停止；否则提示用户
                    if ($force) {
                        $this->printer->note(__('正在停止检测到的进程，进程ID：%{1}', [$actualPid]));
                    } else {
                        $this->printer->note(__('是否要停止此进程？使用 -f 参数直接停止。'));
                        // 即使没有 -f，也尝试停止（因为用户执行了 stop 命令）
                        $this->printer->note(__('正在停止检测到的进程，进程ID：%{1}', [$actualPid]));
                    }
                    
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
                }
            } else {
                $this->printer->warning(__('端口 %{1}:%{2} 被占用，但无法确定进程ID。', [$host, $port]));
                $this->printer->note(__('请手动检查端口占用情况。'));
            }
        } else {
            // 端口未被占用，检查是否已经停止过
            if (!$stoppedFromConfig) {
                if (!$hasConfig) {
                    $this->printer->info(__('未检测到服务器运行（端口 %{1}:%{2} 未被占用）。', [$host, $port]));
                } else {
                    $this->printer->info(__('服务器未运行（端口 %{1}:%{2} 未被占用）。', [$host, $port]));
                }
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
        $eventManager->dispatch('Weline_Server::stop_after', $eventData);
        
        // 清理配置
        $this->clearServerConfig();
        
        // 显示停止信息
        if (!empty($this->stoppedPids)) {
            echo "\n";
            $stopInfo = [];
            foreach ($this->stoppedPids as $stoppedPid) {
                $stopInfo[] = ['进程ID', $stoppedPid];
            }
            $this->printTable('已停止的服务', $stopInfo);
            echo "\n";
        }
        
        $this->printer->success(__('服务器停止操作完成！'));
    }

    /**
     * 检查进程是否正在运行
     */
    private function isProcessRunning(int $pid): bool
    {
        return Processer::isRunningByPid($pid);
    }

    /**
     * 停止进程
     */
    private function stopProcess(int $pid, bool $force = false): bool
    {
        if (!$force) {
            // 先尝试温和停止（与平台相关的优雅停止通常不可用，这里直接检查一次）
            if (!Processer::isRunningByPid($pid)) {
                return true;
            }
        }
        Processer::killByPid($pid);
        usleep(500000);
        return !Processer::isRunningByPid($pid);
    }

    /**
     * 清理服务器配置（仅移除运行时字段，保留用户配置如 worker_count、port、mode 等）
     */
    private function clearServerConfig(): void
    {
        $env = Env::getInstance();
        $server = $env->get('server');
        if (!\is_array($server)) {
            return;
        }
        $runtimeKeys = ['pid', 'start_time', 'status'];
        $cleaned = $server;
        foreach ($runtimeKeys as $key) {
            unset($cleaned[$key]);
        }
        $env->setConfig('server', $cleaned);
        $env->save();
    }

    /**
     * 通过端口获取进程ID
     */
    private function getProcessIdByPort(int $port): ?int
    {
        $pid = Processer::getProcessIdByPort($port);
        return $pid > 0 ? $pid : null;
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
