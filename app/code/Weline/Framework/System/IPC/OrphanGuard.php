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
    protected IpcLoggerInterface $logger;

    public function __construct(
        private readonly int $checkIntervalSec = 5,
        private readonly int $deadThreshold = 3,
        private readonly int $unknownDisconnectThreshold = 12,
        ?IpcLoggerInterface $logger = null,
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
            return false;
        }

        $now = \time();
        if (($now - $this->lastCheckTs) < $this->checkIntervalSec) {
            return false;
        }
        $this->lastCheckTs = $now;

        if ($ipcConnected) {
            $this->deadCount = 0;
            $this->unknownDisconnectCount = 0;
            return false;
        }

        if ($masterPid <= 0) {
            $this->unknownDisconnectCount++;
            $this->logger->warning(
                "[{$selfTag}] Master PID 未知且 IPC 断开 ({$this->unknownDisconnectCount}/{$this->unknownDisconnectThreshold})"
            );
            return $this->unknownDisconnectCount >= $this->unknownDisconnectThreshold;
        }

        if ($this->masterAlive($masterPid)) {
            $this->deadCount = 0;
            $this->unknownDisconnectCount = 0;
            return false;
        }

        $this->deadCount++;
        $this->logger->warning(
            "[{$selfTag}] Master PID {$masterPid} 不可达且 IPC 断开 ({$this->deadCount}/{$this->deadThreshold})"
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

        if (\defined('IS_WIN') && IS_WIN) {
            return Processer::isRunningByPid($masterPid);
        }

        if (@\file_exists("/proc/{$masterPid}")) {
            return true;
        }

        @\exec("kill -0 {$masterPid} 2>/dev/null", $output, $code);
        return $code === 0;
    }
}
