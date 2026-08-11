<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

/**
 * Carries one caller-owned monotonic deadline across a complete control
 * operation without widening the legacy ChildControlClientInterface.
 */
interface OperationDeadlineAwareClientInterface
{
    public function setOperationDeadlineMonotonic(?float $deadlineMonotonic): void;
}
