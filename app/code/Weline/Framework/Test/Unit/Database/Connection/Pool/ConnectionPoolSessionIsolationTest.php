<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Pool;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;

/**
 * 归还路径的会话/事务隔离契约：
 * 1) 未提交事务必须先回滚；
 * 2) 会话级状态（GUC、advisory lock、临时表等）必须重置；
 * 3) 回滚或重置失败时必须丢弃物理连接，不得放回空闲队列。
 */
final class ConnectionPoolSessionIsolationFakePdo extends PDO
{
    /** @var list<string> */
    public array $executed = [];

    public bool $rolledBack = false;

    public function __construct(
        private readonly string $driver = 'pgsql',
        private bool $inTransaction = false,
        private readonly bool $rollBackFails = false,
        private readonly bool $execFails = false,
        private readonly bool $driverUnsupported = false,
    ) {
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($this->driverUnsupported) {
            throw new \PDOException('SQLSTATE[IM001]: driver does not support this function');
        }

        return $attribute === PDO::ATTR_DRIVER_NAME ? $this->driver : null;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function rollBack(): bool
    {
        if ($this->rollBackFails) {
            throw new \PDOException('SQLSTATE[08006] server closed the connection unexpectedly');
        }
        $this->rolledBack = true;
        $this->inTransaction = false;

        return true;
    }

    public function exec(string $statement): int|false
    {
        if ($this->execFails) {
            throw new \PDOException('SQLSTATE[08006] no connection to the server');
        }
        $this->executed[] = $statement;

        return 0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return false;
    }
}

final class ConnectionPoolSessionIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionPool::closePool();
    }

    public function testRequestEndRollsBackAndResetsPgsqlSession(): void
    {
        $config = $this->config();
        $connection = new ConnectionPoolSessionIsolationFakePdo('pgsql', true);

        ConnectionPool::getConnection($config, static fn(): PDO => $connection);
        ConnectionPool::requestEndCleanup();

        self::assertTrue($connection->rolledBack, '未提交事务必须被回滚');
        self::assertContains('DISCARD ALL', $connection->executed, 'pgsql 归还前必须 DISCARD ALL');
        self::assertSame([
            'available' => 1,
            'in_use' => 0,
            'max_size' => 1,
            'current_size' => 1,
        ], ConnectionPool::getPoolStats($config));
    }

    public function testReleasePathRollsBackAndResetsSession(): void
    {
        $config = $this->config();
        $connection = new ConnectionPoolSessionIsolationFakePdo('pgsql', true);

        $lease = ConnectionPool::acquire($config, static fn(): PDO => $connection);
        $lease->release();

        self::assertTrue($connection->rolledBack, 'lease->release() 也不得把未提交事务放回池');
        self::assertContains('DISCARD ALL', $connection->executed);
        self::assertSame(0, ConnectionPool::getPoolStats($config)['in_use']);

        $again = ConnectionPool::acquire($config, static fn(): PDO => new ConnectionPoolSessionIsolationFakePdo());
        self::assertSame($connection, $again->getConnection(), '净化后的连接应可复用');
        self::assertFalse($again->getConnection()->inTransaction());
    }

    public function testMysqlSessionIsResetWithResetConnection(): void
    {
        $config = $this->config();
        $connection = new ConnectionPoolSessionIsolationFakePdo('mysql', false);

        ConnectionPool::getConnection($config, static fn(): PDO => $connection);
        ConnectionPool::requestEndCleanup();

        self::assertContains('RESET CONNECTION', $connection->executed);
    }

    public function testRollbackFailureDiscardsConnection(): void
    {
        $config = $this->config();
        $connection = new ConnectionPoolSessionIsolationFakePdo('pgsql', true, true);

        ConnectionPool::getConnection($config, static fn(): PDO => $connection);
        ConnectionPool::requestEndCleanup();

        self::assertSame([
            'available' => 0,
            'in_use' => 0,
            'max_size' => 1,
            'current_size' => 0,
        ], ConnectionPool::getPoolStats($config));
    }

    public function testSessionResetFailureDiscardsConnection(): void
    {
        $config = $this->config();
        $connection = new ConnectionPoolSessionIsolationFakePdo('pgsql', false, false, true);

        ConnectionPool::getConnection($config, static fn(): PDO => $connection);
        ConnectionPool::requestEndCleanup();

        self::assertSame(0, ConnectionPool::getPoolStats($config)['current_size']);
        self::assertSame(0, ConnectionPool::getPoolStats($config)['available']);
    }

    public function testUnknownDriverKeepsLegacyReturnBehavior(): void
    {
        $config = $this->config();
        $connection = new ConnectionPoolSessionIsolationFakePdo('pgsql', false, false, false, true);

        ConnectionPool::getConnection($config, static fn(): PDO => $connection);
        ConnectionPool::requestEndCleanup();

        self::assertSame(1, ConnectionPool::getPoolStats($config)['available']);
    }

    public function testSingleReturnIssuesOnlyOneSessionReset(): void
    {
        $config = $this->config();
        $connection = new ConnectionPoolSessionIsolationFakePdo('pgsql', true);

        ConnectionPool::getConnection($config, static fn(): PDO => $connection);
        ConnectionPool::requestEndCleanup();

        self::assertCount(1, $connection->executed, '同一次归还只允许一次 DISCARD ALL（避免双倍往返）');
    }

    private function config(): ConfigProviderInterface
    {
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('pgsql');
        $config->method('getHostName')->willReturn('127.0.0.1');
        $config->method('getHostPort')->willReturn(15432);
        $config->method('getDatabase')->willReturn('weline_unit');
        $config->method('getUsername')->willReturn('unit');
        $config->method('getPoolSize')->willReturn(1);

        return $config;
    }
}
