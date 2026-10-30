<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Pool;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionLease;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Exception\ConnectionPoolExhaustedException;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * 后台任务（homepage keep-warm / deferred bootstrap / L1 hydrate）与前台请求共用
 * 同一个连接池。现场实测：max_size=24 的池被后台水合占满，前台请求 2000ms 后
 * ConnectionPoolExhaustedException，WLS 表现为「全部 Worker 不可用」。
 *
 * 这里验证：后台最多占用池的 75%，前台仍能用最后一条。
 */
final class ConnectionPoolAbandonedOwnerReapTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionPool::closePool();
    }

    public function testBackgroundFiberCannotConsumeForegroundReserve(): void
    {
        $config = $this->config(4);
        $factory = static fn(): PDO => new ConnectionPoolExhaustionFakePdo();

        $this->holdConnections($config, $factory, 3);

        $this->expectException(ConnectionPoolExhaustedException::class);

        $background = new \Fiber(static function () use ($config, $factory): void {
            SchedulerSystem::markCurrentFiberBackground();
            ConnectionPool::acquire($config, $factory, 0.2);
        });
        $background->start();
    }

    public function testForegroundFiberStillUsesTheLastFreeSlot(): void
    {
        $config = $this->config(4);
        $factory = static fn(): PDO => new ConnectionPoolExhaustionFakePdo();

        $this->holdConnections($config, $factory, 3);

        $lease = ConnectionPool::acquire($config, $factory, 0.2);
        self::assertInstanceOf(ConnectionLease::class, $lease);
        unset($lease);
    }

    public function testBackgroundFiberMayUseThePoolWhileItHasHeadroom(): void
    {
        $config = $this->config(4);
        $factory = static fn(): PDO => new ConnectionPoolExhaustionFakePdo();

        $this->holdConnections($config, $factory, 2);

        $leases = [];
        $background = new \Fiber(static function () use ($config, $factory, &$leases): void {
            SchedulerSystem::markCurrentFiberBackground();
            $leases[] = ConnectionPool::acquire($config, $factory, 0.2);
            \Fiber::suspend();
        });
        $background->start();

        self::assertCount(1, $leases, '后台 Fiber 在有余量时应当拿到连接');
        self::assertSame(3, ConnectionPool::getPoolStats($config)['in_use']);
    }

    /**
     * 每条连接由一个挂起的 Fiber 持有租约（租约对象必须存活，否则析构即归还）。
     *
     * @return list<\Fiber>
     */
    private function holdConnections(
        ConfigProviderInterface $config,
        callable $factory,
        int $count
    ): array {
        $leases = [];
        $held = [];
        for ($i = 0; $i < $count; ++$i) {
            $fiber = new \Fiber(static function () use ($config, $factory, &$leases): void {
                $leases[] = ConnectionPool::acquire($config, $factory);
                \Fiber::suspend();
            });
            $fiber->start();
            $held[] = $fiber;
        }

        self::assertCount(\count($held), $leases, '每个 holder Fiber 都必须持有自己的租约');

        return $held;
    }

    private function config(int $poolSize = 1): ConfigProviderInterface
    {
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('pgsql');
        $config->method('getHostName')->willReturn('127.0.0.1');
        $config->method('getHostPort')->willReturn(15432);
        $config->method('getDatabase')->willReturn('weline_pool_reap');
        $config->method('getUsername')->willReturn('unit');
        $config->method('getPoolSize')->willReturn($poolSize);

        return $config;
    }
}
