<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Runtime\Resumable\ResumableTaskStartHandlerInterface;
use Weline\Framework\Runtime\Resumable\ResumableTaskStarterInterface;
use Weline\Framework\Runtime\Resumable\TaskHandle;
use Weline\Framework\Runtime\Resumable\TaskOwner;

/**
 * Applies the registered handler's owner-aware start policy before delegating
 * to the durable runtime. This is not a Queue adapter and never runs a task in
 * the HTTP request.
 */
final class ResumableTaskStarter implements ResumableTaskStarterInterface
{
    public function __construct(
        private readonly ResumableTaskHandlerRegistry $handlers,
        private readonly ResumableTaskRuntime $runtime,
    ) {
    }

    public function startForOwner(string $typeCode, array $input, TaskOwner $owner): TaskHandle
    {
        $handler = $this->handlers->handler(trim($typeCode));
        if (!$handler instanceof ResumableTaskStartHandlerInterface) {
            throw new ResumableTaskStoreException(
                'task_start_not_exposed',
                'The requested resumable task type is not browser-startable.'
            );
        }

        $request = $handler->prepareStart($owner, $input);

        return $this->runtime->start(
            typeCode: $handler->typeCode(),
            input: $request->input,
            owner: $owner,
            policy: $request->policy,
            businessKey: $request->businessKey,
        );
    }
}
