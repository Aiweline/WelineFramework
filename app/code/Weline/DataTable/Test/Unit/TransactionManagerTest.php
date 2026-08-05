<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use Fiber;
use PDO;
use PDOStatement;
use ReflectionClass;
use Weline\DataTable\Api\Runtime\RequestResetter;
use Weline\DataTable\Helper\TableContext;
use Weline\DataTable\Helper\TransactionManager;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\ConnectionInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetException;
use Weline\Framework\Test\TestCore;

class TransactionManagerTest extends TestCore
{
    protected function tearDown(): void
    {
        TransactionManager::cleanup();
        parent::tearDown();
    }

    public function testExecuteInTransactionCommitsRootTransaction(): void
    {
        $connection = new FakeConnection();
        $this->injectConnection($connection);

        $result = TransactionManager::executeInTransaction(
            static fn (): string => 'done',
            'datatable_demo_form'
        );

        $this->assertSame('done', $result);
        $this->assertSame(1, $connection->beginCount);
        $this->assertSame(1, $connection->commitCount);
        $this->assertSame(0, $connection->rollbackCount);
        $this->assertSame([], $connection->executedSql);
        $this->assertSame(0, TransactionManager::getTransactionLevel());
        $this->assertFalse(TransactionManager::inTransaction());
    }

    public function testNestedTransactionsUseSavepointsAndRollbackInnerScope(): void
    {
        $connection = new FakeConnection();
        $this->injectConnection($connection);

        $this->assertTrue(TransactionManager::beginTransaction('outer'));
        $this->assertTrue(TransactionManager::beginTransaction('child stage'));
        $this->assertTrue(TransactionManager::rollback('child stage'));
        $this->assertTrue(TransactionManager::commit('outer'));

        $this->assertSame(1, $connection->beginCount);
        $this->assertSame(1, $connection->commitCount);
        $this->assertSame(0, $connection->rollbackCount);
        $this->assertSame(
            [
                'SAVEPOINT child_stage',
                'ROLLBACK TO SAVEPOINT child_stage',
            ],
            $connection->executedSql
        );
        $this->assertSame(0, TransactionManager::getTransactionLevel());
    }

