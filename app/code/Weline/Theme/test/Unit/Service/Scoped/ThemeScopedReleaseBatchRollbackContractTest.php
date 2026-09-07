<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

final class ThemeScopedReleaseBatchRollbackContractTest extends TestCase
{
    public function testRollbackCreatesANewAtomicBatchWithoutMutatingHistoricalReleases(): void
    {
        $root = \dirname(__DIR__, 4);
        $interface = (string)\file_get_contents($root . '/Api/Scoped/ThemeScopedWorkspaceInterface.php');
        $workspace = (string)\file_get_contents($root . '/Service/Scoped/ThemeScopedWorkspace.php');

        self::assertStringContainsString('public function rollbackReleaseBatch(', $interface);
        self::assertStringContainsString('public function rollbackReleaseBatch(', $workspace);
        self::assertStringContainsString('prepareReleaseBatchRollbackItem', $workspace);
        self::assertStringContainsString('applyPreparedReleaseBatchRollbackItem', $workspace);
        self::assertStringContainsString('schema_fields_SOURCE_BATCH_ID', $workspace);
        self::assertStringContainsString("'source_batch_id'", $workspace);
        self::assertStringContainsString("'status' => 'rolled_back'", $workspace);
        self::assertStringNotContainsString('->delete()', $this->rollbackMethod($workspace));
    }

    private function rollbackMethod(string $workspace): string
    {
        $start = \strpos($workspace, 'public function rollbackReleaseBatch(');
        $end = \strpos($workspace, 'public function updateReleaseBatchCacheState(', (int)$start);
        if ($start === false || $end === false) {
            return '';
        }
        return \substr($workspace, $start, $end - $start);
    }
}
