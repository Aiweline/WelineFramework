<?php
declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

use Weline\Framework\System\Process\Processer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\MasterLeaseManager;

/**
 * Child process liveness guard for the current Master.
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
        $this->log('warning', "[{$this->selfTag}] {$reason}: {$message}; child exits");
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
        if ($this->leaseFile === '' || $this->masterToken === '') {
            return $this->isMasterPidMissing() ? "Master PID {$this->masterPid} missing" : '';
        }

        $lease = $this->leaseManager->read($this->leaseFile);
        if ($lease === null) {
            return 'Master lease file missing or invalid: ' . $this->leaseFile;
        }

        $state = (string)($lease['state'] ?? '');
        if ($state !== MasterLeaseManager::STATE_RUNNING) {
            return "Master lease state={$state}; expected running";
        }

        $leasePid = (int)($lease['master_pid'] ?? 0);
        if ($this->masterPid > 0 && $leasePid !== $this->masterPid) {
            return "Master lease PID mismatch: lease={$leasePid}, expected={$this->masterPid}";
        }

        $leaseToken = (string)($lease['master_token'] ?? '');
        if ($leaseToken === '' || !\hash_equals($leaseToken, $this->masterToken)) {
            return 'Master lease token mismatch';
        }

        $leaseInstance = (string)($lease['instance'] ?? '');
        if ($this->instance !== '' && $leaseInstance !== $this->instance) {
            return "Master lease instance mismatch: lease={$leaseInstance}, expected={$this->instance}";
        }

        $leaseEpoch = (int)($lease['master_epoch'] ?? 0);
        if ($this->masterEpoch > 0 && $leaseEpoch > 0 && $leaseEpoch !== $this->masterEpoch) {
            return "Master lease epoch mismatch: lease={$leaseEpoch}, expected={$this->masterEpoch}";
        }

        $updatedAt = (float)($lease['updated_at'] ?? 0.0);
        $leaseFresh = $updatedAt <= 0.0
            || (\microtime(true) - $updatedAt) <= MasterLeaseManager::HEARTBEAT_STALE_SEC;
        if ($leaseFresh) {
            return '';
        }

        if ($this->masterPid <= 0 || $this->isMasterPidMissing()) {
            return 'Master lease heartbeat stale and Master PID missing';
        }

        return '';
    }

    private function isMasterPidMissing(): bool
    {
        return $this->masterPid > 0 && !Processer::isRunningByPid($this->masterPid);
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
