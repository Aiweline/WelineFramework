<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Pool;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Exception\ConnectionPoolExhaustedException;

/**
 * 池指标必须能直接回答「有没有每个请求都新建连接」：
 * acquire_total 与 created_total 的差值就是复用次数。
 */
final class ConnectionPoolMetricsFakePdo extends PDO
{
    public function __construct()
    {
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function exec(string $statement): int|false
    {
        return 0;
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'pgsql' : null;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return false;
    }
}

final class ConnectionPoolMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        ConnectionPool::closePool();
        ConnectionPool::resetMetrics();
    }

    protected function tearDown(): void
    {
        ConnectionPool::closePool();
        ConnectionPool::resetMetrics();
    }

    public function testRepeatedAcquiresReuseSinglePhysicalConnection(): void
    {
        $config = $this->config();
        $created = 0;
        $factory = static function () use (&$created): PDO {
            $created++;

            return new ConnectionPoolMetricsFakePdo();
        };

        for ($i = 0; $i < 20; $i++) {
            $lease = ConnectionPool::acquire($config, $factory);
            $lease->release();
        }

        $metrics = ConnectionPool::getMetrics();
        self::assertSame(20, $metrics['acquire_total']);
        self::assertSame(1, $metrics['created_total'], '20 次取连接只允许建 1 条物理连接');
        self::assertSame(19, $metrics['reused_total']);
        self::assertSame(0, $metrics['exhausted_total']);
        self::assertSame(1, $created);
    }

    public function testExhaustionIsCounted(): void
    {
        $config = $this->config();
        $factory = static fn(): PDO => new ConnectionPoolMetricsFakePdo();

        ConnectionPool::getConnection($config, $factory);
        try {
            ConnectionPool::getConnection($config, $factory, 0.05);
            self::fail('池饱和时应抛出 ConnectionPoolExhaustedException');
        } catch (ConnectionPoolExhaustedException) {
            // 预期
        }

        self::assertSame(1, ConnectionPool::getMetrics()['exhausted_total']);
    }

    public function testResetMetricsClearsCounters(): void
    {
        $config = $this->config();
        $factory = static fn(): PDO => new ConnectionPoolMetricsFakePdo();
        $lease = ConnectionPool::acquire($config, $factory);
        $lease->release();

        ConnectionPool::resetMetrics();

        self::assertSame([
            'acquire_total' => 0,
            'created_total' => 0,
            'reused_total' => 0,
            'exhausted_total' => 0,
        ], ConnectionPool::getMetrics());
    }

    private function config(): ConfigProviderInterface
    {
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('pgsql');
        $config->method('getHostName')->willReturn('127.0.0.1');
        $config->method('getHostPort')->willReturn(15432);
        $config->method('getDatabase')->willReturn('weline_unit_metrics');
        $config->method('getUsername')->willReturn('unit');
        $config->method('getPoolSize')->willReturn(1);

        return $config;
    }
}
