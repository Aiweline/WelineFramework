<?php

declare(strict_types=1);

namespace Weline\Database\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * P5: database_index enqueue moves a pending job to queued, and both the
 * progress service and BinQuery progress operation return a usable snapshot.
 */
final class SchemaIndexSseIntegrationTest extends TestCase
{
    public function testProgressSnapshotAndImmediateEnqueue(): void
    {
        $fixture = dirname(__DIR__, 1) . '/fixtures/schema-index-sse-probe.php';
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
        self::assertSame('pending', $result['before']['status'] ?? null);
        self::assertSame(0, $result['before']['percent'] ?? null);
        self::assertTrue($result['enqueue']['success'] ?? false, $raw);
        self::assertSame('queued', $result['after']['status'] ?? null);
        self::assertTrue($result['progress_api']['success'] ?? false, $raw);
    }
}
