<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/** Centralizes the fail-closed keep versus rollback threshold decision. */
final class OptimizationEvaluationDecision
{
    /** @param array<string,mixed> $assessment */
    public function shouldRollback(array $assessment, bool $expired): bool
    {
        if ($expired || !empty($assessment['guardrail_breached'])) {
            return true;
        }
        if (empty($assessment['sample_ready'])) {
            return false;
        }

        return !empty($assessment['primary_worsened'])
            || (float)($assessment['primary_relative_uplift'] ?? 0.0)
                < (float)($assessment['required_relative_uplift'] ?? 0.0);
    }
}
