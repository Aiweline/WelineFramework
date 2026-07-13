<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Runtime\Resumable\ResumableTaskRuntimeUnavailableException;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeProcessIdentity;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerCommand;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerInvocation;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerProcessLauncher;
use Weline\Framework\Runtime\Resumable\Runner\RuntimeRunnerProcessSupervisor;

/**
 * Initial/recovery process launcher for durable runtime tasks.
 *
 * It performs no business work. A task is first fenced in durable storage,
 * then launched through Processer, and finally the resulting PID is bound
 * back to the same reservation. Any failure revokes that reservation before
 * the caller can schedule a replacement.
 */
final class ResumableTaskRunnerLauncher implements ResumableTaskRunnerLauncherInterface
{
    public function __construct(
        private readonly ResumableTaskStore $store,
        private readonly RuntimeRunnerProcessLauncher $processLauncher,
        private readonly RuntimeRunnerProcessSupervisor $processSupervisor,
    ) {
    }

    public function launch(string $taskId, bool $recovery = false): void
    {
        $row = $this->store->findTask($taskId);
        if ($row === null) {
            throw new ResumableTaskRuntimeUnavailableException('Runtime task disappeared before Runner launch.');
        }

        $generation = (int)$row['fencing_generation'] + 1;
        $runnerId = 'runner-' . bin2hex(random_bytes(12));
        $process = RuntimeProcessIdentity::forTask($taskId, $generation);
        $reserved = $this->store->reserveRunner(
            $taskId,
            $runnerId,
            $process->launchId,
            $process->processName,
            $recovery,
        );
        if ((int)$reserved['fencing_generation'] !== $generation) {
            throw new ResumableTaskRuntimeUnavailableException('Runtime Runner reservation was superseded.');
        }

        $command = new RuntimeRunnerCommand(
            projectRoot: BP,
            invocation: new RuntimeRunnerInvocation($process, $runnerId),
        );
        try {
            $started = $this->processLauncher->launch($command);
            $recorded = $this->store->recordRunnerLaunched(
                taskId: $taskId,
                generation: $generation,
                runnerId: $runnerId,
                launchId: $process->launchId,
                pid: $started->pid,
                liveCommand: $started->liveCommand,
            );
            if (!$recorded) {
                // We deliberately terminate only a fully verified child. A
                // missing live command is fail-closed and gets no kill signal.
                if ($started->canSafelyTerminate()) {
                    $this->processSupervisor->forceTerminate($started);
                }
                $this->store->revokeRunnerReservation($taskId, $generation, $runnerId, $process->launchId);
                throw new ResumableTaskRuntimeUnavailableException('Runtime Runner launch reservation was lost.');
            }
        } catch (ResumableTaskRuntimeUnavailableException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            $this->store->revokeRunnerReservation($taskId, $generation, $runnerId, $process->launchId);
            throw new ResumableTaskRuntimeUnavailableException(
                'Unable to start the isolated Runtime Runner: ' . mb_substr($throwable->getMessage(), 0, 256),
                previous: $throwable,
            );
        }
    }
}
