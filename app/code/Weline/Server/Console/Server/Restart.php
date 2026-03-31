<?php
declare(strict_types=1);

/**
 * Weline Server - 重启命令
 *
 * 已运行且 -r：委托 Reload 执行滚动重载（Master 保持）。
 * 未运行：委托 Start 启动。只有 server:stop 会停止 Master。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:restart - 重启常驻内存服务器
 *
 * 已运行时（-r）：保持 Master 运行，通过 IPC 发送 reload 执行滚动重启（维护模式+重载 Worker）。
 * 未运行时：直接启动。只有 server:stop 才会停止 Master。
 */
class Restart extends CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $instanceName = $this->parseInstanceName($args);
        $forceSwitch = isset($args['f']);
        $forceRestart = isset($args['r']) || isset($args['restart']) || isset($args['force']) || $forceSwitch;
        
        // 检查服务器是否已在运行
        if ($this->isServerRunning($instanceName)) {
            if (!$forceRestart) {
                $this->showAlreadyRunningInfo($instanceName);
                return;
            }

            // 强制重启：保持 Master 运行，通过 IPC 发送 reload 命令，由 Orchestrator 执行维护模式+滚动重载
            // 只有 server:stop 才会停止 Master
            if ($forceSwitch) {
                $this->printer->warning(__('检测到服务器已运行，-f 强制模式：单批次重启 Worker（Master 保持运行）...'));
            } else {
                $this->printer->note(__('检测到服务器已运行，执行滚动重启（Master 保持运行）...'));
            }
            $reloadCommand = ObjectManager::getInstance(Reload::class);
            $reloadCommand->execute($args, $data);
            return;
        }

        $this->printer->note(__('服务器未运行，正在启动...'));

        // 设置守护进程模式
        $args['d'] = true;

        // 启动
        $startCommand = ObjectManager::getInstance(Start::class);
        $startCommand->execute($args, $data);
    }
    
    /**
     * 解析实例名称
     */
    protected function parseInstanceName(array $args): string
    {
        foreach ($args as $key => $val) {
            if (\is_int($key) && \is_string($val) && !str_starts_with($val, '-') && !str_contains($val, ':')) {
                return $val;
            }
        }
        
        return $args['instance'] ?? $args['name'] ?? 'default';
    }
    
    /**
     * 检查服务器是否已运行
     *
     * 使用 ServerInstanceManager 获取统一的实例信息
     */
    protected function isServerRunning(string $instanceName): bool
    {
        $manager = $this->getInstanceManager();
        $info = $manager->getInstanceInfo($instanceName);
        
        if ($info === null) {
            return false;
        }
        
        // 检查 Master 进程
        if ($info->isMasterRunning()) {
            return true;
        }
        
        // 检查主端口
        if ($info->port > 0 && Processer::isPortInUse($info->port)) {
            return true;
        }
        
        // 检查是否有任何服务在运行
        $stats = $info->getServiceStats();
        if ($stats['running'] > 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取实例管理器
     */
    protected function getInstanceManager(): ServerInstanceManager
    {
        return ObjectManager::getInstance(ServerInstanceManager::class);
    }
    
    /**
     * 显示服务器已运行的提示信息
     */
    protected function showAlreadyRunningInfo(string $instanceName): void
    {
        echo "\n";
        $this->printer->success(__('✓ 服务器已在运行中'));
        echo "\n";
        
        $this->printer->note(__('实例名称：%{1}', [$instanceName]));
        echo "\n";
        
        $this->printer->setup(__('如需强制重启服务器，请使用以下命令：'));
        $this->printer->note('  php bin/w server:restart ' . ($instanceName !== 'default' ? $instanceName . ' ' : '') . '-r');
        echo "\n";
        
        $this->printer->setup(__('其他操作：'));
        $this->printer->note('  ' . __('查看状态：php bin/w server:status'));
        $this->printer->note('  ' . __('停止服务：php bin/w server:stop'));
        $this->printer->note('  ' . __('热重载：php bin/w server:reload'));
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
            __('确保 Weline Server 运行中（已运行 -r 时保持 Master 滚动重载，未运行则启动）'),
            [
                '-r, --force' => __('强制重启（保持 Master，滚动重载 Worker）'),
                '-f' => __('与 -r 同用时：单批次重启 Worker（跳过分批策略）'),
                '-h, --host <ip>' => __('监听地址（默认：0.0.0.0）'),
                '-p, --port <port>' => __('监听端口（默认：443）'),
                '-c, --count <n>' => __('Worker 进程数（默认：4）'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('确保服务器运行') => 'php bin/w server:restart',
                __('强制重启服务器') => 'php bin/w server:restart -r',
                __('强制单批次重启') => 'php bin/w server:restart -r -f',
                __('重启并更改端口') => 'php bin/w server:restart -r -p 9000',
            ]
        );
    }
}
