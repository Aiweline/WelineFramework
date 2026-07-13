<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use DateTimeImmutable;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskResult;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerClaim;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerExecutionResult;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerInvocation;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerStoreInterface;

/** Durable runner-side fencing adapter; never touches HTTP/SSE state. */
final class ResumableTaskRunnerStore implements RuntimeRunnerStoreInterface
{
    public function __construct(private readonly ResumableTaskStore $store)
    {
    }

    public function acquire(RuntimeRunnerInvocation $invocation, DateTimeImmutable $now): ?RuntimeRunnerClaim
    {
        $process = $invocation->process;
        $row = $this->store->findTask($process->taskId);
        if ($row === null) {
            return null;
        }
        $policy = ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? []));
        $claimed = $this->store->acquireReservedRunner(
            taskId: $process->taskId,
            generation: $process->generation,
            runnerId: $invocation->runnerId,
            launchId: $process->launchId,
            pid: $process->pid,
            liveCommand: $process->liveCommand,
            leaseSeconds: $policy->runnerLeaseSeconds,
        );
        if ($claimed === null) {
            return null;
        }
        return new RuntimeRunnerClaim(
            taskId: $process->taskId,
            fencingGeneration: $process->generation,
            runnerId: $invocation->runnerId,
            attempt: (int)$claimed['attempt'],
        );
    }

    public function heartbeat(RuntimeRunnerClaim $claim, DateTimeImmutable $now): bool
    {
        $row = $this->store->findTask($claim->taskId);
        if ($row === null) {
            return false;
        }
        try {
            $policy = ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? []));
            $this->store->heartbeat(
                taskId: $claim->taskId,
                generation: $claim->fencingGeneration,
                leaseSeconds: $policy->runnerLeaseSeconds,
                runnerId: $claim->runnerId,
            );
            return true;
        } catch (ResumableTaskStoreException) {
            return false;
        }
    }

    public function isStopRequested(RuntimeRunnerClaim $claim): bool
    {
        $row = $this->store->findTask($claim->taskId);
        if ($row === null
            || (int)$row['fencing_generation'] !== $claim->fencingGeneration
            || (string)$row['runner_id'] !== $claim->runnerId) {
            return true;
        }
        return (string)$row['status'] === ResumableTaskStatus::CANCEL_REQUESTED->value
            || !empty($row['recovery_stop_requested']);
    }

    public function finish(RuntimeRunnerClaim $claim, RuntimeRunnerExecutionResult $result, DateTimeImmutable $now): void
    {
        $row = $this->store->findTask($claim->taskId);
        if ($row === null
            || (int)$row['fencing_generation'] !== $claim->fencingGeneration
            || (string)$row['runner_id'] !== $claim->runnerId) {
            return;
        }
        try {
            if ($result->status === RuntimeRunnerExecutionResult::STALE_FENCE) {
                return;
            }
            $policy = ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? []));
            if ((string)$row['status'] === ResumableTaskStatus::CANCEL_REQUESTED->value) {
                $this->finishCancellation($row, $claim, $policy, $now);
                return;
            }
            if ($result->status === RuntimeRunnerExecutionResult::STOPPED) {
                // A watchdog recovery stop is intentionally non-terminal. The
                // released flag lets it reserve a fresh generation safely.
                $this->store->releaseRunnerLease($claim->taskId, $claim->fencingGeneration, $claim->runnerId);
                return;
            }
            if ($result->status === RuntimeRunnerExecutionResult::COMPLETED) {
                $this->finishTaskResult($row, $claim, $policy, $result->result, $now);
                return;
            }
            $taskResult = TaskResult::failed(
                errorCode: $result->errorCode !== '' ? $result->errorCode : 'runner_exception',
                reason: $result->errorMessage,
            );
            $this->persistTerminalResult($row, $claim, $policy, $taskResult, $now);
        } finally {
            $this->store->releaseRunnerLease($claim->taskId, $claim->fencingGeneration, $claim->runnerId);
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $payload */
    private function finishTaskResult(
        array $row,
        RuntimeRunnerClaim $claim,
        \Weline\Framework\Runtime\Resumable\TaskPolicy $policy,
        array $payload,
        DateTimeImmutable $now,
    ): void {
        $resultRow = $payload['task_result'] ?? null;
        if (!is_array($resultRow)) {
            $this->persistTerminalResult(
                $row,
                $claim,
                $policy,
                TaskResult::failed('invalid_task_result', 'Runner returned no TaskResult.'),
                $now,
            );
            return;
        }
        try {
            $status = ResumableTaskStatus::from((string)($resultRow['status'] ?? ''));
            $taskResult = new TaskResult(
                $status,
                (array)($resultRow['data'] ?? []),
                (string)($resultRow['error_code'] ?? '') ?: null,
                (string)($resultRow['terminal_reason'] ?? ''),
            );
        } catch (\Throwable) {
            $taskResult = TaskResult::failed('invalid_task_result', 'Runner returned an invalid TaskResult.');
        }
        if (!in_array($taskResult->status, [
            ResumableTaskStatus::COMPLETED,
            ResumableTaskStatus::FAILED,
            ResumableTaskStatus::RECOVERY_UNSAFE,
            ResumableTaskStatus::EVENT_BACKLOG_LIMIT,
        ], true)) {
            $taskResult = TaskResult::failed('invalid_task_result', 'Runner returned an unsupported terminal TaskResult.');
        }
        $this->persistTerminalResult($row, $claim, $policy, $taskResult, $now);
    }

    /** @param array<string,mixed> $row */
    private function persistTerminalResult(
        array $row,
        RuntimeRunnerClaim $claim,
        \Weline\Framework\Runtime\Resumable\TaskPolicy $policy,
        TaskResult $result,
        DateTimeImmutable $now,
    ): void {
        $terminal = $this->store->transition(
            taskId: $claim->taskId,
            generation: $claim->fencingGeneration,
            status: $result->status->value,
            patch: [
                'result_json' => $result->data,
                'failure_code' => $result->errorCode ?? '',
                'termination_reason' => $result->terminalReason,
                'retain_until' => $now->modify('+' . $policy->terminalRetentionSeconds . ' seconds')->format('Y-m-d H:i:s'),
            ],
            runnerId: $claim->runnerId,
        );
        $this->store->appendTerminalEventOnce(
            taskId: $claim->taskId,
            generation: $claim->fencingGeneration,
            event: $result->status->value,
            payload: [
                'task_id' => $claim->taskId,
                'status' => $result->status->value,
                'result' => $result->data,
                'error_code' => $result->errorCode,
                'reason' => $result->terminalReason,
            ],
            eventLimit: $policy->maxEvents,
            bytesLimit: $policy->maxEventBacklogBytes,
            runnerId: $claim->runnerId,
        );
    }

    /** @param array<string,mixed> $row */
    private function finishCancellation(
        array $row,
        RuntimeRunnerClaim $claim,
        \Weline\Framework\Runtime\Resumable\TaskPolicy $policy,
        DateTimeImmutable $now,
    ): void {
        $reason = (string)($row['cancel_reason'] ?? '');
        $status = $reason === 'client_lease_expired'
            ? ResumableTaskStatus::EXPIRED
            : ResumableTaskStatus::CANCELLED;
        $this->store->transition(
            taskId: $claim->taskId,
            generation: $claim->fencingGeneration,
            status: $status->value,
            patch: [
                'termination_reason' => $reason,
                'retain_until' => $now->modify('+' . $policy->terminalRetentionSeconds . ' seconds')->format('Y-m-d H:i:s'),
            ],
            runnerId: $claim->runnerId,
        );
        $this->store->appendTerminalEventOnce(
            taskId: $claim->taskId,
            generation: $claim->fencingGeneration,
            event: $status->value,
            payload: ['task_id' => $claim->taskId, 'status' => $status->value, 'reason' => $reason],
            eventLimit: $policy->maxEvents,
            bytesLimit: $policy->maxEventBacklogBytes,
            runnerId: $claim->runnerId,
        );
    }
}
