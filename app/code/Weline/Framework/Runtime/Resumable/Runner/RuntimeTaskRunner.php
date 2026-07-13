<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use DateTimeImmutable;
use Throwable;
use Weline\Framework\Runtime\Resumable\TaskStopRequestedException;

/**
 * Independent process entrypoint adapter.
 *
 * It never accepts an HTTP request, EventSource or Fiber. The command handler
 * later wires this to the durable task runtime and calls run().
 */
final class RuntimeTaskRunner
{
    public function __construct(
        private readonly RuntimeRunnerStoreInterface $store,
        private readonly RuntimeRunnerExecutionDelegateInterface $delegate,
    ) {
    }

    public function run(RuntimeRunnerInvocation $invocation): RuntimeRunnerExecutionResult
    {
        $claim = $this->store->acquire($invocation, new DateTimeImmutable('now'));
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
