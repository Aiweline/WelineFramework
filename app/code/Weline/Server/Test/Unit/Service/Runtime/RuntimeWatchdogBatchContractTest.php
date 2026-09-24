<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Runtime\Task\Watch;
use Weline\Server\Service\Runtime\ResumableTaskWatchdogGateway;

/** WS3-B: watchdog dueSubjects batches leases; Watch idles with bounded backoff. */
final class RuntimeWatchdogBatchContractTest extends TestCase
{
    public function testDueSubjectsBatchesActiveLeaseLookup(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/Runtime/ResumableTaskWatchdogGateway.php',
        );
        self::assertStringContainsString('hasActiveLeasesForTaskIds', $src);
        self::assertStringNotContainsString(
            'hasActiveLeases($taskId, $now->getTimestamp())',
            $src,
        );
    }

    public function testWatchDaemonIdlesWithBoundedBackoff(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Console/Runtime/Task/Watch.php',
        );
        self::assertStringContainsString('IDLE_TICK_MAX_MILLISECONDS', $src);
        self::assertStringContainsString('inspected > 0', $src);
        self::assertTrue((new \ReflectionClass(Watch::class))->hasConstant('IDLE_TICK_MAX_MILLISECONDS'));
    }
}
