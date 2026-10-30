<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Pool;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProvider;

/**
 * Master 主循环任务模式：每个调度是一条新 Fiber（owner=fiber:pid:seq），
 * 任务结束必须 requestEndCleanup()，否则该连接永久占住池位。
 * 这里锁住「Fiber 内取连接 → 任务末尾结算 → 连接回到空闲队列」这条行为。
 */
final class ConnectionPoolFiberTaskCleanupFakePdo extends PDO
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

final class ConnectionPoolFiberTaskCleanupTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionPool::closePool();
    }

    public function testFiberTaskCleanupReturnsConnectionToIdleQueue(): void
    {
        $config = $this->config();
        $factory = static fn(): PDO => new ConnectionPoolFiberTaskCleanupFakePdo();

        $lease = null;
        $fiber = new \Fiber(function () use ($config, $factory, &$lease): void {
            // 必须持有租约引用：ConnectionLease 是 RAII，引用一消失就会自动归还。
            $lease = ConnectionPool::acquire($config, $factory);
            self::assertSame(1, ConnectionPool::getPoolStats($config)['in_use']);
            // 等价于 ServiceOrchestrator 任务 Fiber 的 finally：结算本 Fiber 的 owner
            ConnectionPool::requestEndCleanup();
        });
        $fiber->start();
        self::assertTrue($fiber->isTerminated());

        $stats = ConnectionPool::getPoolStats($config);
        self::assertSame(0, $stats['in_use'], '任务 Fiber 结束后不得残留 in_use');
        self::assertSame(1, $stats['available'], '连接必须回到空闲队列供其它 owner 复用');
    }

    /**
     * 反证（不在此处锁定）：不结算的 Fiber owner 会一直占住池位，
     * 周期回收逻辑由 ConnectionLeaseOwnerTest / ConnectionPoolLifecycleTest 覆盖；
     * 这里不写依赖 GC 时序的断言，避免伪红。
     */

    private function config(): ConfigProvider
    {
        $config = new ConfigProvider([
            'type' => 'pgsql',
            'hostname' => '127.0.0.1',
            'hostport' => '5432',
            'database' => 'weline_unit_fiber_task',
            'username' => 'unit',
            'password' => 'unit',
            'charset' => 'utf8',
            'pool_size' => 2,
            'path' => 'probe/fiber-task',
            // 固定 persistent：池键包含 options 摘要，而 options 里的持久连接位随运行时模式变化，
            // 固定它才能保证同一个 ConfigProvider 在测试进程内始终映射到同一个池。
            'persistent' => false,
        ]);

        return $config;
    }
}
