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
        ?MasterLeaseManager $leaseManager = null,
        private readonly bool $strictLeaseFreshness = false,
    ) {
        $this->leaseManager = $leaseManager ?? new MasterLeaseManager();
    }


    public function isEnabled(): bool
    {
        return $this->strictLeaseFreshness
            || $this->masterPid > 0
            || ($this->leaseFile !== '' && $this->masterToken !== '');
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

        $now = \hrtime(true) / 1_000_000_000;
        if (!\is_finite($now) || $now <= 0.0) {
            $this->lastExitReason = 'Master guard monotonic clock is invalid';
            return true;
        }
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
            if ($this->strictLeaseFreshness) {
                return 'Master lease identity is incomplete';
            }
            return $this->isMasterPidMissing() ? "Master PID {$this->masterPid} missing" : '';
        }

        $validation = $this->leaseManager->validateProtectedChildCredential(
            $this->leaseFile,
            $this->instance,
            $this->masterPid,
            $this->masterEpoch,
            $this->masterToken,
            $this->strictLeaseFreshness,
        );
        if (($validation['authorized'] ?? false) === true) {
            return '';
        }

        $reason = \trim((string)($validation['reason'] ?? ''));
        return $reason !== ''
            ? $reason
            : 'Master lease identity or heartbeat is not authorized';
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
