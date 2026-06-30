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

use Weline\Framework\App\Env;
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
    /** 等待模式最小硬超时（秒） */
    private const WAIT_MIN_TIMEOUT = 30;

    /** 等待模式最大硬超时（秒） */
    private const WAIT_MAX_TIMEOUT = 300;

    /** 等待模式无进度超时（秒）：超过后主动退出，避免 CLI 卡死。 */
    private const WAIT_IDLE_TIMEOUT = 15;
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $requestedInstanceName = $this->parseInstanceName($args);
        
        // 强制模式：批量杀死后重启，不等待排水
        $forceMode = isset($args['f']) || isset($args['force']);
        
        // 等待模式（默认），-n 跳过等待；-f 强制模式固定为不等待
        $waitMode = !(isset($args['n']) || isset($args['no-wait']));
        
        $reloadType = $forceMode 
            ? ControlMessage::RELOAD_TYPE_FORCE 
            : CliCommandExecutedObserver::RELOAD_TYPE_CODE;
        
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $instanceName = $manager->resolvePersistedInstanceName($requestedInstanceName) ?? $requestedInstanceName;

        if ($instanceName !== $requestedInstanceName) {
            $this->printer->note(__('实例 [%{1}] 自动匹配到 [%{2}]', [$requestedInstanceName, $instanceName]));
        }

        $targetInfo = $manager->getPersistedInstanceInfo($instanceName);
        $targetStats = $targetInfo !== null
            ? $manager->probeRuntimeStatsForInstance($targetInfo, 6.0)
            : ['workers' => 0, 'ipc_success' => false, 'ipc_message' => 'instance endpoint not found'];
        $targetRunningWorkers = (int)($targetStats['workers'] ?? 0);
        if ($targetRunningWorkers <= 0) {
            if ($targetInfo !== null && !((bool)($targetStats['ipc_success'] ?? false))) {
                $message = (string)($targetStats['ipc_message'] ?? '');
                $this->printer->warning('Master IPC is unreachable; reload is not safe.');
                if ($message !== '') {
                    $this->printer->note($message);
                }
                $this->printer->note('This does not mean there are no workers. Check server:status and Master control-plane health first.');
                return;
            }
            $globalStats = $manager->getRunningStats();
            if (($globalStats['workers'] ?? 0) > 0) {
                $this->printer->warning(__('实例 [%{1}] 未检测到运行中的 WLS Worker', [$requestedInstanceName]));
                if ($manager->hasInstance($instanceName)) {
                    $this->printer->note(__('可执行 server:listing -r 查看当前运行中的实例'));
                } else {
                    $suggestions = $manager->suggestPersistedInstanceNames($requestedInstanceName);
                    if ($suggestions !== []) {
                        $this->printer->note(__('你可能想要的实例：%{1}', [\implode(', ', $suggestions)]));
                        $this->printer->note(__('如需重载最接近的实例，可执行 server:reload %{1}', [$suggestions[0]]));
                    } else {
                        $this->printer->note(__('请确认实例名称，或执行 server:listing 查看所有实例'));
                    }
                }
                return;
            }
            $this->printer->warning(__('未检测到运行中的 WLS Worker'));
            $this->printer->note(__('请先启动服务器：php bin/w server:start'));
            return;
        }

        $this->printer->note(__('当前操作实例：%{1}', [$instanceName]));
        $targetConfiguredWorkers = $targetInfo === null ? 0 : (int)$targetInfo->workerCount;
        $targetDesiredWorkers = (int)($targetStats['desired_workers'] ?? 0);
        $totalWorkers = \max($targetRunningWorkers, $targetConfiguredWorkers, $targetDesiredWorkers);
        
        if ($forceMode) {
            $this->printer->warning(__('强制重载模式：批量杀死所有 Worker 后重新启动'));
            $this->printer->warning(__('注意：-f 强制重载属于停机型更新，建议先开启维护模式；滚动重载不需要。'));
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
        $info = MasterProcess::getMasterEndpoint($instanceName);
        $controlPort = (int)($info['control_port'] ?? 0);
        $waitTimeout = $this->estimateWaitTimeout($totalWorkers);
        
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
        
        \stream_set_timeout($conn, $waitTimeout);
        \stream_set_blocking($conn, false);
        
        // 发送 reload_wait 命令
        $command = ControlMessage::command(
            ControlMessage::ACTION_RELOAD_WAIT,
            $reloadType,
            [],
            (string)($info['control_token'] ?? '')
        );
        $written = @\fwrite($conn, $command);
        
        if ($written === false || $written === 0) {
            @\fclose($conn);
            $this->printer->warning(__('发送命令失败'));
            return;
        }
        
        echo "\n";
        $this->printer->success(__('✓ 热重载命令已发送'));
        $this->printer->note(__('等待实例 [%{1}] 的 Orchestrator 完成滚动重启...', [$instanceName]));
        
        // 等待 Master 推送完成/失败事件
        $this->waitForCompletion($conn, $totalWorkers, $waitTimeout);
    }
    
    /**
     * 等待 Orchestrator 完成滚动重启
     */
    protected function waitForCompletion($conn, int $totalWorkers, int $waitTimeout): void
    {
        $startTime = \microtime(true);
        $deadline = $startTime + $waitTimeout;
        $lastMessageAt = $startTime;
        $buffer = '';
        $lastProgress = '';
        $lastIdleNoticeAt = $startTime;
        
        while (\microtime(true) < $deadline) {
            $data = @\fread($conn, 4096);
            
            if ($data === false) {
                @\fclose($conn);
                echo "\n";
                $this->printer->warning(__('连接异常断开'));
                return;
            }

            if ($data === '') {
                if (@\feof($conn)) {
                    @\fclose($conn);
                    echo "\n";
                    $this->printer->warning(__('控制连接已断开，Orchestrator 可能仍在后台继续执行；请用 server:status 查看结果'));
                    return;
                }

                if ((\microtime(true) - $lastMessageAt) >= self::WAIT_IDLE_TIMEOUT) {
                    $now = \microtime(true);
                    if (($now - $lastIdleNoticeAt) >= 30.0) {
                        $elapsed = (int)\round($now - $startTime);
                        $remaining = \max(0, (int)\round($deadline - $now));
                        $this->printer->note(__('仍在等待 Master 回传 reload 进度（已等待 %{1}s，剩余 %{2}s）；不会主动断开控制连接。', [$elapsed, $remaining]));
                        $lastIdleNoticeAt = $now;
                    }
                }

                SchedulerSystem::usleep(50000); // 50ms
                continue;
            }
            
            $lastMessageAt = \microtime(true);
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

            SchedulerSystem::usleep(50000); // 50ms
        }
        
        @\fclose($conn);
        echo "\n";
        $this->printer->warning(__('等待超时（%{1}s），重启可能仍在进行中', [$waitTimeout]));
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
        $completed = $msg['completed'] ?? $msg['progress'] ?? 0;
        $total = $msg['total'] ?? $totalWorkers;
        $currentWorkerId = $msg['current_worker_id'] ?? 0;
        $stage = $msg['stage'] ?? '';
        $message = (string) ($msg['message'] ?? '');

        if ($message !== '') {
            $friendlyMessage = $this->normalizeProgressMessage($message);
            $this->printProgress($friendlyMessage, $lastProgress);
            return $friendlyMessage;
        }
        
        $stageText = match ($stage) {
            'draining' => __('排水中'),
            'starting' => __('启动中'),
            'waiting_ready' => __('等待就绪'),
            'waiting_exit' => __('等待旧进程退出'),
            'removing_from_dispatcher' => __('从 Dispatcher 摘除'),
            'stopping' => __('停止中'),
            'rejoin_dispatcher' => __('回加 Dispatcher'),
            default => $stage,
        };
        
        $progress = __('Worker #%{1} %{2} (%{3}/%{4})', [$currentWorkerId, $stageText, $completed, $total]);
        $this->printProgress($progress, $lastProgress);
        return $progress;
    }

    /**
     * 将 Orchestrator 的原始进度文案转换为更友好的中文提示。
     */
    protected function normalizeProgressMessage(string $message): string
    {
        $text = \trim($message);
        if ($text === '') {
            return $message;
        }

        if (\preg_match('/^Batch\s+(\d+)\/(\d+):\s+starting workers\s+\[([^\]]*)\]\s+concurrently$/i', $text, $m)) {
            return (string) __('第 %{1}/%{2} 批：并发启动 Worker [%{3}]', [$m[1], $m[2], $m[3]]);
        }

        if (\preg_match('/^Batch\s+(\d+)\/(\d+):\s+draining workers\s+\[([^\]]*)\]$/i', $text, $m)) {
            return (string) __('第 %{1}/%{2} 批：Worker [%{3}] 正在排水', [$m[1], $m[2], $m[3]]);
        }

        if (\preg_match('/^Batch\s+(\d+)\/(\d+):\s+waiting workers\s+\[([^\]]*)\]\s+ready$/i', $text, $m)) {
            return (string) __('第 %{1}/%{2} 批：等待 Worker [%{3}] 就绪', [$m[1], $m[2], $m[3]]);
        }

        if (\preg_match('/^Batch\s+(\d+)\/(\d+):\s+workers\s+\[([^\]]*)\]\s+rejoined dispatcher$/i', $text, $m)) {
            return (string) __('第 %{1}/%{2} 批：Worker [%{3}] 已回加 Dispatcher', [$m[1], $m[2], $m[3]]);
        }

        return $message;
    }

    protected function estimateWaitTimeout(int $totalWorkers): int
    {
        $workerCount = \max(1, $totalWorkers);
        $minThree = (int) (Env::get('wls.orchestrator.worker_three_batch_min_count', 7) ?? 7);
        if ($minThree < 4) {
            $minThree = 7;
        }

        $batchCount = $workerCount >= $minThree ? 3 : $workerCount;
        $baseSize = intdiv($workerCount, $batchCount);
        $remainder = $workerCount % $batchCount;
        $batchSizes = [];
        for ($i = 0; $i < $batchCount; $i++) {
            $batchSizes[] = $baseSize + ($i < $remainder ? 1 : 0);
        }

        $drainTimeout = (float) (Env::get('wls.orchestrator.drain_timeout_sec', 5.0) ?? 5.0);
        $drainTimeout = \max(1.0, \min(60.0, $drainTimeout));

        $startupTimeout = (float) (Env::get('wls.orchestrator.startup_timeout_sec', 30.0) ?? 30.0);
        $startupTimeout = \max(10.0, \min(1800.0, $startupTimeout));

        $estimated = 15.0;
        foreach ($batchSizes as $batchSize) {
            $exitWait = 15.0 + 5.0 * $batchSize;
            $readyWait = $startupTimeout + 20.0 + 10.0 * $batchSize;
            $estimated += $drainTimeout + $exitWait + $readyWait;
        }
        $estimated += 30.0;

        return (int) \max(self::WAIT_MIN_TIMEOUT, \min(self::WAIT_MAX_TIMEOUT, (int) \ceil($estimated)));
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
        $attempted = \is_array($result['attempted'] ?? null) ? $result['attempted'] : [];
        $succeeded = \is_array($result['succeeded'] ?? null) ? $result['succeeded'] : [];
        $failedByInstance = \is_array($result['failed_by_instance'] ?? null) ? $result['failed_by_instance'] : [];
        $resultsByInstance = \is_array($result['results_by_instance'] ?? null) ? $result['results_by_instance'] : [];

        $this->printer->note(
            'IPC dispatch: attempted=' . (\implode(',', \array_map('strval', $attempted)) ?: '(none)')
            . ', succeeded=' . (\implode(',', \array_map('strval', $succeeded)) ?: '(none)')
        );
        foreach ($resultsByInstance as $targetInstance => $ipcResult) {
            if (!\is_array($ipcResult)) {
                continue;
            }
            $message = (string)($ipcResult['message'] ?? '');
            $data = \is_array($ipcResult['data'] ?? null) ? $ipcResult['data'] : [];
            $dataJson = $data !== []
                ? \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '{}';
            $this->printer->note(
                'IPC response[' . (string)$targetInstance . ']: success=' . (!empty($ipcResult['success']) ? '1' : '0')
                . ', message=' . ($message !== '' ? $message : '(empty)')
                . ', data=' . ($dataJson !== false ? $dataJson : '{}')
            );
        }
        if ($failedByInstance !== []) {
            foreach ($failedByInstance as $failedInstance => $reason) {
                $this->printer->warning('IPC dispatch failed: ' . $failedInstance . ' => ' . (string) $reason);
            }
        }

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
        $optionKeys = [
            'f' => true,
            'force' => true,
            'r' => true,
            'restart' => true,
            'n' => true,
            'no-wait' => true,
            'instance' => true,
            'name' => true,
            'h' => true,
            'help' => true,
        ];

        if (isset($args['instance']) && \is_string($args['instance']) && \trim($args['instance']) !== '') {
            return \trim($args['instance']);
        }

        if (isset($args['name']) && \is_string($args['name']) && \trim($args['name']) !== '') {
            return \trim($args['name']);
        }

        foreach ($args as $key => $val) {
            if (\is_int($key) && \is_string($val) && !str_starts_with($val, '-') && !str_contains($val, ':')) {
                return $val;
            }
        }

        // 兼容部分命令解析器：`server:reload test` 可能被解析为 ['test' => true]
        foreach ($args as $key => $val) {
            if (!\is_string($key) || isset($optionKeys[$key])) {
                continue;
            }
            if (!\is_bool($val) || $val !== true) {
                continue;
            }
            if (\str_starts_with($key, '-')) {
                continue;
            }

            return \trim($key) !== '' ? \trim($key) : 'default';
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
            __('通知 Orchestrator 执行滚动重启。修改 Worker 代码后使用此命令即可生效。'),
            [
                '[instance]' => __('实例名称（默认：default）'),
                '-f, --force' => __('强制模式：批量杀死所有 Worker 后重新启动（停机型更新，不等待排水，建议先开启维护模式）'),
                '-n, --no-wait' => __('不等待：发送命令后立即返回'),
            ],
            [
                __('默认行为') => __('等待滚动重启完成后返回，显示进度（-f 强制模式除外）'),
                __('-f 强制模式') => __('批量重启：直接杀死所有 Worker，属于停机型更新，快速但会中断请求'),
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
