<?php

declare(strict_types=1);

namespace Weline\Database\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * P7: same-name rebuild swaps columns (a -> b) and DROP removes the index.
 */
final class SchemaIndexDropRebuildIntegrationTest extends TestCase
{
    public function testRebuildAndDropOnRealPgsql(): void
    {
        $fixture = dirname(__DIR__, 1) . '/fixtures/schema-index-drop-rebuild-probe.php';
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
        self::assertSame('done', $result['rebuild_result']['status'] ?? null);
        self::assertStringContainsString('(b)', (string)($result['new_definition'] ?? ''));
        self::assertSame('done', $result['drop_result']['status'] ?? null);
        self::assertSame('removed', $result['drop_state'] ?? null);
    }
}
