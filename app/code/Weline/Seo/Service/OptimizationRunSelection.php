<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/** Chooses one deterministic run for a cycle detail request. */
final class OptimizationRunSelection
{
    /**
     * A requested run wins. Without it, the latest numeric run ID is the stable
     * cycle default regardless of activity-list ordering.
     *
     * @param array<int,int> $runIds
     * @return array<int,int>
     */
    public function select(array $runIds, int $requestedRunId = 0): array
    {
        if ($requestedRunId > 0) {
            return [$requestedRunId => $requestedRunId];
        }

        $normalized = [];
        foreach ($runIds as $runId) {
            $runId = (int)$runId;
            if ($runId > 0) {
                $normalized[$runId] = $runId;
            }
        }
        if ($normalized === []) {
            return [];
        }
        \krsort($normalized, \SORT_NUMERIC);
        $latest = (int)\array_key_first($normalized);

        return [$latest => $latest];
    }
}
