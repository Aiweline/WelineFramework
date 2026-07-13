<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Process-scoped, allocation-free hook for Runtime maintenance work between
 * fixed pipeline stages. Implementations must never mutate request semantics.
 */
interface RequestPipelineStageListenerInterface
{
    public function afterRequestPipelineStage(string $stage, float $elapsedMilliseconds): void;
}
