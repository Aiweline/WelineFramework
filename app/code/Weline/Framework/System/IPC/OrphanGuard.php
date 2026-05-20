<?php
declare(strict_types=1);

namespace Weline\Framework\System\IPC;

use Weline\Framework\System\Process\Processer;

/**
 * 孤儿进程保护
 *
 * 子进程通过此类检测主控进程（Master）是否存活。
 * 当 IPC 连接断开且 Master PID 不可达时，触发孤儿退出。
 *
 * 设计为与具体应用无关，WLS 和 Cron 均可使用。
 */
class OrphanGuard
{
    private int $lastCheckTs = 0;
    private int $deadCount = 0;
    private int $unknownDisconnectCount = 0;
    /** 记录首次断开时间戳，用于计算硬性超时 */
    private int $disconnectFirstDetectedAt = 0;
    protected IpcLoggerInterface $logger;

    /**
     * @param int $checkIntervalSec 检查间隔（秒）
     * @param int $deadThreshold Master PID 不可达时退出前检查次数
     * @param int $unknownDisconnectThreshold Master PID 未知时退出前检查次数
     * @param IpcLoggerInterface|null $logger 日志接口
     * @param int $maxExitWaitSec 硬性超时（秒），无论 IPC 连接状态如何，到达此时间后强制退出。默认 180 秒（3 分钟）
     */
    public function __construct(
        private readonly int $checkIntervalSec = 5,
        private readonly int $deadThreshold = 3,
        private readonly int $unknownDisconnectThreshold = 12,
        ?IpcLoggerInterface $logger = null,
        private readonly int $maxExitWaitSec = 180,
    ) {
        $this->logger = $logger ?? new NullIpcLogger();
    }

    /**
     * 判断子进程是否应当退出（孤儿化）
     *
     * @param int    $masterPid        主控进程 PID（0 = 未知）
     * @param bool   $ipcConnected     IPC 连接是否正常
     * @param bool   $receivedShutdown 是否已收到正常 shutdown 消息
     * @param string $selfTag          日志标识（如 "Worker#1"）
     */
    public function shouldExit(
        int $masterPid,
        bool $ipcConnected,
        bool $receivedShutdown,
        string $selfTag
    ): bool {
        if ($receivedShutdown) {
            // 收到 shutdown 信号，不退出
            $this->disconnectFirstDetectedAt = 0;
            return false;
        }

        $now = \time();
        if (($now - $this->lastCheckTs) < $this->checkIntervalSec) {
            // 未到检查间隔，跳过
            return false;
        }
        $this->lastCheckTs = $now;

        // IPC 连接正常：重置所有计数器
        if ($ipcConnected) {
            $this->deadCount = 0;
            $this->unknownDisconnectCount = 0;
            $this->disconnectFirstDetectedAt = 0;
            return false;
        }

        // IPC 断开：记录首次断开时间（如果尚未记录）
        if ($this->disconnectFirstDetectedAt === 0) {
            $this->disconnectFirstDetectedAt = $now;
        }

        // 计算已断开时长
        $disconnectedDuration = $now - $this->disconnectFirstDetectedAt;

        // ========== 硬性超时检查（最优先）==========
        // 无论 IPC 连接状态、Master 是否可达，只要断开超过 maxExitWaitSec 就强制退出
        // 防止子进程无限等待已死亡的 Master
        if ($disconnectedDuration >= $this->maxExitWaitSec) {
            $this->logger->warning(
                "[{$selfTag}] 硬性超时达到 ({$disconnectedDuration}s >= {$this->maxExitWaitSec}s)，强制退出"
            );
            $this->disconnectFirstDetectedAt = 0;
            return true;
        }

        // ========== Master PID 未知时的检查 ==========
        if ($masterPid <= 0) {
            $this->unknownDisconnectCount++;
            $this->logger->warning(
                "[{$selfTag}] Master PID 未知且 IPC 断开 ({$this->unknownDisconnectCount}/{$this->unknownDisconnectThreshold}, 已断开 {$disconnectedDuration}s, 剩余 " . ($this->maxExitWaitSec - $disconnectedDuration) . "s 硬性超时)"
            );
            return $this->unknownDisconnectCount >= $this->unknownDisconnectThreshold;
        }

        // ========== Master PID 已知但可能不可达时的检查 ==========
        if ($this->masterAlive($masterPid)) {
            $this->deadCount = 0;
            $this->unknownDisconnectCount = 0;
            return false;
        }

        $this->deadCount++;
        $this->logger->warning(
            "[{$selfTag}] Master PID {$masterPid} 不可达且 IPC 断开 ({$this->deadCount}/{$this->deadThreshold}, 已断开 {$disconnectedDuration}s, 剩余 " . ($this->maxExitWaitSec - $disconnectedDuration) . "s 硬性超时)"
        );
        return $this->deadCount >= $this->deadThreshold;
    }

    protected function masterAlive(int $masterPid): bool
    {
        if (\function_exists('posix_kill')) {
            $alive = @\posix_kill($masterPid, 0);
            if (!$alive && \function_exists('posix_get_last_error')) {
                $errno = (int)@\posix_get_last_error();
                // errno=1 (EPERM) 表示进程存在但无权限发信号
                if ($errno === 1) {
                    return true;
                }
            }
            return $alive;
        }

        if ($this->isWindows()) {
            return Processer::isRunningByPid($masterPid);
        }

        if (@\file_exists("/proc/{$masterPid}")) {
            return true;
        }

        @\exec("kill -0 {$masterPid} 2>/dev/null", $output, $code);
        return $code === 0;
    }

    private function isWindows(): bool
    {
        if (\defined('PHP_OS_FAMILY')) {
            return \PHP_OS_FAMILY === 'Windows';
        }

        return \stripos(\PHP_OS, 'WIN') === 0;
    }
}
