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
 * 取连接时验活：空闲连接在服务端已终止时，必须在交付前被发现并重建，
 * 而不是把死连接交给调用方（写语句不重试 → 直接 5xx）。
 */
final class ConnectionPoolValidateFakePdo extends PDO
{
    public function __construct(public bool $dead = false)
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->dead && \stripos($query, 'SELECT 1') !== false) {
            throw new \PDOException('SQLSTATE[08006] server closed the connection unexpectedly');
        }

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

    public function inTransaction(): bool
    {
        return false;
    }
}

final class ConnectionPoolValidateOnAcquireTest extends TestCase
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

    public function testDeadIdleConnectionIsReplacedBeforeDelivery(): void
    {
        $config = $this->config();
        $dead = new ConnectionPoolValidateFakePdo();
        $fresh = new ConnectionPoolValidateFakePdo();
        $created = 0;
        $factory = static function () use (&$created, $dead, $fresh): PDO {
            $created++;

            return $created === 1 ? $dead : $fresh;
        };

        ConnectionPool::getConnection($config, $factory);
        ConnectionPool::requestEndCleanup();
        $this->ageIdleConnection($config, 5.0);

        $dead->dead = true;
        $connection = ConnectionPool::getConnection($config, $factory, 0.5);

        self::assertSame($fresh, $connection, '死连接不得交付，必须重建');
        self::assertSame(2, $created);
        self::assertSame(1, ConnectionPool::getPoolStats($config)['current_size']);
    }

    public function testFreshConnectionIsValidatedByDefault(): void
    {
        $config = $this->config();
        $dead = new ConnectionPoolValidateFakePdo();
        $fresh = new ConnectionPoolValidateFakePdo();
        $created = 0;
        $factory = static function () use (&$created, $dead, $fresh): PDO {
            $created++;

            return $created === 1 ? $dead : $fresh;
        };

        ConnectionPool::getConnection($config, $factory);
        ConnectionPool::requestEndCleanup();

        $dead->dead = true;
        $connection = ConnectionPool::getConnection($config, $factory, 0.5);

        self::assertSame($fresh, $connection, '默认每次取用都验活，刚归还的死连接也必须被替换');
        self::assertSame(2, $created);
    }

    public function testConfiguredIdleWindowSkipsValidationForFreshConnections(): void
    {
        $this->overridePoolEnv('idle_validate_seconds', 1.0);
        $config = $this->config();
        $dead = new ConnectionPoolValidateFakePdo();
        $created = 0;
        $factory = static function () use (&$created, $dead): PDO {
            $created++;

            return $dead;
        };

        ConnectionPool::getConnection($config, $factory);
        ConnectionPool::requestEndCleanup();

        $dead->dead = true;
        self::assertSame($dead, ConnectionPool::getConnection($config, $factory, 0.5));
        self::assertSame(1, $created);
    }

    public function testValidationCanBeDisabled(): void
    {
        $this->overridePoolEnv('validate_on_acquire', false);
        $config = $this->config();
        $dead = new ConnectionPoolValidateFakePdo();
        $factory = static fn(): PDO => $dead;

        ConnectionPool::getConnection($config, $factory);
        ConnectionPool::requestEndCleanup();
        $this->ageIdleConnection($config, 5.0);

        $dead->dead = true;
        self::assertSame($dead, ConnectionPool::getConnection($config, $factory, 0.5));
    }

    public function testIdleValidateSecondsConfigIsHonoured(): void
    {
        $this->overridePoolEnv('idle_validate_seconds', 0);
        $config = $this->config();
        $dead = new ConnectionPoolValidateFakePdo();
        $fresh = new ConnectionPoolValidateFakePdo();
        $created = 0;
        $factory = static function () use (&$created, $dead, $fresh): PDO {
            $created++;

            return $created === 1 ? $dead : $fresh;
        };

        ConnectionPool::getConnection($config, $factory);
        ConnectionPool::requestEndCleanup();

        $dead->dead = true;
        self::assertSame(
            $fresh,
            ConnectionPool::getConnection($config, $factory, 0.5),
            'idle_validate_seconds=0 时刚归还的连接也必须验活'
        );
    }

    public function testDisconnectEpochForcesValidationOfFreshConnections(): void
    {
        $config = $this->config();
        $stale = new ConnectionPoolValidateFakePdo();
        $fresh = new ConnectionPoolValidateFakePdo();
        $created = 0;
        $factory = static function () use (&$created, $stale, $fresh): PDO {
            $created++;

            return $created === 1 ? $stale : $fresh;
        };

        ConnectionPool::getConnection($config, $factory);
        ConnectionPool::requestEndCleanup();

        // 模拟别处检测到断链：纪元自增，早于该纪元归还的连接必须重新验活
        ConnectionPool::markConnectionUnhealthy(new ConnectionPoolValidateFakePdo());
        $stale->dead = true;

        self::assertSame(
            $fresh,
            ConnectionPool::getConnection($config, $factory, 0.5),
            '纪元变化后，刚归还的连接也必须验活，不能直接交付'
        );
        self::assertSame(2, $created);
    }

    private function overridePoolEnv(string $key, mixed $value): void
    {
        $property = new \ReflectionProperty(Env::class, 'config');
        $property->setAccessible(true);
        $config = $property->getValue(Env::getInstance());
        $config['db']['pool'][$key] = $value;
        $property->setValue(Env::getInstance(), $config);
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
