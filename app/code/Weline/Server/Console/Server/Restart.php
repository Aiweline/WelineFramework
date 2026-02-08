<?php
declare(strict_types=1);

/**
 * Weline Server - 重启命令
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Processer;

/**
 * server:restart - 重启常驻内存服务器
 */
class Restart extends CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 解析实例名称
        $instanceName = $this->parseInstanceName($args);
        
        // 获取端口配置
        $port = $this->getPort($instanceName, $args);
        
        // 检查是否强制重启（-r 或 --force）
        $forceRestart = isset($args['r']) || isset($args['restart']) || isset($args['force']);
        
        // 检查服务器是否已在运行
        if ($this->isServerRunning($instanceName, $port)) {
            if (!$forceRestart) {
                // 未指定 -r，服务器已在运行则认为任务完成
                $this->showAlreadyRunningInfo($instanceName, $port);
                return;
            }
            
            // 指定了 -r，执行强制重启
            $this->printer->note(__('检测到服务器已运行，正在重启...'));
            
            // 先停止
            $stopCommand = ObjectManager::getInstance(Stop::class);
            $stopCommand->execute($args, $data);
            
            // 等待进程完全停止
            sleep(2);
        } else {
            // 服务器未运行，直接启动
            $this->printer->note(__('服务器未运行，正在启动...'));
        }
        
        // 设置守护进程模式
        $args['d'] = true;
        
        // 启动服务器
        $startCommand = ObjectManager::getInstance(Start::class);
        $startCommand->execute($args, $data);
    }
    
    /**
     * 解析实例名称
     */
    protected function parseInstanceName(array $args): string
    {
        // 检查位置参数（第一个非选项参数作为实例名）
        // 排除命令名（包含冒号）
        foreach ($args as $key => $val) {
            if (\is_int($key) && \is_string($val) && !str_starts_with($val, '-') && !str_contains($val, ':')) {
                return $val;
            }
        }
        
        // 检查命名参数
        if (!empty($args['instance'])) {
            return $args['instance'];
        }
        if (!empty($args['name'])) {
            return $args['name'];
        }
        
        return 'default';
    }
    
    /**
     * 获取端口配置
     */
    protected function getPort(string $instanceName, array $args): int
    {
        // 命令行参数优先
        if (isset($args['p'])) {
            return (int) $args['p'];
        }
        if (isset($args['port'])) {
            return (int) $args['port'];
        }
        
        // 从实例文件获取
        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $instanceName . '.json';
        if (\is_file($instanceFile)) {
            $instanceData = \json_decode(\file_get_contents($instanceFile), true);
            if (!empty($instanceData['port'])) {
                return (int) $instanceData['port'];
            }
        }
        
        // 从 env 配置获取
        $serverConfig = Env::getInstance()->getConfig('server');
        if (!empty($serverConfig['port'])) {
            return (int) $serverConfig['port'];
        }
        
        // 默认端口
        return Start::DEFAULT_PORT_HTTPS;
    }
    
    /**
     * 检查服务器是否已运行
     */
    protected function isServerRunning(string $instanceName, int $port): bool
    {
        // 检查实例文件
        $instanceFile = Env::VAR_DIR . 'server' . DS . 'instances' . DS . $instanceName . '.json';
        if (!\is_file($instanceFile)) {
            // 无实例文件但端口被占用，也算运行中
            return Processer::isPortInUse($port);
        }
        
        $instanceData = \json_decode(\file_get_contents($instanceFile), true);
        if (!$instanceData) {
            return Processer::isPortInUse($port);
        }
        
        $count = (int) ($instanceData['count'] ?? 4);
        $workerPortBase = (int) ($instanceData['worker_port'] ?? $port);
        
        // 检查 Dispatcher PID
        $dispatcherProcessName = '--name=weline-dispatcher-' . $instanceName;
        $dispatcherPid = (int) Processer::getData($dispatcherProcessName, 'pid');
        if ($dispatcherPid > 0 && Processer::isRunningByPid($dispatcherPid)) {
            return true;
        }
        
        // 检查 Worker PIDs
        for ($i = 1; $i <= $count; $i++) {
            $workerProcessName = '--name=weline-master-' . $instanceName . '-worker-' . $i;
            $workerPid = (int) Processer::getData($workerProcessName, 'pid');
            if ($workerPid > 0 && Processer::isRunningByPid($workerPid)) {
                return true;
            }
        }
        
        // 端口检测
        if (Processer::isPortInUse($port)) {
            return true;
        }
        
        // 检查 Worker 端口
        for ($i = 0; $i < $count; $i++) {
            $workerPort = $workerPortBase + $i;
            if (Processer::isPortInUse($workerPort)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 显示服务器已运行的提示信息
     */
    protected function showAlreadyRunningInfo(string $instanceName, int $port): void
    {
        echo "\n";
        $this->printer->success(__('✓ 服务器已在运行中'));
        echo "\n";
        
        $this->printer->note(__('实例名称：%{1}', [$instanceName]));
        $this->printer->note(__('监听端口：%{1}', [$port]));
        echo "\n";
        
        $this->printer->setup(__('如需强制重启服务器，请使用以下命令：'));
        $this->printer->note('  php bin/w server:restart ' . ($instanceName !== 'default' ? $instanceName . ' ' : '') . '-r');
        echo "\n";
        
        $this->printer->setup(__('其他操作：'));
        $this->printer->note('  ' . __('查看状态：php bin/w server:status'));
        $this->printer->note('  ' . __('停止服务：php bin/w server:stop'));
        echo "\n";
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('重启 Weline 常驻内存 HTTP 服务器');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:restart',
            __('确保 Weline Server 运行中（已运行则不重启，除非使用 -r）'),
            [
                '-r, --force' => __('强制重启（如服务器已运行，则先停止再启动）'),
                '-h, --host <ip>' => __('监听地址（默认：0.0.0.0）'),
                '-p, --port <port>' => __('监听端口（默认：443）'),
                '-c, --count <n>' => __('Worker 进程数（默认：4）'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('确保服务器运行') => 'php bin/w server:restart',
                __('强制重启服务器') => 'php bin/w server:restart -r',
                __('重启并更改端口') => 'php bin/w server:restart -r -p 9000',
            ]
        );
    }
}
