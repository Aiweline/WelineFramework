<?php

declare(strict_types=1);

namespace Weline\Database\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * P3: a queued create job is applied outside setup, the physical index is valid,
 * and job/state reach done/applied.
 */
final class SchemaIndexApplyIntegrationTest extends TestCase
{
    public function testQueuedCreateJobIsAppliedConcurrently(): void
    {
        $fixture = dirname(__DIR__, 1) . '/fixtures/schema-index-apply-probe.php';
        self::assertFileExists($fixture);

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>&1', $output, $status);
        $raw = implode("\n", $output);
        $result = json_decode($raw, true);
        self::assertIsArray($result, $raw);

        if (!empty($result['skipped'])) {
            self::markTestSkipped((string)($result['reason'] ?? 'pgsql fixture skipped'));
        }

        self::assertSame(0, $status, $raw);
        self::assertTrue($result['ok'] ?? false, $raw);
        self::assertTrue($result['apply']['success'] ?? false, $raw);
        self::assertSame('done', $result['job_status'] ?? null);
        self::assertSame('applied', $result['state_status'] ?? null);
        self::assertTrue($result['index_valid'] ?? false);
    }
}
