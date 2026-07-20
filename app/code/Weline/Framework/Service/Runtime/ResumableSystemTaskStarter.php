<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Runtime\Resumable\ResumableSystemTaskStartHandlerInterface;
use Weline\Framework\Runtime\Resumable\ResumableSystemTaskStarterInterface;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\Resumable\TaskSnapshot;

/**
 * Applies a handler-owned server-only start policy before handing a task to
 * the durable Runtime. This class is intentionally not used by QueryProvider.
 */
final class ResumableSystemTaskStarter implements ResumableSystemTaskStarterInterface
{
    public function __construct(
        private readonly ResumableTaskHandlerRegistry $handlers,
        private readonly ResumableTaskRuntime $runtime,
    ) {
    }

    public function startForSystem(string $typeCode, array $input, string $systemPrincipal): TaskSnapshot
    {
        $systemPrincipal = trim($systemPrincipal);
        if (preg_match('/^system:[a-z][a-z0-9._:-]{2,127}$/', $systemPrincipal) !== 1) {
            throw new \InvalidArgumentException('System resumable task principal is invalid.');
        }

        $handler = $this->handlers->handler(trim($typeCode));
        if (!$handler instanceof ResumableSystemTaskStartHandlerInterface) {
            throw new ResumableTaskStoreException(
                'task_start_not_system_exposed',
                'The requested resumable task type is not server-startable.'
            );
        }

        $request = $handler->prepareSystemStart($input);
        if ($request->policy->requiresClientLease()) {
            throw new ResumableTaskStoreException(
                'invalid_system_task_policy',
                'A server-startable task must explicitly use system liveness.'
            );
        }

        return $this->runtime->startSystem(
            typeCode: $handler->typeCode(),
            input: $request->input,
            owner: new TaskOwner(area: 'system', principal: $systemPrincipal),
            policy: $request->policy,
            businessKey: $request->businessKey,
        );
    }
}
