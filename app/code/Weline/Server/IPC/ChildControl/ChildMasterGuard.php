<?php
declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\MasterLeaseManager;

/**
 * Child process liveness guard for the current Master.
 */
class ChildMasterGuard
{
    private const TRANSIENT_OWNER_UNKNOWN_RECHECK_SEC = 5.0;
    private const TRANSIENT_OWNER_UNKNOWN_RECHECK_USEC = 50_000;
    /** 租约/凭据类断言的连续失败容忍次数；真孤儿（Master PID missing）不受此限。 */
    private const CREDENTIAL_ASSERT_RETRY_STREAK = 3;

    private float $lastCheckAt = 0.0;
    private string $lastExitReason = '';
    private int $credentialFailureStreak = 0;
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
            $this->credentialFailureStreak = 0;
            $this->lastExitReason = '';
            return false;
        }

        // 租约/凭据类断言在 writer「临时文件 + rename」窗口里会瞬时失败：
        // 连续 N 次才退出（判据不变，只看同一 reason 是否连续复现）。
        if ($this->isTransientLeaseCredentialReason($reason)) {
            ++$this->credentialFailureStreak;
            if ($this->credentialFailureStreak < self::CREDENTIAL_ASSERT_RETRY_STREAK) {
                WlsLogger::warning_(
                    'credential assertion retry tolerated'
                    . ' tag=' . $this->selfTag
                    . ' reason=' . $reason
                    . ' streak=' . $this->credentialFailureStreak
                    . ' max_streak=' . self::CREDENTIAL_ASSERT_RETRY_STREAK
                );
                return false;
            }
            WlsLogger::error_(
                'credential assertion retry exhausted; child exits'
                . ' tag=' . $this->selfTag
                . ' reason=' . $reason
                . ' streak=' . $this->credentialFailureStreak
            );
        } else {
            $this->credentialFailureStreak = 0;
        }

        $this->lastExitReason = $reason;
        $this->log('warning', "[{$this->selfTag}] {$reason}");
        return true;
    }

    /**
     * 只容忍「租约/凭据文件私有性断言」这一类瞬时失败（writer 的临时文件 + rename 窗口）。
     * 真孤儿与真实失效一律立刻退出：Master PID missing / lease not running / heartbeat stale /
     * identity incomplete / not uniquely authorized。
     */
    private function isTransientLeaseCredentialReason(string $reason): bool
    {
        foreach (['missing', 'not running', 'stale', 'incomplete', 'not uniquely authorized'] as $hardSignal) {
            if (\str_contains($reason, $hardSignal)) {
                return false;
            }
        }

        return \str_contains($reason, 'must be private, regular and unlinked');
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
        if (!$this->strictLeaseFreshness
            && ($validation['transient_owner_unknown'] ?? false) === true
        ) {
            // Darwin process-table evidence can be transiently unavailable under
            // request/FD pressure. Never authorize that UNKNOWN observation:
            // keep the worker only when a bounded recheck regains the complete
            // protected credential authorization. Persistent UNKNOWN and every
            // positive mismatch still retire the child.
            $deadline = (\hrtime(true) / 1_000_000_000)
                + self::TRANSIENT_OWNER_UNKNOWN_RECHECK_SEC;
            do {
                SchedulerSystem::usleep(self::TRANSIENT_OWNER_UNKNOWN_RECHECK_USEC);
                $validation = $this->leaseManager->validateProtectedChildCredential(
                    $this->leaseFile,
                    $this->instance,
                    $this->masterPid,
                    $this->masterEpoch,
                    $this->masterToken,
                    false,
                );
                if (($validation['authorized'] ?? false) === true) {
                    return '';
                }
                $reason = \trim((string)($validation['reason'] ?? ''));
                if (($validation['transient_owner_unknown'] ?? false) !== true) {
                    break;
                }
            } while ((\hrtime(true) / 1_000_000_000) < $deadline);
        }

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
