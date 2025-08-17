<?php

namespace Weline\Framework\Console\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Console\Console\Deploy\Mode\Set;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Start implements CommandInterface
{
    function __construct(
        private Set      $set,
        private System   $system,
        private Printing $printer
    )
    {

    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $host = $args['host'] ?? $args['h'] ?? '127.0.0.1';
        $port = $args['port'] ?? $args['p'] ?? '9981';
        $backend = $args['backend'] ?? $args['b'] ?? false;
        $force = $args['force'] ?? $args['f'] ?? false;
        
        // 检查服务是否已经运行
        $runningInfo = $this->isServerRunning($host, $port);
        if ($runningInfo['running']) {
            if ($force) {
                // 强制启动模式：先停止现有服务器
                $this->printer->note(__('检测到服务器已在运行中，强制启动模式将先停止现有服务器...'));
                
                if ($runningInfo['pid']) {
                    $this->printer->note(__('正在停止现有服务器进程，进程ID：%{1}', [$runningInfo['pid']]));
                    if ($this->stopExistingServer($runningInfo['pid'])) {
                        $this->printer->success(__('现有服务器已成功停止'));
                    } else {
                        $this->printer->error(__('停止现有服务器失败，请手动停止后重试'));
                        return;
                    }
                } else {
                    // 通过端口停止
                    $actualPid = $this->getProcessIdByPort($port);
                    if ($actualPid) {
                        $this->printer->note(__('通过端口检测到进程，正在停止，进程ID：%{1}', [$actualPid]));
                        if ($this->stopExistingServer($actualPid)) {
                            $this->printer->success(__('现有服务器已成功停止'));
                        } else {
                            $this->printer->error(__('停止现有服务器失败，请手动停止后重试'));
                            return;
                        }
                    } else {
                        $this->printer->warning(__('端口被占用但无法确定进程ID，尝试强制清理端口'));
                        // 等待一下让端口释放
                        sleep(3);
                    }
                }
                
                // 清理配置
                $this->clearServerConfig();
                
                // 等待端口完全释放
                $this->printer->note(__('等待端口释放...'));
                sleep(2);
                
                // 触发服务器停止事件（强制重启时）
                $eventManager = ObjectManager::getInstance(EventsManager::class);
                $stopEventData = [
                    'pid' => $runningInfo['pid'],
                    'host' => $host,
                    'port' => $port,
                    'force' => true,
                    'reason' => 'force_restart'
                ];
                $eventManager->dispatch('Framework_Server::stop_after', $stopEventData);
                
            } else {
                // 非强制模式：显示现有服务器信息
                if ($runningInfo['pid']) {
                    $this->printer->success(__('检测到服务器已在运行中！进程ID：%{1}', [$runningInfo['pid']]));
                    
                    // 检查配置是否完整，如果不完整则更新
                    $env = Env::getInstance();
                    $serverConfig = $env->get('server') ?? [];
                    if (empty($serverConfig) || !isset($serverConfig['pid']) || $serverConfig['pid'] != $runningInfo['pid']) {
                        $this->saveServerPid($host, $port, $runningInfo['pid']);
                        $this->printer->note(__('已自动更新配置信息。'));
                    }
                    
                    // 显示服务器信息
                    $this->printer->note(__('后端地址：http://%{1}:%{2}/%{3}/admin/login', [$host, $port, Env::get('admin')]));
                    $this->printer->note(__('后端API地址：http://%{1}:%{2}/%{3}/rest', [$host, $port, Env::get('api_admin')]));
                    
                    // 如果是后台模式，显示后台运行信息
                    $this->printer->success(__('服务器已在后台运行'));
                    $this->printer->warning(__('如果需要停止服务器，请使用 "php bin/w server:stop" 命令'));
                    $this->printer->note(__('如需强制重启，请使用 "php bin/w server:start -f" 命令'));
                    
                    return;
                } else {
                    $this->printer->warning(__('检测到端口被占用，但无法获取进程信息'));
                    $this->printer->note(__('后端地址：http://%{1}:%{2}/%{3}/admin/login', [$host, $port, Env::get('admin')]));
                    $this->printer->note(__('后端API地址：http://%{1}:%{2}/%{3}/rest', [$host, $port, Env::get('api_admin')]));
                    $this->printer->note(__('如需强制重启，请使用 "php bin/w server:start -f" 命令'));
                    return;
                }
            }
        }
        
        # 咨询，WEB服务器会将部署模式设置为DEV
        $this->printer->warning(__('开发专用，请勿用于生产环境。'));
        $this->printer->note(__('启用PHP内置本地WebServer服务...'));
        $this->printer->note(__('后端地址：http://%{1}:%{2}/%{3}/admin/login', [$host, $port, Env::get('admin')]));
        $this->printer->note(__('后端API地址：http://%{1}:%{2}/%{3}/rest', [$host, $port, Env::get('api_admin')]));
        # 局域网
        # 获取本机局域网IP
        $this->printer->note(__('局域网访问：'));
        $this->printer->note(__('局域网地址：http://%{1}:%{2}/%{3}/admin/login', [$this->system->getLocalIp(), $port, Env::get('admin')]));
        $this->printer->note(__('局域网API地址：http://%{1}:%{2}/%{3}/rest', [$this->system->getLocalIp(), $port, Env::get('api_admin')]));

        # 调用静态文件部署
        $force = $args['force'] ?? $args['f'] ?? false;
        if (!$force && Env::get('deploy') !== 'dev') {
            $this->printer->setup(__('启用PHP内置服务器需要将部署模式\'设置为dev，当前部署模式为 %{1}，是否继续(y/n)?', Env::get('deploy') ?? 'default'));
            $input = $this->system->input();
            if (strtolower(chop($input)) !== 'y' && strtolower(chop($input)) !== 'yes') {
                $this->printer->setup('已为您取消操作！');
                return;
            }
            $this->set->deploy('dev');
        }
        if (Env::get('deploy') !== 'dev') {
            # 清理缓存
            ObjectManager::getInstance(\Weline\Framework\Cache\Console\Cache\Clear::class)->execute();
            # 强制部署开发环境
            $this->set->deploy('prod');
        }
        
        // 启动服务器并记录进程ID
        $pid = Server::instance($host, $port, $backend);
        
        // 调试信息
        if ($backend) {
            if (function_exists('proc_open')) {
                $this->printer->note(__('使用proc_open启动后台进程'));
            } else {
                $this->printer->note(__('proc_open不可用，使用传统方式启动后台进程'));
            }
        }
        
        // 如果后台模式下PID为null，尝试通过端口获取PID
        if ($backend && !$pid) {
            $connection = @fsockopen($host, $port, $errno, $errstr, 1);
            if (is_resource($connection)) {
                fclose($connection);
                // 尝试获取占用端口的进程ID
                $pid = $this->getProcessIdByPort($port);
                if ($pid) {
                    $this->printer->note(__('通过端口检测到服务器进程，进程ID：%{1}', [$pid]));
                }
            }
        }
        
        // 如果后台模式下仍然没有PID，但端口被占用，说明启动成功
        if ($backend && !$pid) {
            $connection = @fsockopen($host, $port, $errno, $errstr, 1);
            if (is_resource($connection)) {
                fclose($connection);
                $this->printer->success(__('服务器已在后台启动成功！'));
                $this->printer->note(__('使用 "php bin/w server:stop" 停止服务器'));
                $this->printer->success(__('程序已进入后台运行'));
                return;
            }
        }
        
        if ($pid) {
            $this->saveServerPid($host, $port, $pid);
            
            // 触发服务器启动事件
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = [
                'pid' => $pid,
                'host' => $host,
                'port' => $port,
                'backend' => $backend,
                'start_time' => time(),
                'force' => $force
            ];
            $eventManager->dispatch('Framework_Server::start_after', $eventData);
            
            if ($backend) {
                $this->printer->success(__('服务器已在后台启动成功！进程ID：%{1}', [$pid]));
                $this->printer->note(__('使用 "php bin/w s:stop" 停止服务器'));
                $this->printer->success(__('程序已进入后台运行，可以关闭终端'));
                
                // 后台模式下，打印完信息后自动退出
                return;
            } else {
                $this->printer->success(__('服务器启动成功！进程ID：%{1}', [$pid]));
                $this->printer->note(__('按 Ctrl+C 停止服务器'));
            }
        } else if ($backend) {
            // 后台模式下，如果PID为null，说明启动失败
            $this->printer->error(__('后台启动失败，请检查端口是否被占用'));
        }
    }

