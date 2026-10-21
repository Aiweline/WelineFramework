<?php

declare(strict_types=1);

namespace Weline\Database\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * P6: per-table cooldown and per-table window budget gate the next pending job.
 */
final class SchemaIndexGuardIntegrationTest extends TestCase
{
    public function testCooldownAndTableBudgetBlockNextPendingJob(): void
    {
        $fixture = dirname(__DIR__, 1) . '/fixtures/schema-index-guard-probe.php';
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
        self::assertTrue($result['cooldown_blocked'] ?? false);
        self::assertTrue($result['table_budget_blocked'] ?? false);
        self::assertGreaterThan(0, $result['available_job_id'] ?? 0);
    }
}
