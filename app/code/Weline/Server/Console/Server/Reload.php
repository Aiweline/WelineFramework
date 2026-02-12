<?php
declare(strict_types=1);

/**
 * Weline Server - 热重载命令
 * 
 * 通知 WLS Worker 重新加载代码，无需重启整个服务器。
 * 仅重启 Worker 进程，Dispatcher 和 Master 保持运行。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\WlsInstanceRegistry;
use Weline\Server\Observer\CliCommandExecutedObserver;

/**
 * server:reload - 热重载 Worker 代码
 * 
 * 修改了 Worker 相关代码后使用此命令，无需完全重启服务器。
 * 适用场景：
 * - 修改了 worker.php / worker_ssl.php
 * - 修改了业务代码（Controller、Model、Service 等）
 * - 修改了模板、配置等
 * 
 * 不适用场景（需要 server:restart -r）：
 * - 修改了 dispatcher.php
 * - 修改了 MasterProcess.php
 * - 修改了启动参数（端口、Worker 数等）
 * 
 * 注意：缓存清理请使用 cache:clear 命令，已集成 WLS 缓存重载事件
 */
class Reload extends CommandAbstract
{
    // 重载通过 IPC 控制通道发送，不再使用文件标记
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 解析实例名称
        $instanceName = $this->parseInstanceName($args);
        
        // 代码重载类型
        $reloadType = CliCommandExecutedObserver::RELOAD_TYPE_CODE;
        
        $this->printer->note(__('正在执行代码重载...'));
        
        /** @var WlsInstanceRegistry $registry */
        $registry = ObjectManager::getInstance(WlsInstanceRegistry::class);
        
        // 获取运行状态
        $stats = $registry->getRunningStats();
        if ($stats['workers'] === 0) {
            $this->printer->warning(__('未检测到运行中的 WLS Worker'));
            $this->printer->note(__('请先启动服务器：php bin/w server:start'));
            return;
        }
        
        $totalWorkers = $stats['workers'];
        
        // 优先使用 IPC 控制通道（TCP 方式，跨平台、即时生效）
        $ipcSuccess = MasterProcess::sendReloadCommand($instanceName, $reloadType);
        
        if ($ipcSuccess) {
            echo "\n";
            $this->printer->success(__('✓ 热重载命令已发送（IPC 控制通道）'));
            $this->printer->note(__('Master 将编排滚动重启，Worker 逐个优雅重启'));
            $this->printer->note(__('Worker 数：%{1}', [$totalWorkers]));
            echo "\n";
            return;
        }
        
        // IPC 失败：回退到信号方式
        $notified = 0;
        $method = '';
        
        $masterPids = $registry->getRunningMasterPids();
        if (!empty($masterPids) && \defined('SIGHUP')) {
            foreach ($masterPids as $pid) {
                $pid = (int) $pid;
                if ($pid > 0 && Processer::isRunningByPid($pid) && Processer::sendSignal($pid, SIGHUP, true)) {
                    $notified++;
                    $this->printer->note(__('已向 Master (PID: %{1}) 发送 SIGHUP 信号', [$pid]));
                }
            }
            
            if ($notified > 0) {
                echo "\n";
                $this->printer->success(__('✓ 热重载信号已发送'));
                $this->printer->note(__('Master 将通知所有 Worker 优雅重启'));
                echo "\n";
                return;
            }
        }
        
        $workerPids = $registry->getRunningWorkerPids();
        if (\defined('SIGUSR1')) {
            foreach ($workerPids as $pid) {
                $pid = (int) $pid;
                if ($pid > 0 && Processer::isRunningByPid($pid) && Processer::sendSignal($pid, SIGUSR1, true)) {
                    $notified++;
                }
            }
        }
        $method = __('SIGUSR1 信号');
        
        if ($notified === 0) {
            $this->printer->warning(__('所有重载方式均失败，请检查 Master 是否运行'));
            return;
        }
        
        echo "\n";
        $this->printer->success(__('✓ 热重载通知已发送'));
        $this->printer->note(__('方式：%{1}', [$method]));
        $this->printer->note(__('Worker 数：%{1}', [$totalWorkers]));
        echo "\n";
        $this->printer->note(__('Worker 将在当前请求处理完成后优雅重启'));
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
        
        if (!empty($args['instance'])) {
            return $args['instance'];
        }
        if (!empty($args['name'])) {
            return $args['name'];
        }
        
        return 'default';
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('热重载 WLS Worker 代码（无需完全重启服务器）');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:reload',
            __('通知 WLS Worker 重新加载代码。修改 Worker 代码后使用此命令即可生效，无需重启整个服务器。'),
            [
                '[instance]' => __('实例名称（默认：default）'),
            ],
            [
                __('适用场景') => __('修改了 Worker 代码、业务代码、模板、配置等'),
                __('不适用场景') => __('修改了 Dispatcher、Master 代码或启动参数（需用 server:restart -r）'),
                __('缓存清理') => __('请使用 cache:clear 命令，已集成 WLS 缓存重载事件'),
            ],
            [
                __('代码重载（Worker 优雅重启）') => 'php bin/w server:reload',
                __('重载指定实例') => 'php bin/w server:reload api-server',
                __('清理缓存') => 'php bin/w cache:clear',
            ]
        );
    }
}
