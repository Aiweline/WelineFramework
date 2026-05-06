<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\test\Unit\Cron;

use PHPUnit\Framework\TestCase;

final class QueueWatchdogTest extends TestCase
{
    public function testCronRunsEveryMinuteAndCapsRetriesAtThree(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Cron/QueueWatchdog.php');

        self::assertStringContainsString("return '*/1 * * * *';", $source);
        self::assertStringContainsString('private const MAX_RETRY_COUNT = 3;', $source);
        self::assertStringContainsString("private const WATCHDOG_RETRY_KEY = 'watchdog_retry_count';", $source);
    }

    public function testWatchdogResetsBuildQueueAndSessionBackToPending(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Cron/QueueWatchdog.php');

        self::assertStringContainsString('resetUnfinishedTasksForQueueRetry($scope, $message)', $source);
        self::assertStringContainsString('->setStatus(Queue::status_pending)', $source);
        self::assertStringContainsString("'queue_waiting_for_scheduler' => true", $source);
        self::assertStringContainsString("'operation' => 'build'", $source);
    }

    public function testExhaustedRetriesStopAutomaticRequeue(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Cron/QueueWatchdog.php');

        self::assertStringContainsString('if ($retryCount >= self::MAX_RETRY_COUNT)', $source);
        self::assertStringContainsString('private const WATCHDOG_EXHAUSTED_KEY = \'watchdog_retry_exhausted\';', $source);
        self::assertStringContainsString("'status' => 'error'", $source);
    }
}
