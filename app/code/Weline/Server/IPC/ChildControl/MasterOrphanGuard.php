<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

use Weline\Framework\System\Process\Processer;
use Weline\Server\Log\WlsLogger;

final class MasterOrphanGuard
{
    private int $lastCheckTs = 0;
    private int $deadCount = 0;
    private int $unknownMasterDisconnectCount = 0;

    public function __construct(
        private readonly int $checkIntervalSec = 5,
        private readonly int $deadThreshold = 3,
        private readonly int $unknownMasterDisconnectThreshold = 12
    ) {
    }

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
            $this->unknownMasterDisconnectCount = 0;
            return false;
        }

        // master_pid 缺失时，无法做 PID 存活判断。
        // 此时以 IPC 断连持续时长作为兜底判定，避免子进程长期孤儿化。
        if ($masterPid <= 0) {
            $this->unknownMasterDisconnectCount++;
            WlsLogger::warning_(
                "[{$selfTag}] master_pid 缺失且 IPC 断开 ({$this->unknownMasterDisconnectCount}/{$this->unknownMasterDisconnectThreshold})"
            );
            return $this->unknownMasterDisconnectCount >= $this->unknownMasterDisconnectThreshold;
        }

        if ($this->masterAlive($masterPid)) {
            $this->deadCount = 0;
            $this->unknownMasterDisconnectCount = 0;
            return false;
        }

        $this->deadCount++;
        WlsLogger::warning_("[{$selfTag}] Master PID {$masterPid} 不可达且 IPC 断开 ({$this->deadCount}/{$this->deadThreshold})");
        return $this->deadCount >= $this->deadThreshold;
    }

    private function masterAlive(int $masterPid): bool
    {
        if (\function_exists('posix_kill')) {
            $alive = @\posix_kill($masterPid, 0);
            if (!$alive && \function_exists('posix_get_last_error')) {
                $errno = (int)@\posix_get_last_error();
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