    public function testParallelFibersOwnIndependentRootTransactionsAndFinishModes(): void
    {
        $connectionA = new FakeConnection();
        $connectionB = new FakeConnection();
        $observed = [];

        $fiberA = new Fiber(function () use ($connectionA, &$observed): void {
            Context::enter(new Context());
            try {
                self::injectConnection($connectionA);
                self::assertTrue(TransactionManager::beginTransaction('fiber-a'));
                Fiber::suspend('a-ready');

                $observed['a_before_commit'] = TransactionManager::getTransactionLevel();
                self::assertTrue(TransactionManager::commit('fiber-a'));
                $observed['a_after_commit'] = TransactionManager::getTransactionLevel();
                Fiber::suspend('a-committed');
            } finally {
                TransactionManager::cleanup();
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use ($connectionB, &$observed): void {
            Context::enter(new Context());
            try {
                self::injectConnection($connectionB);
                self::assertTrue(TransactionManager::beginTransaction('fiber-b'));
                Fiber::suspend('b-ready');

                $observed['b_after_a_commit'] = TransactionManager::getTransactionLevel();
                self::assertTrue(TransactionManager::rollback('fiber-b'));
                $observed['b_after_rollback'] = TransactionManager::getTransactionLevel();
                Fiber::suspend('b-rolled-back');
            } finally {
                TransactionManager::cleanup();
                Context::leave();
            }
        });

        self::assertSame('a-ready', $fiberA->start());
        self::assertSame('b-ready', $fiberB->start());
        self::assertSame('a-committed', $fiberA->resume());
        self::assertSame('b-rolled-back', $fiberB->resume());

        self::assertSame(1, $observed['a_before_commit']);
        self::assertSame(0, $observed['a_after_commit']);
        self::assertSame(1, $observed['b_after_a_commit']);
        self::assertSame(0, $observed['b_after_rollback']);
        self::assertSame(1, $connectionA->beginCount);
        self::assertSame(1, $connectionA->commitCount);
        self::assertSame(0, $connectionA->rollbackCount);
        self::assertSame([], $connectionA->executedSql);
        self::assertSame(1, $connectionB->beginCount);
        self::assertSame(0, $connectionB->commitCount);
        self::assertSame(1, $connectionB->rollbackCount);
        self::assertSame([], $connectionB->executedSql);

        $fiberA->resume();
        $fiberB->resume();
        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
    }

    public function testCleanupInOneFiberDoesNotClearPeerNestedTransactionState(): void
    {
        $connectionA = new FakeConnection();
        $connectionB = new FakeConnection();
        $observed = [];

        $fiberA = new Fiber(function () use ($connectionA, &$observed): void {
            Context::enter(new Context());
            try {
                self::injectConnection($connectionA);
                self::assertTrue(TransactionManager::beginTransaction('fiber-a'));
                Fiber::suspend('a-ready');

                TransactionManager::cleanup();
                $observed['a_after_cleanup'] = TransactionManager::getTransactionLevel();
                Fiber::suspend('a-cleaned');
            } finally {
                TransactionManager::cleanup();
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use ($connectionB, &$observed): void {
            Context::enter(new Context());
            try {
                self::injectConnection($connectionB);
                self::assertTrue(TransactionManager::beginTransaction('fiber-b'));
                Fiber::suspend('b-ready');

                $observed['b_after_a_cleanup'] = TransactionManager::getTransactionLevel();
                self::assertTrue(TransactionManager::beginTransaction('fiber-b-child'));
                $observed['b_nested'] = TransactionManager::getTransactionLevel();
                self::assertTrue(TransactionManager::commit('fiber-b-child'));
                self::assertTrue(TransactionManager::commit('fiber-b'));
                $observed['b_after_commits'] = TransactionManager::getTransactionLevel();
                Fiber::suspend('b-committed');
            } finally {
                TransactionManager::cleanup();
                Context::leave();
            }
        });

        self::assertSame('a-ready', $fiberA->start());
        self::assertSame('b-ready', $fiberB->start());
        self::assertSame('a-cleaned', $fiberA->resume());
        self::assertSame('b-committed', $fiberB->resume());

        self::assertSame(0, $observed['a_after_cleanup']);
        self::assertSame(1, $observed['b_after_a_cleanup']);
        self::assertSame(2, $observed['b_nested']);
        self::assertSame(0, $observed['b_after_commits']);
        self::assertSame(['SAVEPOINT fiber_b_child', 'RELEASE SAVEPOINT fiber_b_child'], $connectionB->executedSql);
        self::assertSame(1, $connectionB->beginCount);
        self::assertSame(1, $connectionB->commitCount);

        $fiberA->resume();
        $fiberB->resume();
        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
    }

    public function testParallelFibersCreateAndCloseIndependentConnectorsFromSharedFactory(): void
    {
        $connectionA = new FakeConnection();
        $connectionB = new FakeConnection();

        $connectorA = $this->createMock(ConnectorInterface::class);
        $connectorA->expects(self::once())->method('create')->willReturnSelf();
        $connectorA->expects(self::once())->method('getWrappedConnection')->willReturn($connectionA);
        $connectorA->expects(self::once())->method('close');

        $connectorB = $this->createMock(ConnectorInterface::class);
        $connectorB->expects(self::once())->method('create')->willReturnSelf();
        $connectorB->expects(self::once())->method('getWrappedConnection')->willReturn($connectionB);
        $connectorB->expects(self::once())->method('close');

        $factory = $this->getMockBuilder(ConnectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnectorAdapter', 'getConnector'])
            ->getMock();
        $factory->expects(self::exactly(2))
            ->method('getConnectorAdapter')
            ->willReturnOnConsecutiveCalls($connectorA, $connectorB);
        $factory->expects(self::never())->method('getConnector');

        $fiberA = new Fiber(function () use ($factory): void {
            Context::enter(new Context());
            try {
                ObjectManager::setInstance(ConnectionFactory::class, $factory);
                self::assertTrue(TransactionManager::beginTransaction('fiber-a-factory'));
                Fiber::suspend('a-ready');
                self::assertTrue(TransactionManager::commit('fiber-a-factory'));
            } finally {
                TransactionManager::cleanup();
                ObjectManager::removeInstance(ConnectionFactory::class);
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use ($factory): void {
            Context::enter(new Context());
            try {
                ObjectManager::setInstance(ConnectionFactory::class, $factory);
                self::assertTrue(TransactionManager::beginTransaction('fiber-b-factory'));
                Fiber::suspend('b-ready');
                self::assertTrue(TransactionManager::rollback('fiber-b-factory'));
            } finally {
                TransactionManager::cleanup();
                ObjectManager::removeInstance(ConnectionFactory::class);
                Context::leave();
            }
        });

        self::assertSame('a-ready', $fiberA->start());
        self::assertSame('b-ready', $fiberB->start());
        $fiberA->resume();
        $fiberB->resume();

        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
        self::assertSame(1, $connectionA->beginCount);
        self::assertSame(1, $connectionA->commitCount);
        self::assertSame(0, $connectionA->rollbackCount);
        self::assertSame(1, $connectionB->beginCount);
        self::assertSame(0, $connectionB->commitCount);
        self::assertSame(1, $connectionB->rollbackCount);
    }

    public function testCleanupFailureIsPropagatedAfterRequestStateIsRemoved(): void
    {
        $connection = new FakeConnection();
        self::injectConnection($connection);
        self::assertTrue(TransactionManager::beginTransaction('cleanup-failure'));
        $connection->rollBackResult = false;

        try {
            TransactionManager::cleanup();
            self::fail('Expected request cleanup failure to be propagated.');
        } catch (RequestResetException $exception) {
            self::assertContains('rollback', $exception->stages());
        }

        self::assertSame(1, $connection->rollbackCount);
        self::assertSame(0, TransactionManager::getTransactionLevel());
    }

    public function testCleanupRetainsConnectorUntilRollbackCanBeMadeSafe(): void
    {
        $connection = new FakeConnection();
        $closeCalls = 0;
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects(self::once())
            ->method('close')
            ->willReturnCallback(static function () use (&$closeCalls): void {
                $closeCalls++;
            });
        self::injectConnection($connection, $connector);
        self::assertTrue(TransactionManager::beginTransaction('unsafe-cleanup'));
        $connection->rollBackResult = false;

        try {
            TransactionManager::cleanup();
            self::fail('Expected the unsafe cleanup failure to be propagated.');
        } catch (RequestResetException $exception) {
            self::assertContains('rollback', $exception->stages());
            self::assertContains('mark_connection_unhealthy', $exception->stages());
        }

        self::assertSame(0, $closeCalls, 'An unmarked dirty connector must not be released.');
        self::assertSame(1, TransactionManager::getTransactionLevel());

        $connection->rollBackResult = true;
        TransactionManager::cleanup();

        self::assertSame(1, $closeCalls);
        self::assertSame(2, $connection->rollbackCount);
        self::assertSame(0, TransactionManager::getTransactionLevel());
    }

    public function testConnectorAcquirePreservesWrappedAndCloseFailures(): void
    {
        TransactionManager::cleanup();
        $wrappedFailure = new \RuntimeException('wrapped-connection-failure');
        $closeFailure = new \RuntimeException('connector-close-failure');
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects(self::once())->method('create')->willReturnSelf();
        $connector->expects(self::once())
            ->method('getWrappedConnection')
            ->willThrowException($wrappedFailure);
        $connector->expects(self::once())
            ->method('close')
            ->willThrowException($closeFailure);

        $factory = $this->getMockBuilder(ConnectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnectorAdapter'])
            ->getMock();
        $factory->expects(self::once())->method('getConnectorAdapter')->willReturn($connector);

        $originalFactory = ObjectManager::_getInstance(ConnectionFactory::class);
        ObjectManager::setInstance(ConnectionFactory::class, $factory);
        try {
            $method = (new ReflectionClass(TransactionManager::class))->getMethod('getConnection');
            $method->setAccessible(true);
            try {
                $method->invoke(null);
                self::fail('Expected connector acquisition failures to be aggregated.');
            } catch (\RuntimeException $exception) {
                self::assertSame($wrappedFailure, $exception->getPrevious());
                self::assertStringContainsString('wrapped-connection-failure', $exception->getMessage());
                self::assertStringContainsString('connector-close-failure', $exception->getMessage());
            }
        } finally {
            ObjectManager::removeInstance(ConnectionFactory::class);
            if ($originalFactory !== null) {
                ObjectManager::setInstance(ConnectionFactory::class, $originalFactory);
            }
        }
    }

    public function testModuleResetterClearsFieldStateAndPropagatesConnectorCloseFailure(): void
    {
        TableContext::setTableContext('resetter-scope', [
            'scope' => 'resetter-scope',
            'marker' => 'before-reset',
        ]);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects(self::once())
            ->method('close')
            ->willThrowException(new \RuntimeException('connector-close-failure'));
        self::injectConnection(new FakeConnection(), $connector);

        try {
            (new RequestResetter())->resetRequest();
            self::fail('Expected module resetter failure to be propagated.');
        } catch (RequestResetException $exception) {
            self::assertContains('transaction_manager/connector_close', $exception->stages());
        }

        self::assertSame([], TableContext::getAllTableContexts());
        self::assertSame(0, TransactionManager::getTransactionLevel());
    }

    private static function injectConnection(
        ConnectionInterface $connection,
        ?ConnectorInterface $connector = null,
    ): void
    {
        TransactionManager::cleanup();

        $reflection = new ReflectionClass(TransactionManager::class);
        $method = $reflection->getMethod('storeRequestState');
        $method->setAccessible(true);
        $method->invoke(null, [
            'connector' => $connector,
            'connection' => $connection,
            'transaction_stack' => [],
            'savepoints' => [],
            'transaction_level' => 0,
        ]);
    }
}

final class FakeConnection implements ConnectionInterface
{
    public int $beginCount = 0;

    public int $commitCount = 0;

    public int $rollbackCount = 0;

    public bool $rollBackResult = true;

    /**
     * @var array<int, string>
     */
    public array $executedSql = [];

    private bool $inTransaction = false;

    public function prepare(string $sql): PDOStatement
    {
        throw new \BadMethodCallException('prepare() is not used in this test double.');
    }

    public function execute(string $sql): int
    {
        $this->executedSql[] = $sql;
        return 1;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '1';
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string
    {
        return "'" . addslashes($string) . "'";
    }

    public function beginTransaction(): bool
    {
        $this->beginCount++;
        $this->inTransaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->commitCount++;
        $this->inTransaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->rollbackCount++;
        if ($this->rollBackResult) {
            $this->inTransaction = false;
        }
        return $this->rollBackResult;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function getDriverType(): string
    {
        return 'test';
    }

    public function getServerVersion(): string
    {
        return 'test';
    }

    public function getPdo(): PDO
    {
        throw new \BadMethodCallException('getPdo() is not used in this test double.');
    }
}
