<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

/**
 * Bridges the isolated process loop to the resumable task handler/runtime.
 */
interface RuntimeRunnerExecutionDelegateInterface
{
    public function execute(RuntimeRunnerClaim $claim, RuntimeRunnerControl $control): RuntimeRunnerExecutionResult;
}
