<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use DateTimeImmutable;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeProcessIdentity;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeProcessProbe;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerCommand;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerInvocation;
use Weline\Framework\Service\Runtime\ResumableTaskPolicyHydrator;
use Weline\Framework\Service\Runtime\ResumableTaskStore;
use Weline\Server\Runtime\Resumable\RuntimeWatchdogGatewayInterface;
use Weline\Server\Runtime\Resumable\RuntimeWatchdogSubject;

/** ORM adapter for the process-agnostic Runtime watchdog. */
final class ResumableTaskWatchdogGateway implements RuntimeWatchdogGatewayInterface
{
    public function __construct(private readonly ResumableTaskStore $store)
    {
    }

    public function dueSubjects(DateTimeImmutable $now, int $limit): iterable
    {
        foreach ($this->store->watchdogCandidates($limit) as $row) {
            $generation = (int)$row['fencing_generation'];
            if ($generation < 1) {
                continue;
            }
            $taskId = (string)$row['task_id'];
            $launchId = (string)($row['runner_launch_id'] ?? '');
            if ($launchId === '') {
                $launchId = 'released-' . $generation;
            }
            $processName = (string)($row['runner_process_name'] ?? '');
            if ($processName === '') {
                $processName = RuntimeProcessIdentity::buildProcessName($taskId, $generation);
            }
            $process = new RuntimeProcessIdentity(
                taskId: $taskId,
                generation: $generation,
                processName: $processName,
                launchId: $launchId,
                pid: max(0, (int)($row['runner_pid'] ?? 0)),
                liveCommand: (string)($row['runner_live_command'] ?? ''),
                startedAt: $this->date((string)($row['started_at'] ?? '')),
            );
            $policy = ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? []));
            $status = (string)$row['status'];
            $stopRequested = $status === 'cancel_requested' || !empty($row['recovery_stop_requested']);
            $leaseReleased = !empty($row['runner_lease_released']);
            $executionLease = $this->timestamp((string)($row['execution_lease_until'] ?? ''));
            $deadline = $this->timestamp((string)($row['stop_deadline_at'] ?? ''));
            yield new RuntimeWatchdogSubject(
                taskId: $taskId,
                process: $process,
                isTerminal: false,
                allClientLeasesExpired: !$this->store->hasActiveLeases($taskId, $now->getTimestamp()),
                // A null execution lease means the Runner has not claimed yet
                // (still starting/recovering). That is not an expired heartbeat.
                runnerHeartbeatExpired: !$leaseReleased
                    && $executionLease !== null
                    && $executionLease <= $now->getTimestamp(),
                stopRequested: $stopRequested,
                recoveryStopRequested: !empty($row['recovery_stop_requested']),
                cooperativeStopDeadlineReached: $deadline !== null && $deadline <= $now->getTimestamp(),
                forceTerminationConfirmationExpired: $deadline !== null
                    && $deadline + $policy->forceKillGraceSeconds <= $now->getTimestamp(),
                recoveryEligible: $policy->recoveryEnabled
                    && (int)$row['attempt'] < (int)$row['max_attempts']
                    && $status !== 'cancel_requested'
                    && $this->store->hasActiveLeases($taskId, $now->getTimestamp()),
                runnerLeaseReleased: $leaseReleased,
            );
        }
    }

    public function recordProcessProbe(RuntimeWatchdogSubject $subject, RuntimeProcessProbe $probe, DateTimeImmutable $now): void
    {
        if ($probe->allowsRecovery()) {
            $row = $this->store->findTask($subject->taskId);
            if ($row !== null) {
                $this->store->releaseRunnerLease(
                    $subject->taskId,
                    (int)$row['fencing_generation'],
                    (string)($row['runner_id'] ?? ''),
                );
            }
        }
    }

    public function requestCooperativeStop(RuntimeWatchdogSubject $subject, string $reason, DateTimeImmutable $now): void
    {
        $current = $this->store->findTask($subject->taskId);
        if ($current === null) {
            return;
        }
        $currentPolicy = ResumableTaskPolicyHydrator::fromArray((array)($current['policy'] ?? []));
        $row = $this->store->requestCooperativeStop(
            $subject->taskId,
            $reason,
            $now->getTimestamp() + $currentPolicy->cooperativeStopGraceSeconds,
        );
        if (in_array((string)$row['status'], ['expired', 'cancelled'], true)) {
            $policy = ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? []));
            $this->store->setTerminalRetention($subject->taskId, $now->getTimestamp() + $policy->terminalRetentionSeconds);
            $this->store->appendTerminalEventOnce(
                taskId: $subject->taskId,
                generation: (int)$row['fencing_generation'],
                event: (string)$row['status'],
                payload: ['task_id' => $subject->taskId, 'status' => $row['status'], 'reason' => $reason],
                eventLimit: $policy->maxEvents,
                bytesLimit: $policy->maxEventBacklogBytes,
            );
        }
    }

    public function requestRecoveryStop(RuntimeWatchdogSubject $subject, string $reason, DateTimeImmutable $now): void
    {
        $row = $this->store->findTask($subject->taskId);
        if ($row === null) {
            return;
        }
        $policy = ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? []));
        $this->store->requestRecoveryStop($subject->taskId, $now->getTimestamp() + $policy->cooperativeStopGraceSeconds);
    }

    public function finalizeStop(RuntimeWatchdogSubject $subject, RuntimeProcessProbe $probe, DateTimeImmutable $now): void
    {
        if (!$probe->allowsRecovery()) {
            return;
        }
        $row = $this->store->findTask($subject->taskId);
        if ($row === null || (string)$row['status'] !== 'cancel_requested') {
            return;
        }
        $policy = ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? []));
        $reason = (string)($row['cancel_reason'] ?? '');
        $status = $reason === 'client_lease_expired' ? 'expired' : 'cancelled';
        $terminal = $this->store->transition(
            $subject->taskId,
            (int)$row['fencing_generation'],
            $status,
            [
                'termination_reason' => $reason,
                'retain_until' => $now->modify('+' . $policy->terminalRetentionSeconds . ' seconds')->format('Y-m-d H:i:s'),
            ],
            (string)($row['runner_id'] ?? '') ?: null,
        );
        $this->store->appendTerminalEventOnce(
            taskId: $subject->taskId,
            generation: (int)$terminal['fencing_generation'],
            event: $status,
            payload: ['task_id' => $subject->taskId, 'status' => $status, 'reason' => $reason],
            eventLimit: $policy->maxEvents,
            bytesLimit: $policy->maxEventBacklogBytes,
            runnerId: (string)($terminal['runner_id'] ?? '') ?: null,
        );
        $this->store->releaseRunnerLease(
            $subject->taskId,
            (int)$terminal['fencing_generation'],
            (string)($terminal['runner_id'] ?? ''),
        );
    }

    public function claimRecovery(RuntimeWatchdogSubject $subject, DateTimeImmutable $now): ?RuntimeRunnerCommand
    {
        $row = $this->store->findTask($subject->taskId);
        if ($row === null || !in_array((string)$row['status'], ['running', 'recovering'], true)) {
            return null;
        }
        if ((string)$row['status'] === 'running') {
            $this->store->requestRecoveryStop($subject->taskId, $now->getTimestamp());
            $row = $this->store->findTask($subject->taskId);
            if ($row === null || (string)$row['status'] !== 'recovering') {
                return null;
            }
        }
        $generation = (int)$row['fencing_generation'] + 1;
        $runnerId = 'runner-' . bin2hex(random_bytes(12));
        $process = RuntimeProcessIdentity::forTask($subject->taskId, $generation);
        try {
            $reserved = $this->store->reserveRunner(
                $subject->taskId,
                $runnerId,
                $process->launchId,
                $process->processName,
                true,
            );
        } catch (\Throwable) {
            return null;
        }
        if ((int)$reserved['fencing_generation'] !== $generation) {
            return null;
        }
        return new RuntimeRunnerCommand(BP, new RuntimeRunnerInvocation($process, $runnerId));
    }

    public function recordRunnerLaunched(RuntimeWatchdogSubject $subject, RuntimeProcessIdentity $process, DateTimeImmutable $now): void
    {
        $row = $this->store->findTask($process->taskId);
        if ($row === null) {
            return;
        }
        $recorded = $this->store->recordRunnerLaunched(
            taskId: $process->taskId,
            generation: $process->generation,
            runnerId: (string)($row['runner_id'] ?? ''),
            launchId: $process->launchId,
            pid: $process->pid,
            liveCommand: $process->liveCommand,
        );
        if (!$recorded) {
            $this->store->revokeRunnerReservation(
                $process->taskId,
                $process->generation,
                (string)($row['runner_id'] ?? ''),
                $process->launchId,
            );
        }
    }

    public function recordRunnerLaunchFailure(RuntimeWatchdogSubject $subject, string $reason, DateTimeImmutable $now): void
    {
        $row = $this->store->findTask($subject->taskId);
        if ($row === null) {
            return;
        }
        $this->store->revokeRunnerReservation(
            $subject->taskId,
            (int)$row['fencing_generation'],
            (string)($row['runner_id'] ?? ''),
            (string)($row['runner_launch_id'] ?? ''),
        );
    }

    public function recordForceTermination(RuntimeWatchdogSubject $subject, RuntimeProcessProbe $probe, DateTimeImmutable $now): void
    {
        if ($probe->allowsRecovery()) {
            $row = $this->store->findTask($subject->taskId);
            if ($row === null) {
                return;
            }
            $this->store->releaseRunnerLease(
                $subject->taskId,
                $subject->process->generation,
                (string)($row['runner_id'] ?? ''),
            );
        }
    }

    public function recordRecoveryBlocked(RuntimeWatchdogSubject $subject, string $reason, DateTimeImmutable $now): void
    {
        $row = $this->store->findTask($subject->taskId);
        if ($row === null) {
            return;
        }
        $policy = ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? []));
        $terminal = $this->store->markRecoveryUnsafe(
            $subject->taskId,
            $reason,
            $now->getTimestamp() + $policy->terminalRetentionSeconds,
        );
        $this->store->appendTerminalEventOnce(
            taskId: $subject->taskId,
            generation: (int)$terminal['fencing_generation'],
            event: 'recovery_unsafe',
            payload: ['task_id' => $subject->taskId, 'status' => 'recovery_unsafe', 'reason' => $reason],
            eventLimit: $policy->maxEvents,
            bytesLimit: $policy->maxEventBacklogBytes,
            runnerId: (string)($terminal['runner_id'] ?? '') ?: null,
        );
    }

    private function timestamp(string $value): ?int
    {
        if (trim($value) === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $timestamp = $this->timestamp($value);
        return $timestamp === null ? null : (new DateTimeImmutable())->setTimestamp($timestamp);
    }
}
