<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class QueueDispatchServiceTest extends TestCase
{
    public function testQueueWorkerCommandCarriesDedicatedMemoryLimit(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $buildMethodSource = $this->extractPrivateMethodSource($source, 'buildQueueRunProcessName');

        self::assertStringContainsString('resolveWorkerMemoryLimit()', $buildMethodSource);
        self::assertStringContainsString('memory_limit=', $buildMethodSource);
        self::assertStringContainsString('queue:run --id=', $buildMethodSource);
        self::assertStringContainsString("private const DEFAULT_WORKER_MEMORY_LIMIT = '512M';", $source);
        self::assertStringContainsString("'queue.worker.memory_limit'", $source);
    }

    public function testQueueWorkerMemoryLimitNormalizationAcceptsPhpIniUnits(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $normalizeMethodSource = $this->extractPrivateMethodSource($source, 'normalizeMemoryLimit');

        self::assertStringContainsString("if (\$value === '-1')", $normalizeMethodSource);
        self::assertStringContainsString("return \$value . 'M';", $normalizeMethodSource);
        self::assertStringContainsString('/^[1-9]\d*(?:K|M|G)$/', $normalizeMethodSource);
        self::assertStringContainsString('return $default;', $normalizeMethodSource);
    }

    public function testReconcileRepairsFinishedRunningQueues(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $reconcileMethodSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        self::assertStringNotContainsString('schema_fields_finished, 0', $reconcileMethodSource);
        self::assertStringContainsString('$queue->isFinished()', $reconcileMethodSource);

        $finishedOffset = \strpos($reconcileMethodSource, '$queue->isFinished()');
        $pendingOffset = \strpos($reconcileMethodSource, 'setStatus($queue::status_pending)');
        self::assertNotFalse($finishedOffset);
        self::assertNotFalse($pendingOffset);
        self::assertLessThan(
            $pendingOffset,
            $finishedOffset,
            'Finished running queues must be completed before no-PID rows are reset to pending.'
        );
    }

    private function extractPrivateMethodSource(string $source, string $methodName): string
    {
        $methodOffset = \strpos($source, 'private function ' . $methodName);
        if ($methodOffset === false) {
            $methodOffset = \strpos($source, 'public function ' . $methodName);
        }
        self::assertNotFalse($methodOffset, $methodName . ' missing');
        $nextPrivateMethodOffset = \strpos($source, 'private function ', $methodOffset + 1);
        $nextPublicMethodOffset = \strpos($source, 'public function ', $methodOffset + 1);
        $methodOffsets = \array_filter(
            [$nextPrivateMethodOffset, $nextPublicMethodOffset],
            static fn (int|false $offset): bool => $offset !== false
        );
        $nextMethodOffset = $methodOffsets === [] ? false : \min($methodOffsets);

        return $nextMethodOffset === false
            ? \substr($source, $methodOffset)
            : \substr($source, $methodOffset, $nextMethodOffset - $methodOffset);
    }
}
