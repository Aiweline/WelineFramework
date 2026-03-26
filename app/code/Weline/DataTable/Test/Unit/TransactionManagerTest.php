<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use PDO;
use PDOStatement;
use ReflectionClass;
use Weline\DataTable\Helper\TransactionManager;
use Weline\Framework\Database\Connection\ConnectionInterface;
use Weline\Framework\UnitTest\TestCore;

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

    private function injectConnection(ConnectionInterface $connection): void
    {
        TransactionManager::cleanup();

        $reflection = new ReflectionClass(TransactionManager::class);
        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $property->setValue(null, $connection);
    }
}

final class FakeConnection implements ConnectionInterface
{
    public int $beginCount = 0;

    public int $commitCount = 0;

    public int $rollbackCount = 0;

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
        $this->inTransaction = false;
        return true;
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
