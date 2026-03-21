<?php
declare(strict_types=1);

/**
 * Weline Server - 热重载命令
 * 
 * 通知 Master 的 Orchestrator 执行滚动重启。
 * 命令只发送信号，所有重载逻辑由 Orchestrator 处理。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Observer\CliCommandExecutedObserver;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:reload - 热重载 Worker 代码
 * 
 * 架构：命令只负责发送信号，所有重载逻辑由 Orchestrator 处理
 * 
 * 适用场景：
 * - 修改了 Worker 相关代码
 * - 修改了业务代码（Controller、Model、Service 等）
 * - 修改了模板、配置等
 * 
 * 不适用场景（需要 server:restart -r）：
 * - 修改了 Dispatcher、Master、Orchestrator 代码
 * - 修改了启动参数（端口、Worker 数等）
 */
class Reload extends CommandAbstract
{
    /** 等待模式最大超时时间（秒） */
    private const WAIT_MAX_TIMEOUT = 120;
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $instanceName = $this->parseInstanceName($args);
        
        // 强制模式：批量杀死后重启，不等待排水
        $forceMode = isset($args['f']) || isset($args['force']);
        
        // 等待模式（默认），-n 跳过等待
        $waitMode = !(isset($args['n']) || isset($args['no-wait']));
        
        $reloadType = $forceMode 
            ? ControlMessage::RELOAD_TYPE_FORCE 
            : CliCommandExecutedObserver::RELOAD_TYPE_CODE;
        
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        
        // 获取运行状态
        $stats = $manager->getRunningStats();
        if ($stats['workers'] === 0) {
            $this->printer->warning(__('未检测到运行中的 WLS Worker'));
            $this->printer->note(__('请先启动服务器：php bin/w server:start'));
            return;
        }
        
        $totalWorkers = $stats['workers'];
        
        if ($forceMode) {
            $this->printer->warning(__('强制重载模式：批量杀死所有 Worker 后重新启动'));
        } else {
            $this->printer->note(__('执行滚动重启（优雅重载）...'));
        }
        $this->printer->note(__('Worker 数：%{1}', [$totalWorkers]));
        
        if ($waitMode) {
            $this->executeReloadAndWait($instanceName, $totalWorkers, $reloadType);
        } else {
            $this->executeReloadAsync($instanceName, $reloadType, $forceMode);
        }
        
