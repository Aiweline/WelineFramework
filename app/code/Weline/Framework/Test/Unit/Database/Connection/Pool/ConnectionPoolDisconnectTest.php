<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Pool;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;

final class ConnectionPoolDisconnectFakePdo extends PDO
{
    public function __construct(
        private readonly bool $throwOnInTransaction = false,
        private readonly bool $inTransaction = false
    ) {
    }

    public function inTransaction(): bool
    {
        if ($this->throwOnInTransaction) {
            throw new \PDOException('SQLSTATE[HY000]: General error: 7 no connection to the server');
        }

        return $this->inTransaction;
    }

    public function rollBack(): bool
    {
        throw new \PDOException('SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly');
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return false;
    }
}

final class ConnectionPoolDisconnectTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionPool::closePool();
    }

    public function testMarkedUnhealthyConnectionIsDiscardedOnRelease(): void
    {
        $config = $this->config();
        $connection = new ConnectionPoolDisconnectFakePdo();

        $pooled = ConnectionPool::getConnection($config, static fn(): PDO => $connection);
        self::assertSame($connection, $pooled);

        ConnectionPool::markConnectionUnhealthy($connection);
        ConnectionPool::releaseConnection($connection, $config);

        self::assertSame([
            'available' => 0,
            'in_use' => 0,
            'max_size' => 1,
            'current_size' => 0,
        ], ConnectionPool::getPoolStats($config));
    }

    public function testRequestEndCleanupDoesNotReturnBrokenConnectionToPool(): void
    {
        $config = $this->config();
        $connection = new ConnectionPoolDisconnectFakePdo(true);

        ConnectionPool::getConnection($config, static fn(): PDO => $connection);
        ConnectionPool::requestEndCleanup();

        self::assertSame([
            'available' => 0,
            'in_use' => 0,
            'max_size' => 1,
            'current_size' => 0,
        ], ConnectionPool::getPoolStats($config));
    }

    public function testDisconnectExceptionClassifierCoversPgsqlConnectionLossMessages(): void
    {
        self::assertTrue(ConnectionPool::isDisconnectException(
            new \PDOException('SQLSTATE[HY000]: General error: 7 no connection to the server')
        ));
        self::assertTrue(ConnectionPool::isDisconnectException(
            new \PDOException('SQLSTATE[08006] terminating connection due to administrator command')
        ));
        self::assertFalse(ConnectionPool::isDisconnectException(
            new \PDOException('SQLSTATE[23505]: duplicate key value violates unique constraint')
        ));
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
