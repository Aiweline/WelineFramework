<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use DateTimeImmutable;
use Throwable;
use Weline\Framework\Runtime\Resumable\TaskStopRequestedException;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Independent process entrypoint adapter.
 *
 * It never accepts an HTTP request, EventSource or Fiber. The command handler
 * later wires this to the durable task runtime and calls run().
 */
final class RuntimeTaskRunner
{
    private const ACQUIRE_ATTEMPTS = 10;
    private const ACQUIRE_RETRY_DELAY_MICROSECONDS = 10_000;

    public function __construct(
        private readonly RuntimeRunnerStoreInterface $store,
        private readonly RuntimeRunnerExecutionDelegateInterface $delegate,
    ) {
    }

    public function run(RuntimeRunnerInvocation $invocation): RuntimeRunnerExecutionResult
    {
        $claim = null;
        for ($attempt = 1; $attempt <= self::ACQUIRE_ATTEMPTS; $attempt++) {
            $claim = $this->store->acquire($invocation, new DateTimeImmutable('now'));
            if ($claim !== null) {
                break;
            }
            if ($attempt < self::ACQUIRE_ATTEMPTS) {
                // The parent persists the launch identity immediately after
                // fork/exec. A concurrent CAS can make the first claim look
                // stale for a few milliseconds; bounded retries preserve the
                // fence while avoiding a permanent orphan in `starting`.
                SchedulerSystem::usleep(self::ACQUIRE_RETRY_DELAY_MICROSECONDS);
            }
        }
        if ($claim === null) {
            return RuntimeRunnerExecutionResult::staleFence();
        }

        $control = new RuntimeRunnerControl($this->store, $claim);
        $result = RuntimeRunnerExecutionResult::failed(new \RuntimeException('Runtime Runner did not produce a result.'));

        try {
            $control->heartbeat();
            $control->throwIfStopRequested();
            $result = $this->delegate->execute($claim, $control);
        } catch (RuntimeRunnerStopRequestedException|TaskStopRequestedException) {
            $result = RuntimeRunnerExecutionResult::stopped();
        } catch (RuntimeRunnerFenceLostException) {
            $result = RuntimeRunnerExecutionResult::staleFence();
        } catch (Throwable $throwable) {
            $result = RuntimeRunnerExecutionResult::failed($throwable);
        } finally {
            $this->store->finish($claim, $result, new DateTimeImmutable('now'));
        }

        return $result;
    }
}
