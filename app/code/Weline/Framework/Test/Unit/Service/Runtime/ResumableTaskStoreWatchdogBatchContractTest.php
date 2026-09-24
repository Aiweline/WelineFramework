<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Runtime\ResumableTaskStore;

/** WS3-B: lease/task id list batching replaces per-task hasActiveLeases/findTask probes. */
final class ResumableTaskStoreWatchdogBatchContractTest extends TestCase
{
    public function testExposesLeaseAndTaskIdBatchApis(): void
    {
        self::assertTrue(method_exists(ResumableTaskStore::class, 'hasActiveLeasesForTaskIds'));
        self::assertTrue(method_exists(ResumableTaskStore::class, 'findTasksByIds'));
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/Runtime/ResumableTaskStore.php',
        );
        self::assertStringContainsString("schema_fields_TASK_ID, array_keys(\$normalized), 'IN'", $src);
        self::assertStringContainsString('function hasActiveLeasesForTaskIds', $src);
        self::assertStringContainsString('function findTasksByIds', $src);
        self::assertStringContainsString(
            'hasActiveLeasesForTaskIds([$taskId], $now)',
            $src,
        );
    }
}
