<?php

declare(strict_types=1);

namespace Weline\Database\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * P2 regression: a drop+add of the same index is recorded as one rebuild job,
 * repeat diffs do not duplicate it, and UNIQUE indexes remain synchronous.
 */
final class SchemaIndexSinkIntegrationTest extends TestCase
{
    public function testRebuildCoalesceDedupeAndUniqueSync(): void
    {
        $fixture = dirname(__DIR__, 1) . '/fixtures/schema-index-sink-probe.php';
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
        self::assertSame(1, $result['first_job_count'] ?? null, 'drop+add must coalesce into one job');
        self::assertSame('rebuild', $result['first_job_action'] ?? null);
        self::assertSame(1, $result['second_job_count'] ?? null, 'repeat diff must dedupe');
        self::assertTrue($result['unique_index_present'] ?? false, 'UNIQUE index must stay synchronous');
        self::assertSame(1, $result['jobs_after_unique'] ?? null, 'UNIQUE must not create a job');
    }
}
