<?php

declare(strict_types=1);

namespace Weline\Database\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * P3: cron admits exactly one pending job to the queue and marks it queued;
 * it does not execute the DDL itself.
 */
final class SchemaIndexCronIntegrationTest extends TestCase
{
    public function testCronEnqueuesPendingJob(): void
    {
        $fixture = dirname(__DIR__, 1) . '/fixtures/schema-index-cron-probe.php';
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
        self::assertSame('queued', $result['job_status'] ?? null);
        self::assertSame(1, $result['queue_count'] ?? null);
        self::assertStringContainsString('已入队', (string)($result['message'] ?? ''));
    }
}
