<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Pool;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;

/**
 * 连接生命周期：空闲 TTL、最大存活时间、低频巡检。
 * 没有这些淘汰策略，池会按历史峰值永久占住 PG backend。
 */
final class ConnectionPoolLifecycleFakePdo extends PDO
{
    public function __construct(public readonly string $name = 'conn')
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

final class ConnectionPoolLifecycleTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $originalEnvConfig = null;

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(Env::class, 'config');
        $property->setAccessible(true);
        $this->originalEnvConfig = $property->getValue(Env::getInstance());
    }

    protected function tearDown(): void
    {
        if ($this->originalEnvConfig !== null) {
            $property = new \ReflectionProperty(Env::class, 'config');
            $property->setAccessible(true);
            $property->setValue(Env::getInstance(), $this->originalEnvConfig);
        }
        ConnectionPool::closePool();
    }

    public function testIdleExpiredConnectionIsDroppedAndRecreated(): void
    {
        $this->overridePoolEnv('idle_ttl_seconds', 1);
        $this->overridePoolEnv('max_lifetime_seconds', 0);
        $config = $this->config();
        $created = 0;
        $factory = static function () use (&$created): PDO {
            $created++;

            return new ConnectionPoolLifecycleFakePdo('c' . $created);
        };

        ConnectionPool::getConnection($config, $factory);
        ConnectionPool::requestEndCleanup();
        $this->ageIdleConnection($config, 5.0);

        $replacement = ConnectionPool::getConnection($config, $factory, 1.0);

        self::assertSame('c2', $replacement->name, '空闲超 TTL 的连接必须淘汰后重建');
        self::assertSame(2, $created);
        self::assertSame(1, ConnectionPool::getPoolStats($config)['current_size']);
    }

    public function testSweepReclaimsExpiredIdleConnectionsWithoutTraffic(): void
    {
        $this->overridePoolEnv('idle_ttl_seconds', 1);
        $this->overridePoolEnv('max_lifetime_seconds', 0);
        $config = $this->config();
        $factory = static fn(): PDO => new ConnectionPoolLifecycleFakePdo();

        ConnectionPool::getConnection($config, $factory);
        ConnectionPool::requestEndCleanup();
        $this->ageIdleConnection($config, 10.0);

        $swept = ConnectionPool::sweepExpiredIdleConnections();

        self::assertSame(1, $swept, '巡检必须回收过期空闲连接');
        $stats = ConnectionPool::getPoolStats($config);
        self::assertSame(0, $stats['available']);
        self::assertSame(0, $stats['current_size']);
    }

    public function testMaxLifetimeDropsLongLivedConnection(): void
    {
        $this->overridePoolEnv('idle_ttl_seconds', 0);
        $this->overridePoolEnv('max_lifetime_seconds', 60);
        $config = $this->config();
        $created = 0;
        $factory = static function () use (&$created): PDO {
            $created++;

            return new ConnectionPoolLifecycleFakePdo('c' . $created);
        };

        $first = ConnectionPool::getConnection($config, $factory);
        ConnectionPool::requestEndCleanup();
        $this->backdateCreatedAt($first, 120.0);

        $replacement = ConnectionPool::getConnection($config, $factory, 1.0);

        self::assertSame('c2', $replacement->name, '超过最大存活时间的连接必须淘汰');
        self::assertSame(2, $created);
    }

    public function testDisabledTtlKeepsConnectionsReusable(): void
    {
        $this->overridePoolEnv('idle_ttl_seconds', 0);
        $this->overridePoolEnv('max_lifetime_seconds', 0);
        $config = $this->config();
        $factory = static fn(): PDO => new ConnectionPoolLifecycleFakePdo('keep');

        ConnectionPool::getConnection($config, $factory);
        ConnectionPool::requestEndCleanup();
        $this->ageIdleConnection($config, 86400.0);

        self::assertSame('keep', ConnectionPool::getConnection($config, $factory, 1.0)->name);
    }

    private function ageIdleConnection(ConfigProviderInterface $config, float $seconds): void
    {
        $keyMethod = new \ReflectionMethod(ConnectionPool::class, 'getPoolKey');
        $keyMethod->setAccessible(true);
        $poolKey = $keyMethod->invoke(null, $config);

        $property = new \ReflectionProperty(ConnectionPool::class, 'pools');
        $property->setAccessible(true);
        $pools = $property->getValue();
        /** @var \SplQueue $queue */
        $queue = $pools[$poolKey]['pool'];
        $item = $queue->dequeue();
        $item['last_used'] = \microtime(true) - $seconds;
        $queue->enqueue($item);
    }

    private function backdateCreatedAt(PDO $connection, float $seconds): void
    {
        $property = new \ReflectionProperty(ConnectionPool::class, 'connectionCreatedAt');
        $property->setAccessible(true);
        $createdAt = $property->getValue();
        $createdAt[\spl_object_id($connection)] = \microtime(true) - $seconds;
        $property->setValue(null, $createdAt);
    }

    private function overridePoolEnv(string $key, mixed $value): void
    {
        $property = new \ReflectionProperty(Env::class, 'config');
        $property->setAccessible(true);
        $config = $property->getValue(Env::getInstance());
        $config['db']['pool'][$key] = $value;
        $property->setValue(Env::getInstance(), $config);
    }

    private function config(): ConfigProviderInterface
    {
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('pgsql');
        $config->method('getHostName')->willReturn('127.0.0.1');
        $config->method('getHostPort')->willReturn(15432);
        $config->method('getDatabase')->willReturn('weline_unit_lifecycle');
        $config->method('getUsername')->willReturn('unit');
        $config->method('getPoolSize')->willReturn(2);

        return $config;
    }
}