    /**
     * 检查服务器是否正在运行
     * @return array ['running' => bool, 'pid' => int|null]
     */
    private function isServerRunning(string $host, int $port): array
    {
        $env = Env::getInstance();
        $serverConfig = $env->get('server') ?? [];
        
        if (isset($serverConfig['pid']) && $serverConfig['pid']) {
            // 检查进程是否存在
            if (function_exists('posix_kill')) {
                $isRunning = posix_kill($serverConfig['pid'], 0);
                return [
                    'running' => $isRunning,
                    'pid' => $isRunning ? $serverConfig['pid'] : null
                ];
            } else {
                // Windows系统检查
                $output = [];
                exec("tasklist /FI \"PID eq {$serverConfig['pid']}\" 2>NUL", $output);
                $isRunning = !empty($output) && count($output) > 1;
                return [
                    'running' => $isRunning,
                    'pid' => $isRunning ? $serverConfig['pid'] : null
                ];
            }
        }
        
        // 检查端口是否被占用
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if (is_resource($connection)) {
            fclose($connection);
            
            // 尝试获取占用端口的进程ID
            $pid = $this->getProcessIdByPort($port);
            
            return [
                'running' => true,
                'pid' => $pid
            ];
        }
        
        return [
            'running' => false,
            'pid' => null
        ];
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
     * 停止现有服务器
     */
    private function stopExistingServer(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            // Linux/Unix系统
            $killed = posix_kill($pid, SIGTERM);
            sleep(2);
            if ($this->isProcessRunning($pid)) {
                $killed = posix_kill($pid, SIGKILL);
                sleep(1);
            }
            return !$this->isProcessRunning($pid);
        } else {
            // Windows系统
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
            
            return !$this->isProcessRunning($pid);
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
     * 保存服务器进程ID到环境配置
     */
    private function saveServerPid(string $host, int $port, int $pid): void
    {
        $env = Env::getInstance();
        
        $serverConfig = [
            'host' => $host,
            'port' => $port,
            'pid' => $pid,
            'start_time' => time(),
            'status' => 'running'
        ];
        
        $env->setConfig('server', $serverConfig);
        $env->save();
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '启用PHP内置本地WebServer服务。开发专用，请勿用于生产环境。默认实时运行，使用 -b 或 -backend 参数后台运行，使用 -f 或 --force 参数强制重启。';
    }
}