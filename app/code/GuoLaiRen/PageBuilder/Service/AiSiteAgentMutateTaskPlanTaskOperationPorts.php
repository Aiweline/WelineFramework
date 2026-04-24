<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteAgentMutateTaskPlanTaskOperationPorts
{
    /**
     * @param \Closure(mixed ...$args):array<string,mixed> $startOperation
     * @param \Closure(mixed ...$args):array<string,mixed> $buildWorkspaceState
     */
    public function __construct(
        public readonly \Closure $startOperation,
        public readonly \Closure $buildWorkspaceState,
    ) {
    }
}

