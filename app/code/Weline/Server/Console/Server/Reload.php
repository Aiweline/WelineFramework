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
use Weline\Server\IPC\ControlMessage;
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
    /** 等待模式轮询间隔（毫秒） */
    private const WAIT_POLL_INTERVAL_MS = 500;
    
    /** 等待模式最大超时时间（秒） */
    private const WAIT_MAX_TIMEOUT = 120;
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 解析实例名称
        $instanceName = $this->parseInstanceName($args);
        
        // 检查是否强制模式（-f）：批量杀死后重启，不等待排水
        $forceMode = isset($args['f']) || isset($args['force']);
        
        // 默认等待模式，-n/--no-wait 跳过等待
        $waitMode = !(isset($args['n']) || isset($args['no-wait']));
        
        // 确定重载类型
        $reloadType = $forceMode 
            ? ControlMessage::RELOAD_TYPE_FORCE 
            : CliCommandExecutedObserver::RELOAD_TYPE_CODE;
        
        if ($forceMode) {
            $this->printer->warning(__('强制重载模式：批量杀死所有 Worker 后重新启动（不等待排水）'));
        } else {
            $this->printer->note(__('正在执行代码重载（滚动重启）...'));
        }
        
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
            if ($forceMode) {
                $this->printer->note(__('Master 将批量杀死所有 Worker 后重新启动'));
            } else {
                $this->printer->note(__('Master 将编排滚动重启，Worker 逐个优雅重启'));
            }
            $this->printer->note(__('Worker 数：%{1}', [$totalWorkers]));
            
            // 等待模式：轮询直到滚动重启完成
            if ($waitMode) {
                echo "\n";
                $this->waitForReloadComplete($instanceName, $totalWorkers);
            }
            
            echo "\n";
            return;
        }
        
        // IPC 失败时，强制模式无法回退到信号方式
        if ($forceMode) {
            $this->printer->error(__('IPC 通道不可用，强制模式无法执行'));
            $this->printer->note(__('请检查 Master 是否运行，或使用 server:start -r -f 重启'));
            return;
        }
        
        // IPC 失败：回退到信号方式（仅滚动重启）
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
                
                // 等待模式（信号方式回退）
                if ($waitMode) {
                    echo "\n";
                    $this->waitForReloadComplete($instanceName, $totalWorkers);
                }
                
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
     * 等待滚动重启完成
     */
    protected function waitForReloadComplete(string $instanceName, int $totalWorkers): void
    {
        $this->printer->note(__('等待滚动重启完成...'));
        
        $startTime = \time();
        $lastProgress = -1;
        $reloadStarted = false;  // 标记滚动重启是否已开始
        $consecutiveFailures = 0; // 连续查询失败次数
        $maxConsecutiveFailures = 5; // 最大允许连续失败次数
        
        // 先等待一小段时间让 Master 处理 reload 命令
        \usleep(200000); // 200ms
        
        while (true) {
            // 超时检查
            $elapsed = \time() - $startTime;
            if ($elapsed > self::WAIT_MAX_TIMEOUT) {
                $this->printer->warning(__('等待超时 (%{1}s)，滚动重启可能仍在进行中', [self::WAIT_MAX_TIMEOUT]));
                return;
            }
            
            // 查询 Master 状态（带重试）
            $status = MasterProcess::sendCommand($instanceName, ControlMessage::ACTION_STATUS);
            if ($status === null) {
                $consecutiveFailures++;
                if ($consecutiveFailures >= $maxConsecutiveFailures) {
                    $this->printer->warning(__('连续 %{1} 次无法获取 Master 状态，等待中止', [$consecutiveFailures]));
                    return;
                }
                // 短暂等待后重试
                \usleep(500000); // 500ms
                continue;
            }
            
            // 查询成功，重置失败计数
            $consecutiveFailures = 0;
            
            $data = $status['data'] ?? [];
            $rollingRestart = $data['rolling_restart'] ?? false;
            $forceRestarting = $data['force_restarting'] ?? false;
            $rollingQueue = $data['rolling_queue'] ?? [];
            $drainingWorker = $data['draining_worker'] ?? 0;
            
            // 检测滚动重启是否已开始
            if ($rollingRestart || $forceRestarting) {
                $reloadStarted = true;
            }
            
            // 只有在滚动重启已开始后，才判断是否完成
            // 如果从未开始且已等待超过 5 秒，也认为完成（可能 Master 处理太快）
            if (!$rollingRestart && !$forceRestarting) {
                if ($reloadStarted || $elapsed >= 5) {
                    echo "\r" . \str_repeat(' ', 80) . "\r";
                    $this->printer->success(__('✓ 滚动重启完成（耗时: %{1}s）', [$elapsed]));
                    return;
                }
                // 还没开始，继续等待
                \usleep(self::WAIT_POLL_INTERVAL_MS * 1000);
                continue;
            }
            
            // 显示进度
            $remaining = \count($rollingQueue);
            $completed = $totalWorkers - $remaining - ($drainingWorker > 0 ? 1 : 0);
            $progress = $totalWorkers > 0 ? (int)(($completed / $totalWorkers) * 100) : 0;
            
            if ($progress !== $lastProgress) {
                $bar = $this->renderProgressBar($progress);
                echo "\r" . \str_repeat(' ', 80) . "\r";
                echo "  {$bar} {$completed}/{$totalWorkers} Worker";
                if ($drainingWorker > 0) {
                    echo " (Worker #{$drainingWorker} 排水中)";
                }
                $lastProgress = $progress;
            }
            
            // 轮询间隔
            \usleep(self::WAIT_POLL_INTERVAL_MS * 1000);
        }
    }
    
    /**
     * 渲染进度条
     */
    protected function renderProgressBar(int $percent): string
    {
        $percent = \max(0, \min(100, $percent));
        $width = 20;
        $filled = (int)(($percent / 100) * $width);
        $empty = $width - $filled;
        return '[' . \str_repeat('=', $filled) . \str_repeat(' ', $empty) . '] ' . \str_pad((string)$percent, 3, ' ', STR_PAD_LEFT) . '%';
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
            'server:reload [-f] [-n]',
            __('通知 WLS Worker 重新加载代码。修改 Worker 代码后使用此命令即可生效，无需重启整个服务器。'),
            [
                '[instance]' => __('实例名称（默认：default）'),
                '-f, --force' => __('强制模式：批量杀死所有 Worker 后重新启动（不等待排水）'),
                '-n, --no-wait' => __('不等待：发送命令后立即返回，不等待重启完成'),
            ],
            [
                __('默认行为') => __('等待滚动重启完成后返回，显示进度'),
                __('-f 强制模式') => __('批量重启：直接杀死所有 Worker，快速但会中断请求'),
                __('-n 不等待') => __('发送命令后立即返回，适合脚本调用'),
                __('适用场景') => __('修改了 Worker 代码、业务代码、模板、配置等'),
                __('不适用场景') => __('修改了 Dispatcher、Master 代码或启动参数（需用 server:start -r）'),
            ],
            [
                __('滚动重载（默认等待）') => 'php bin/w server:reload',
                __('不等待') => 'php bin/w server:reload -n',
                __('强制重载') => 'php bin/w server:reload -f',
                __('重载指定实例') => 'php bin/w server:reload api-server',
            ]
        );
    }
}
