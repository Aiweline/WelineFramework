<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;

final class QueueCronTest extends TestCase
{
    public function testReconcileRunningQueuesTreatsDoneMarkerAsTerminalSuccess(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/QueueDispatchService.php');
        $reconcileSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        self::assertStringContainsString('hasQueueDoneMarker($output, $queue)', $reconcileSource);
        self::assertStringContainsString("str_contains(\$haystack, 'QUEUE_DONE')", $source);
        self::assertLessThan(
            \strpos($reconcileSource, 'setStatus($queue::status_error)'),
            \strpos($reconcileSource, 'hasQueueDoneMarker($output, $queue)'),
            'QUEUE_DONE marker must be checked before marking a reconciled queue as error.'
        );
    }

    public function testCronDelegatesDispatchToQueueDispatchService(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Cron/Queue.php');

        self::assertStringContainsString('QueueDispatchService', $source);
        self::assertStringContainsString('$this->queueDispatchService->dispatchPendingAutoQueues();', $source);
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