        echo "\n";
    }
    
    /**
     * 发送 reload_wait 命令并等待完成
     */
    protected function executeReloadAndWait(string $instanceName, int $totalWorkers, string $reloadType): void
    {
        $info = MasterProcess::getMasterInfo($instanceName);
        $controlPort = (int)($info['control_port'] ?? 0);
        
        if ($controlPort <= 0) {
            $this->printer->warning(__('无法获取控制端口，请检查 Master 是否运行'));
            return;
        }
        
        // 建立 IPC 连接
        $conn = @\stream_socket_client("tcp://127.0.0.1:{$controlPort}", $errno, $errstr, 5);
        if (!$conn) {
            $this->printer->warning(__('无法建立 IPC 连接: %{1}', [$errstr]));
            return;
        }
        
        \stream_set_timeout($conn, self::WAIT_MAX_TIMEOUT);
        \stream_set_blocking($conn, false);
        
        // 发送 reload_wait 命令
        $command = ControlMessage::command(ControlMessage::ACTION_RELOAD_WAIT, $reloadType);
        $written = @\fwrite($conn, $command);
        
        if ($written === false || $written === 0) {
            @\fclose($conn);
            $this->printer->warning(__('发送命令失败'));
            return;
        }
        
        echo "\n";
        $this->printer->success(__('✓ 热重载命令已发送'));
        $this->printer->note(__('等待 Orchestrator 完成滚动重启...'));
        
        // 等待 Master 推送完成/失败事件
        $this->waitForCompletion($conn, $totalWorkers);
    }
    
    /**
     * 等待 Orchestrator 完成滚动重启
     */
    protected function waitForCompletion($conn, int $totalWorkers): void
    {
        $startTime = \microtime(true);
        $deadline = $startTime + self::WAIT_MAX_TIMEOUT;
        $buffer = '';
        $lastProgress = '';
        
        while (\microtime(true) < $deadline) {
            $data = @\fread($conn, 4096);
            
            if ($data === false) {
                @\fclose($conn);
                echo "\n";
                $this->printer->warning(__('连接异常断开'));
                return;
            }
            
            if ($data !== '') {
                $buffer .= $data;
                $messages = ControlMessage::extractMessages($buffer);
                
                foreach ($messages as $msg) {
                    $type = $msg['type'] ?? '';
                    
                    switch ($type) {
                        case ControlMessage::TYPE_RELOAD_COMPLETED:
                            $this->handleReloadCompleted($msg, $conn, $totalWorkers);
                            return;
                            
                        case ControlMessage::TYPE_RELOAD_FAILED:
                            $this->handleReloadFailed($msg, $conn);
                            return;
                            
                        case ControlMessage::TYPE_RELOAD_PROGRESS:
                            $lastProgress = $this->handleReloadProgress($msg, $totalWorkers, $lastProgress);
                            break;
                            
                        case ControlMessage::TYPE_COMMAND_RESULT:
                            // handleCommandResult 返回 true 表示最终结果，false 表示继续等待
                            if ($this->handleCommandResult($msg, $conn, $totalWorkers)) {
                                return;
                            }
                            break;
                    }
                }
            }
            
            SchedulerSystem::usleep(50000); // 50ms
        }
        
        @\fclose($conn);
        echo "\n";
        $this->printer->warning(__('等待超时（%{1}s），重启可能仍在进行中', [self::WAIT_MAX_TIMEOUT]));
        $this->printer->note(__('可用 server:status 查看当前状态'));
    }
    
    /**
     * 处理重载完成事件
     */
    protected function handleReloadCompleted(array $msg, $conn, int $totalWorkers): void
    {
        $elapsedMs = $msg['elapsed_ms'] ?? 0;
        $workerCount = $msg['worker_count'] ?? $totalWorkers;
        $elapsedDisplay = $elapsedMs < 1000 
            ? \sprintf('%dms', (int)$elapsedMs) 
            : \sprintf('%.1fs', $elapsedMs / 1000);
        
        echo "\n";
        $this->printer->success(__('✓ 滚动重启完成（耗时: %{1}，Worker: %{2}）', [$elapsedDisplay, $workerCount]));
        @\fclose($conn);
    }
    
    /**
     * 处理重载失败事件
     */
    protected function handleReloadFailed(array $msg, $conn): void
    {
        $reason = $msg['reason'] ?? __('未知错误');
        $failedWorkerId = $msg['worker_id'] ?? 0;
        
        echo "\n";
        if ($failedWorkerId > 0) {
            $this->printer->error(__('✗ 滚动重启失败：Worker #%{1} - %{2}', [$failedWorkerId, $reason]));
        } else {
            $this->printer->error(__('✗ 滚动重启失败：%{1}', [$reason]));
        }
        @\fclose($conn);
    }
    
    /**
     * 处理重载进度事件
     */
    protected function handleReloadProgress(array $msg, int $totalWorkers, string $lastProgress): string
    {
        $completed = $msg['completed'] ?? 0;
        $total = $msg['total'] ?? $totalWorkers;
        $currentWorkerId = $msg['current_worker_id'] ?? 0;
        $stage = $msg['stage'] ?? '';
        
        $stageText = match ($stage) {
            'draining' => __('排水中'),
            'starting' => __('启动中'),
            'waiting_ready' => __('等待就绪'),
            default => $stage,
        };
        
        $progress = __('Worker #%{1} %{2} (%{3}/%{4})', [$currentWorkerId, $stageText, $completed, $total]);
        $this->printProgress($progress, $lastProgress);
        return $progress;
    }
    
    /**
     * 处理命令结果
     * 
     * @return bool 是否为最终结果（true 表示应结束等待，false 表示继续等待）
     */
    protected function handleCommandResult(array $msg, $conn, int $totalWorkers): bool
    {
        $success = $msg['success'] ?? false;
        $message = $msg['message'] ?? '';
        
        // "Reload initiated" 是初始响应，表示 Orchestrator 已收到命令并开始执行
        // 需要继续等待 RELOAD_COMPLETED 或 RELOAD_FAILED 消息
        if ($success && \stripos($message, 'initiated') !== false) {
            return false;
        }
        
        if ($success) {
            $elapsedMs = $msg['data']['elapsed_ms'] ?? 0;
            $workerCount = $msg['data']['worker_count'] ?? $totalWorkers;
            $elapsedDisplay = $elapsedMs < 1000 
                ? \sprintf('%dms', (int)$elapsedMs) 
                : \sprintf('%.1fs', $elapsedMs / 1000);
            
            echo "\n";
            $this->printer->success(__('✓ 重载完成（耗时: %{1}，Worker: %{2}）', [$elapsedDisplay, $workerCount]));
        } else {
            echo "\n";
            $this->printer->error(__('✗ 重载失败：%{1}', [$message ?: '未知错误']));
        }
        @\fclose($conn);
        return true;
    }
    
    /**
     * 异步发送重载命令（不等待完成）
     */
    protected function executeReloadAsync(string $instanceName, string $reloadType, bool $forceMode): void
    {
        /** @var BroadcastControlDispatchService $dispatchService */
        $dispatchService = ObjectManager::getInstance(BroadcastControlDispatchService::class);
        $result = $dispatchService->reloadAsync($instanceName, $reloadType);

        if (empty($result['success'])) {
            $this->printer->warning($result['message']);
            return;
        }
        
        echo "\n";
        $this->printer->success(__('✓ 热重载命令已发送'));
        $this->printer->note($result['message']);
        
        if ($forceMode) {
            $this->printer->note(__('Orchestrator 将批量杀死所有 Worker 后重新启动'));
        } else {
            $this->printer->note(__('Orchestrator 将编排滚动重启，Worker 逐个优雅重启'));
        }
    }
    
    /**
     * 打印进度（覆盖上一行）
     */
    protected function printProgress(string $progress, string $lastProgress): void
    {
        if ($progress === $lastProgress) {
            return;
        }
        
        $clearLen = \max(\strlen($lastProgress), \strlen($progress)) + 10;
        echo "\r" . \str_repeat(' ', $clearLen) . "\r";
        echo "  " . $progress;
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
            __('通知 Orchestrator 执行滚动重启。修改 Worker 代码后使用此命令即可生效。'),
            [
                '[instance]' => __('实例名称（默认：default）'),
                '-f, --force' => __('强制模式：批量杀死所有 Worker 后重新启动（不等待排水）'),
                '-n, --no-wait' => __('不等待：发送命令后立即返回'),
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
            ]
        );
    }
}
