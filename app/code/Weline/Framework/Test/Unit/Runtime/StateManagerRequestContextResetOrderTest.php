<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Pool\ConnectionLease;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Runtime\WlsConcurrency;

final class StateManagerResetOrderFakePdo extends PDO
{
    public function __construct()
    {
    }

    public function inTransaction(): bool
    {
        return false;
    }
}

final class StateManagerRequestContextResetOrderTest extends TestCase
{
    private const EARLY_DATABASE_CALLBACK = '__early_database_cleanup_probe__';
    private const LATE_DATABASE_CALLBACK = '__late_database_checkout_probe__';

    protected function tearDown(): void
    {
        StateManager::unregisterResetCallback(self::EARLY_DATABASE_CALLBACK);
        StateManager::unregisterResetCallback(self::LATE_DATABASE_CALLBACK);
        ConnectionPool::closePool();
        parent::tearDown();
    }

    public function testRequestContextCleanupRunsAfterScopeBoundResetters(): void
    {
        StateManager::registerFrameworkResets();

        $property = new \ReflectionProperty(StateManager::class, 'resetCallbacks');
        $callbacks = $property->getValue();
        self::assertIsArray($callbacks);

        $order = \array_flip(\array_keys($callbacks));
        self::assertArrayHasKey('request_context', $order);
        self::assertArrayHasKey('template_instance', $order);
        self::assertArrayHasKey('module_request_resetters', $order);
        self::assertArrayHasKey('session_shutdown_queue', $order);
        self::assertArrayHasKey('db_connection_cleanup', $order);

        self::assertGreaterThan($order['template_instance'], $order['request_context']);
        self::assertGreaterThan($order['module_request_resetters'], $order['request_context']);
        self::assertGreaterThan($order['session_shutdown_queue'], $order['request_context']);
        self::assertGreaterThan($order['db_connection_cleanup'], $order['request_context']);
    }

    public function testConcurrentFiberCleanupDoesNotOmitRequestScopedTemplate(): void
    {
        self::assertNotContains(
            'template_instance',
            WlsConcurrency::callbackNamesOmittableWithPeerFibers()
        );
    }

    public function testRequestBoundaryReturnsDatabaseLeaseAcquiredByALateResetCallback(): void
    {
        $callbacksProperty = new \ReflectionProperty(StateManager::class, 'resetCallbacks');
        $originalCallbacks = $callbacksProperty->getValue();
        $callbacksProperty->setValue(null, []);

        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('pgsql');
        $config->method('getHostName')->willReturn('127.0.0.1');
        $config->method('getHostPort')->willReturn(15432);
        $config->method('getDatabase')->willReturn('weline_state_reset_order');
        $config->method('getUsername')->willReturn('unit');
        $config->method('getPoolSize')->willReturn(1);

        $retainedLease = null;
        $callbackFailure = null;
        try {
            StateManager::registerResetCallback(
                self::EARLY_DATABASE_CALLBACK,
                static fn () => ConnectionPool::requestEndCleanup(),
            );
            StateManager::registerResetCallback(
                self::LATE_DATABASE_CALLBACK,
                static function () use ($config, &$retainedLease, &$callbackFailure): void {
                    try {
                        $retainedLease = ConnectionPool::acquire(
                            $config,
                            static fn (): PDO => new StateManagerResetOrderFakePdo(),
                        );
                    } catch (\Throwable $throwable) {
                        $callbackFailure = $throwable;
                    }
                },
            );

            StateManager::reset();

            self::assertNull($callbackFailure, $callbackFailure?->getMessage() ?? '');
            self::assertInstanceOf(ConnectionLease::class, $retainedLease);
            self::assertFalse($retainedLease->isActive());
            self::assertSame(
                ['available' => 1, 'in_use' => 0, 'max_size' => 1, 'current_size' => 1],
                ConnectionPool::getPoolStats($config),
            );
        } finally {
            $callbacksProperty->setValue(null, $originalCallbacks);
        }
    }

    public function testFiberBoundaryReturnsOnlyTheCurrentOwnerAfterLateCheckout(): void
    {
        $callbacksProperty = new \ReflectionProperty(StateManager::class, 'resetCallbacks');
        $originalCallbacks = $callbacksProperty->getValue();
        $callbacksProperty->setValue(null, []);

        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn('pgsql');
        $config->method('getHostName')->willReturn('127.0.0.1');
        $config->method('getHostPort')->willReturn(15433);
        $config->method('getDatabase')->willReturn('weline_state_reset_fiber_order');
        $config->method('getUsername')->willReturn('unit');
        $config->method('getPoolSize')->willReturn(2);

        $fiberOneLease = null;
        $fiberTwoLease = null;
        try {
            StateManager::registerResetCallback(
                self::EARLY_DATABASE_CALLBACK,
                static fn () => ConnectionPool::requestEndCleanup(),
            );
            StateManager::registerResetCallback(
                self::LATE_DATABASE_CALLBACK,
                static function () use ($config, &$fiberOneLease): void {
                    $fiberOneLease = ConnectionPool::acquire(
                        $config,
                        static fn (): PDO => new StateManagerResetOrderFakePdo(),
                    );
                },
            );

            $fiberOne = new \Fiber(static function (): void {
                StateManager::reset();
            });
            $fiberTwo = new \Fiber(static function () use ($config, &$fiberTwoLease): void {
                $fiberTwoLease = ConnectionPool::acquire(
                    $config,
                    static fn (): PDO => new StateManagerResetOrderFakePdo(),
                );
                \Fiber::suspend();
            });

            $fiberTwo->start();
            $fiberOne->start();

            self::assertTrue($fiberOne->isTerminated());
            self::assertInstanceOf(ConnectionLease::class, $fiberOneLease);
            self::assertFalse($fiberOneLease->isActive());
            self::assertInstanceOf(ConnectionLease::class, $fiberTwoLease);
            self::assertTrue($fiberTwoLease->isActive());
            self::assertSame(
                ['available' => 1, 'in_use' => 1, 'max_size' => 2, 'current_size' => 2],
                ConnectionPool::getPoolStats($config),
            );
        } finally {
            $callbacksProperty->setValue(null, $originalCallbacks);
        }
    }
}
