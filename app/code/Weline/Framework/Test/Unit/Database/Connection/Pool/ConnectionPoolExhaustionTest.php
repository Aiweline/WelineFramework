<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Pool;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Exception\ConnectionPoolExhaustedException;

final class ConnectionPoolExhaustionFakePdo extends PDO
{
    public function __construct()
    {
    }
}

final class ConnectionPoolExhaustionTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionPool::closePool();
    }

    public function testPoolNeverCreatesConnectionBeyondConfiguredMaximum(): void
    {
        $config = $this->config();
        $created = 0;
        $factory = static function () use (&$created): PDO {
            $created++;
            return new ConnectionPoolExhaustionFakePdo();
        };

        ConnectionPool::getConnection($config, $factory);

        try {
            ConnectionPool::getConnection($config, $factory, 0.001);
            self::fail('Expected the saturated pool to reject an unmanaged overflow connection.');
        } catch (ConnectionPoolExhaustedException $exception) {
            self::assertStringContainsString('max_size=1', $exception->getMessage());
        }

        self::assertSame(1, $created);
        self::assertSame(1, ConnectionPool::getPoolStats($config)['current_size']);
    }

    public function testReleasedConnectionCanBeReacquiredWithoutCreatingAnotherConnection(): void
    {
        $config = $this->config();
        $created = 0;
        $factory = static function () use (&$created): PDO {
            $created++;
            return new ConnectionPoolExhaustionFakePdo();
        };

        $first = ConnectionPool::getConnection($config, $factory);
        ConnectionPool::releaseConnection($first, $config);
        $second = ConnectionPool::getConnection($config, $factory, 0.001);

        self::assertSame($first, $second);
        self::assertSame(1, $created);
    }

    private function config(): ConfigProviderInterface
    {
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('pgsql');
        $config->method('getHostName')->willReturn('127.0.0.1');
        $config->method('getHostPort')->willReturn(15432);
        $config->method('getDatabase')->willReturn('weline_pool_exhaustion');
        $config->method('getUsername')->willReturn('unit');
        $config->method('getPoolSize')->willReturn(1);

        return $config;
    }
}
