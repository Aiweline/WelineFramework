<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Worker rolling-reload batch planner shared by the Master and CLI.
 *
 * The planner never removes more workers than allowed by min_ready. Small pools
 * use the fewest safe batches instead of forcing one Worker per batch.
 */
final class WorkerRestartBatchPlanner
{
    /**
     * @param int[] $orderedInstanceIds
     * @return array<int, int[]>
     */
    public static function plan(
        array $orderedInstanceIds,
        bool $forceSingleBatch = false,
        int $threeBatchMinCount = 7,
        int $configuredBatchCount = 3,
        ?int $minReady = null
    ): array {
        $ids = \array_map('intval', \array_values($orderedInstanceIds));
        $workerCount = \count($ids);
        if ($workerCount === 0) {
            return [];
        }
        if ($forceSingleBatch) {
            return [$ids];
        }

        $threeBatchMinCount = $threeBatchMinCount >= 4 ? $threeBatchMinCount : 7;
        $configuredBatchCount = $configuredBatchCount >= 1 ? $configuredBatchCount : 3;
        $minReady ??= self::resolveMinReady($workerCount);
        $minReady = \max(0, \min($workerCount - 1, $minReady));
        $maxBatchSize = \max(1, $workerCount - $minReady);
        $requiredBatchCount = (int) \ceil($workerCount / $maxBatchSize);

        $batchCount = $workerCount < $threeBatchMinCount
            ? $requiredBatchCount
            : \max($configuredBatchCount, $requiredBatchCount);
        $batchCount = \max(1, \min($workerCount, $batchCount));

        $baseSize = intdiv($workerCount, $batchCount);
        $remainder = $workerCount % $batchCount;
        $batches = [];
        $offset = 0;
        for ($batch = 0; $batch < $batchCount; $batch++) {
            $size = $baseSize + ($batch < $remainder ? 1 : 0);
            if ($size <= 0) {
                break;
            }
            $batches[] = \array_slice($ids, $offset, $size);
            $offset += $size;
        }

        return $batches;
    }

    public static function resolveMinReady(int $workerCount, mixed $configured = null): int
    {
        if ($workerCount <= 1) {
            return 0;
        }

        $default = \max(1, intdiv($workerCount * 2, 3));
        $minReady = $default;
        if (\is_string($configured)) {
            $value = \strtolower(\trim($configured));
            if ($value === '' || $value === 'auto' || $value === 'default') {
                $minReady = $default;
            } elseif (\str_ends_with($value, '%') && \is_numeric(\substr($value, 0, -1))) {
                $ratio = \max(0.0, \min(100.0, (float) \substr($value, 0, -1))) / 100.0;
                $minReady = (int) \floor($workerCount * $ratio);
            } elseif (\is_numeric($value)) {
                $numeric = (float) $value;
                $minReady = $numeric > 0.0 && $numeric <= 1.0
                    ? (int) \floor($workerCount * $numeric)
                    : (int) $numeric;
            }
        } elseif (\is_int($configured) || \is_float($configured)) {
            $numeric = (float) $configured;
            $minReady = $numeric > 0.0 && $numeric <= 1.0
                ? (int) \floor($workerCount * $numeric)
                : (int) $numeric;
        }

        return \max(1, \min($workerCount - 1, $minReady));
    }
}
