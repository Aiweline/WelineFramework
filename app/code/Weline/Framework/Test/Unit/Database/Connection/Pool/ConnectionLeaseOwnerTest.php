<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Pool;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionLease;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;

/**
 * 租约所有权：主进程上下文只有在持有租约的 Fiber 已终止时才可接管。
 * WLS 下请求 Fiber 会在让出点挂起，此时它仍活着并持有连接。
 */
final class ConnectionLeaseOwnerFakePdo extends PDO
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

final class ConnectionLeaseOwnerTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionPool::closePool();
    }

    public function testSuspendedLiveFiberLeaseCannotBeTakenOverByProcessContext(): void
    {
        $config = $this->config();
        $lease = null;

        $fiber = new \Fiber(function () use ($config, &$lease): void {
            $lease = ConnectionPool::acquire($config, static fn(): PDO => new ConnectionLeaseOwnerFakePdo());
            \Fiber::suspend();
            $lease->release();
        });

        $fiber->start();
        self::assertInstanceOf(ConnectionLease::class, $lease);
        self::assertTrue($fiber->isSuspended(), '请求 Fiber 处于挂起状态（模拟 WLS 让出点）');
        self::assertFalse($lease->isOwnerFiberDead());
        self::assertFalse(
            $lease->isOwnedByCurrentOwner(),
            '挂起但存活的 Fiber 仍持有连接，主上下文不得接管'
        );

        $fiber->resume();
        self::assertSame(0, ConnectionPool::getPoolStats($config)['in_use']);
    }

    public function testTerminatedFiberLeaseCanBeTakenOver(): void
    {
        $config = $this->config();
        $lease = null;

        $fiber = new \Fiber(function () use ($config, &$lease): void {
            $lease = ConnectionPool::acquire($config, static fn(): PDO => new ConnectionLeaseOwnerFakePdo());
        });
        $fiber->start();
        self::assertTrue($fiber->isTerminated());
        self::assertInstanceOf(ConnectionLease::class, $lease);

        self::assertTrue($lease->isOwnerFiberDead());
        self::assertTrue(
            $lease->isOwnedByCurrentOwner(),
            'Fiber 已终止时允许主上下文接管，避免连接永久滞留'
        );

        $lease->release();
        self::assertSame(0, ConnectionPool::getPoolStats($config)['in_use'], '接管后必须能把连接结算回池');
    }

    private function config(): ConfigProviderInterface
    {
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('pgsql');
        $config->method('getHostName')->willReturn('127.0.0.1');
        $config->method('getHostPort')->willReturn(15432);
        $config->method('getDatabase')->willReturn('weline_unit_lease');
        $config->method('getUsername')->willReturn('unit');
        $config->method('getPoolSize')->willReturn(2);

        return $config;
    }
}
