<?php

declare(strict_types=1);

namespace Weline\Database\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * P7: drift verify detects missing applied index, skips pending state, and --fix enqueues repair.
 */
final class SchemaIndexDriftIntegrationTest extends TestCase
{
    public function testDriftDetectionAndRepairOnRealPgsql(): void
    {
        $fixture = dirname(__DIR__, 1) . '/fixtures/schema-index-drift-probe.php';
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
        self::assertSame(1, $result['detect']['drifted'] ?? null);
        self::assertSame(1, $result['repair']['repaired'] ?? null);
        self::assertSame('drifted', $result['applied_state'] ?? null);
        self::assertSame('pending', $result['pending_state'] ?? null);
        self::assertSame('create', $result['repair_job']['action'] ?? null);
    }
}
