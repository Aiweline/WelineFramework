<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionServer;

final class SessionServerMemoryListBudgetTest extends TestCase
{
    public function testMemoryListReturnsBoundedMetadataInsteadOfNamespacePayload(): void
    {
        $server = new SessionServer([
            'role' => 'memory_server',
            'auth_enabled' => false,
            'persist_enabled' => false,
            'memory_high_watermark_bytes' => PHP_INT_MAX,
            'memory_low_watermark_bytes' => PHP_INT_MAX - 1,
        ]);
        $payload = [];
        for ($index = 0; $index < 32; $index++) {
            $payload['key-' . $index] = \str_repeat('x', 4096);
        }
        $server->getStore()->setAll('__kv__:large-namespace', $payload, 300);

        $method = new \ReflectionMethod($server, 'listSessions');
        $rows = $method->invoke($server, [], 50);

        self::assertCount(1, $rows);
        self::assertSame('__kv__:large-namespace', $rows[0]['session_id'] ?? null);
        self::assertSame(32, $rows[0]['data_count'] ?? null);
        self::assertCount(8, $rows[0]['keys'] ?? []);
        self::assertArrayNotHasKey('data', $rows[0]);
    }
}
