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

    /**
     * 默认获取预算必须有界：30 秒级的同步等待会把局部过载放大成整站不可用。
     */
    public function testDefaultAcquireBudgetIsBounded(): void
    {
        $reflection = new \ReflectionClass(ConnectionPool::class);
        $budget = (float)$reflection->getConstant('DEFAULT_ACQUIRE_TIMEOUT_SECONDS');

        self::assertGreaterThan(0.0, $budget, '默认预算必须大于 0，否则完全放弃背压');
        self::assertLessThanOrEqual(3.0, $budget, '默认预算必须有界，不得再出现 30 秒级等待');
    }

    /**
     * 行为验证：池饱和且调用方未显式传超时时，必须走默认预算并在有界时间内失败，
     * 而不是把请求 Fiber 阻塞数十秒。
     */
    public function testSaturatedPoolFailsFastWithinBoundedBudget(): void
    {
        $config = $this->config();
        $factory = static fn(): PDO => new ConnectionPoolExhaustionFakePdo();

        // 占满唯一连接且不归还
        ConnectionPool::getConnection($config, $factory);

        $startedAt = \microtime(true);
        try {
            ConnectionPool::getConnection($config, $factory);
            self::fail('Expected the saturated pool to reject the overflow acquire.');
        } catch (ConnectionPoolExhaustedException $exception) {
            $elapsed = \microtime(true) - $startedAt;
            self::assertLessThan(
                10.0,
                $elapsed,
                '默认获取预算必须有界；实测耗时 ' . \round($elapsed, 2) . 's'
            );
            self::assertStringContainsString('max_size=1', $exception->getMessage());
        }
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
