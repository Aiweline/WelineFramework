<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Pool;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProvider;

/**
 * 池身份必须包含会话初始化配置（options / pre_sql / charset）。
 * 否则模块分库用「同实例同库 + 不同 search_path/编码」时，
 * 两套配置会共用同一条物理连接并互相污染会话状态。
 */
final class ConnectionPoolIdentityFakePdo extends PDO
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

final class ConnectionPoolIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionPool::closePool();
    }

    public function testPreSqlProducesDistinctPoolKeyAndConnection(): void
    {
        $base = $this->config('public');
        $module = $this->config('module_b');

        self::assertNotSame($this->poolKey($base), $this->poolKey($module), 'pre_sql 不同必须视为不同池');

        $created = 0;
        $factory = static function () use (&$created): PDO {
            $created++;

            return new ConnectionPoolIdentityFakePdo();
        };

        ConnectionPool::getConnection($base, $factory);
        ConnectionPool::getConnection($module, $factory, 1.0);

        self::assertSame(2, $created, '两套会话配置必须各建一条物理连接');
        $stats = ConnectionPool::getPoolStats();
        self::assertArrayHasKey($this->poolKey($base), $stats, '基础配置必须有独立池');
        self::assertArrayHasKey($this->poolKey($module), $stats, '模块配置必须有独立池');
    }

    public function testCharsetProducesDistinctPoolKey(): void
    {
        $utf8 = $this->config('', 'utf8');
        $ascii = $this->config('', 'sql_ascii');

        self::assertNotSame($this->poolKey($utf8), $this->poolKey($ascii));
    }

    public function testPdoOptionsProduceDistinctPoolKey(): void
    {
        $plain = $this->config('');
        $caseLower = $this->config('', 'utf8', [PDO::ATTR_CASE => PDO::CASE_LOWER]);

        self::assertNotSame($this->poolKey($plain), $this->poolKey($caseLower));
    }

    public function testIdenticalSessionConfigSharesSinglePool(): void
    {
        $first = $this->config('public');
        $second = $this->config('public');

        self::assertSame($this->poolKey($first), $this->poolKey($second));

        $created = 0;
        $factory = static function () use (&$created): PDO {
            $created++;

            return new ConnectionPoolIdentityFakePdo();
        };

        ConnectionPool::getConnection($first, $factory);
        ConnectionPool::requestEndCleanup();
        ConnectionPool::getConnection($second, $factory, 1.0);

        self::assertSame(1, $created, '完全相同的配置必须复用同一个池');
        self::assertCount(1, ConnectionPool::getPoolStats());
    }

    /** @param array<int, mixed> $options */
    private function config(string $preSql, string $charset = 'utf8', array $options = []): ConfigProvider
    {
        $config = new ConfigProvider([
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'weline_unit_identity',
            'username' => 'unit',
            'password' => 'unit',
            'prefix' => 'w_',
            'charset' => $charset,
            'pool_size' => 2,
            'path' => 'probe/identity',
        ]);

        if ($preSql !== '') {
            $config->setPreSql($preSql);
        }
        if ($options !== []) {
            $config->setOptions($options);
        }

        return $config;
    }

    private function poolKey(ConfigProvider $config): string
    {
        $method = new \ReflectionMethod(ConnectionPool::class, 'getPoolKey');
        $method->setAccessible(true);

        return $method->invoke(null, $config);
    }
}
