<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskResult;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerClaim;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerControl;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerExecutionDelegateInterface;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerExecutionResult;

/** Executes the registered business handler after the CLI Runner owns its fence. */
final class ResumableTaskRunnerDelegate implements RuntimeRunnerExecutionDelegateInterface
{
    public function __construct(
        private readonly ResumableTaskStore $store,
        private readonly ResumableTaskHandlerRegistry $handlers,
    ) {
    }

    public function execute(RuntimeRunnerClaim $claim, RuntimeRunnerControl $control): RuntimeRunnerExecutionResult
    {
        $row = $this->store->findTask($claim->taskId);
        if ($row === null || (int)$row['fencing_generation'] !== $claim->fencingGeneration
            || (string)$row['runner_id'] !== $claim->runnerId) {
            return RuntimeRunnerExecutionResult::staleFence();
        }
        $checkpointRow = $this->store->latestCheckpoint($claim->taskId);
        $checkpoint = $checkpointRow === null ? null : TaskCheckpoint::fromArray($checkpointRow);
        $policy = ResumableTaskPolicyHydrator::fromArray((array)($row['policy'] ?? []));
        $context = new ResumableTaskExecutionContext(
            store: $this->store,
            control: $control,
            id: $claim->taskId,
            runnerGeneration: $claim->fencingGeneration,
            runnerId: $claim->runnerId,
            runnerAttempt: $claim->attempt,
            policy: $policy,
            currentCheckpoint: $checkpoint,
        );

        try {
            $result = $this->handlers->handler((string)$row['type_code'])->execute(
                $context,
                (array)($row['input'] ?? []),
                $checkpoint,
            );
            if (!$result instanceof TaskResult) {
                throw new \RuntimeException('Resumable task handler must return TaskResult.');
            }
            return RuntimeRunnerExecutionResult::completed(['task_result' => $result->toArray()]);
        } catch (ResumableTaskStoreException $exception) {
            if ($exception->errorCode === 'event_backlog_limit') {
                $result = new TaskResult(
                    ResumableTaskStatus::EVENT_BACKLOG_LIMIT,
                    errorCode: 'event_backlog_limit',
                    terminalReason: 'Persistent event backlog limit was reached.',
                );
                return RuntimeRunnerExecutionResult::completed(['task_result' => $result->toArray()]);
            }
            throw $exception;
        }
    }
}
