<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Adapter\Pgsql;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Query;

final class PgsqlQueryRollbackDisconnectedFakePdo extends PDO
{
    public function __construct(
        private readonly bool $throwOnInTransaction,
        private readonly bool $inTransaction,
        private readonly string $rollbackMessage = 'SQLSTATE[HY000]: General error: 7 no connection to the server'
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
        throw new \PDOException($this->rollbackMessage);
    }
}

final class PgsqlQueryRollbackDisconnectedQuery extends Query
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getLink(): PDO
    {
        return $this->pdo;
    }
}

final class PgsqlQueryRollbackDisconnectedTest extends TestCase
{
    public function testRollbackDoesNotThrowWhenActiveTransactionConnectionIsGone(): void
    {
        $query = new PgsqlQueryRollbackDisconnectedQuery(
            new PgsqlQueryRollbackDisconnectedFakePdo(false, true)
        );

        $query->rollBack();

        self::assertTrue(true);
    }

    public function testRollbackDoesNotThrowWhenTransactionCheckConnectionIsGone(): void
    {
        $query = new PgsqlQueryRollbackDisconnectedQuery(
            new PgsqlQueryRollbackDisconnectedFakePdo(true, false)
        );

        $query->rollBack();

        self::assertTrue(true);
    }

    public function testRollbackStillThrowsUnexpectedRollbackFailure(): void
    {
        $query = new PgsqlQueryRollbackDisconnectedQuery(
            new PgsqlQueryRollbackDisconnectedFakePdo(
                false,
                true,
                'SQLSTATE[HY000]: General error: unexpected rollback failure'
            )
        );

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('unexpected rollback failure');

        $query->rollBack();
    }
}
