<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared\Connection;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionStore;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Shared\Connection\ConnectionPoolManager;
use Weline\Server\Shared\Connection\PooledConnection;

final class SharedSessionTransportMonotonicTimingTest extends TestCase
{
    public function testTransportDeadlinesCooldownsAndMetricsUseMonotonicTime(): void
    {
        foreach ([
            PooledConnection::class,
            ConnectionPoolManager::class,
            SharedStateClient::class,
            SessionStore::class,
        ] as $class) {
            $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

            self::assertStringNotContainsString('microtime(true)', $source, $class);
            self::assertStringContainsString('hrtime(true)', $source, $class);
        }
    }
}
