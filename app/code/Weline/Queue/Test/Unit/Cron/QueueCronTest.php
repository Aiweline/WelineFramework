<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;

final class QueueCronTest extends TestCase
{
    public function testReconcileRunningQueuesTreatsDoneMarkerAsTerminalSuccess(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Cron/Queue.php');
        $reconcileSource = $this->extractPrivateMethodSource($source, 'reconcileRunningQueues');

        self::assertStringContainsString('hasQueueDoneMarker($output, $queue)', $reconcileSource);
        self::assertStringContainsString("str_contains(\$haystack, 'QUEUE_DONE')", $source);
        self::assertLessThan(
            \strpos($reconcileSource, 'setStatus($queue::status_error)'),
            \strpos($reconcileSource, 'hasQueueDoneMarker($output, $queue)'),
            'QUEUE_DONE marker must be checked before marking a reconciled queue as error.'
        );
    }

    private function extractPrivateMethodSource(string $source, string $methodName): string
    {
        $methodOffset = \strpos($source, 'private function ' . $methodName);
        self::assertNotFalse($methodOffset, $methodName . ' missing');
        $nextMethodOffset = \strpos($source, 'private function ', $methodOffset + 1);

        return $nextMethodOffset === false
            ? \substr($source, $methodOffset)
            : \substr($source, $methodOffset, $nextMethodOffset - $methodOffset);
    }
}
