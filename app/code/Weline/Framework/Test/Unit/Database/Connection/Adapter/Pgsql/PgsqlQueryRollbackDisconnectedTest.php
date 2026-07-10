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

final class PgsqlQueryRetryDisconnectedStatement extends \PDOStatement
{
    /** @var list<array<string, mixed>> */
    private array $rows;

    private int $cursor = 0;
    private int $executeCalls;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(int &$executeCalls, array $rows = [])
    {
        $this->executeCalls = &$executeCalls;
        $this->rows = $rows;
    }

    public function execute(?array $params = null): bool
    {
        unset($params);
        $this->executeCalls++;
        if ($this->executeCalls === 1) {
            throw new \PDOException('SQLSTATE[HY000]: General error: 7 no connection to the server');
        }

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        unset($mode, $cursorOrientation, $cursorOffset);

        return $this->rows[$this->cursor++] ?? false;
    }

    public function nextRowset(): bool
    {
        return false;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}

final class PgsqlQueryRetryDisconnectedQuery extends Query
{
    public int $prepareCalls = 0;
    public int $executeCalls = 0;
    public int $reconnectCalls = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getLink(): PDO
    {
        return $this->pdo;
    }

    protected function preparePgsql(string $sql, array $options = []): \PDOStatement|false
    {
        unset($options);
        $this->prepareCalls++;
        $this->sql = $sql;

        return new PgsqlQueryRetryDisconnectedStatement(
            $this->executeCalls,
            [['probe' => 'ok']]
        );
    }

    protected function reconnectAfterDisconnect(\PDOException $exception): bool
    {
        unset($exception);
        $this->reconnectCalls++;

        return true;
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

    public function testSafeReadRetriesOnceAfterDisconnect(): void
    {
        $query = new PgsqlQueryRetryDisconnectedQuery(
            new PgsqlQueryRollbackDisconnectedFakePdo(false, false)
        );

        $result = $query->query('SELECT 1 AS probe')->fetch();

        self::assertSame([['probe' => 'ok']], $result);
        self::assertSame(1, $query->reconnectCalls);
        self::assertSame(2, $query->executeCalls);
    }

    public function testNonReadSqlDoesNotRetryAfterDisconnect(): void
    {
        $query = new PgsqlQueryRetryDisconnectedQuery(
            new PgsqlQueryRollbackDisconnectedFakePdo(false, false)
        );

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('no connection to the server');

        try {
            $query->query('UPDATE unit_table SET value = 1')->fetch();
        } finally {
            self::assertSame(0, $query->reconnectCalls);
            self::assertSame(1, $query->executeCalls);
        }
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
