<?php
declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

use Weline\Framework\System\Process\Processer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\MasterLeaseManager;

/**
 * 子进程自治守卫：PID + Master lease 双重判断当前 Master 是否仍有效。
 */
class ChildMasterGuard
{
    private float $lastCheckAt = 0.0;
    private string $lastExitReason = '';
    private MasterLeaseManager $leaseManager;

    public function __construct(
        private readonly int $masterPid,
        private readonly string $leaseFile,
        private readonly string $masterToken,
        private readonly string $selfTag,
        private readonly string $instance = '',
        private readonly int $masterEpoch = 0,
        private readonly float $checkIntervalSec = 2.0,
        ?MasterLeaseManager $leaseManager = null
    ) {
        $this->leaseManager = $leaseManager ?? new MasterLeaseManager();
    }

    public function isEnabled(): bool
    {
        return $this->masterPid > 0 || ($this->leaseFile !== '' && $this->masterToken !== '');
    }

    public function getLastExitReason(): string
    {
        return $this->lastExitReason;
    }

    public function assertAliveOrExit(string $reason): void
    {
        if (!$this->shouldExit(true)) {
            return;
        }

        $message = $this->lastExitReason !== ''
            ? $this->lastExitReason
            : 'Master lease/PID check failed';
        $this->log('warning', "[{$this->selfTag}] {$reason}: {$message}，子进程自行退出");
        exit(0);
    }

    public function shouldExit(bool $force = false): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $now = \microtime(true);
        if (!$force && ($now - $this->lastCheckAt) < $this->checkIntervalSec) {
            return false;
        }
        $this->lastCheckAt = $now;

        $reason = $this->evaluateExitReason();
        if ($reason === '') {
            $this->lastExitReason = '';
            return false;
        }

        $this->lastExitReason = $reason;
        $this->log('warning', "[{$this->selfTag}] {$reason}");
        return true;
    }

    private function evaluateExitReason(): string
    {
        if ($this->masterPid > 0 && !Processer::isRunningByPid($this->masterPid)) {
            return "Master PID {$this->masterPid} 不存在";
        }

        if ($this->leaseFile === '' || $this->masterToken === '') {
            return '';
        }

        $lease = $this->leaseManager->read($this->leaseFile);
        if ($lease === null) {
            return 'Master lease 文件不存在或不可解析: ' . $this->leaseFile;
        }

        $state = (string)($lease['state'] ?? '');
        if ($state !== MasterLeaseManager::STATE_RUNNING) {
            return "Master lease state={$state}，不是 running";
        }

        $leasePid = (int)($lease['master_pid'] ?? 0);
        if ($this->masterPid > 0 && $leasePid !== $this->masterPid) {
            return "Master lease PID 不匹配: lease={$leasePid}, expected={$this->masterPid}";
        }

        $leaseToken = (string)($lease['master_token'] ?? '');
        if ($leaseToken === '' || !\hash_equals($leaseToken, $this->masterToken)) {
            return 'Master lease token 不匹配';
        }

        $leaseInstance = (string)($lease['instance'] ?? '');
        if ($this->instance !== '' && $leaseInstance !== $this->instance) {
            return "Master lease instance 不匹配: lease={$leaseInstance}, expected={$this->instance}";
        }

        $leaseEpoch = (int)($lease['master_epoch'] ?? 0);
        if ($this->masterEpoch > 0 && $leaseEpoch > 0 && $leaseEpoch !== $this->masterEpoch) {
            return "Master lease epoch 不匹配: lease={$leaseEpoch}, expected={$this->masterEpoch}";
        }

        $updatedAt = (float)($lease['updated_at'] ?? 0.0);
        if ($updatedAt > 0.0 && (\microtime(true) - $updatedAt) > MasterLeaseManager::HEARTBEAT_STALE_SEC) {
            if ($this->masterPid <= 0 || !Processer::isRunningByPid($this->masterPid)) {
                return 'Master lease 心跳超时且 Master PID 不存在';
            }
        }

        return '';
    }

    private function log(string $level, string $message): void
    {
        try {
            if ($level === 'warning') {
                WlsLogger::warning_($message);
                return;
            }
            WlsLogger::info_($message);
        } catch (\Throwable) {
            \error_log($message);
        }
    }
}
