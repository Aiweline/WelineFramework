<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Adapter\Pgsql;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Query;

final class PgsqlPrepareLifecycleFakePdo extends PDO
{
    public function __construct()
    {
    }

    public function errorInfo(): array
    {
        return ['HY000', 0, 'Prepare failed'];
    }
}

final class PgsqlPrepareLifecycleStatement extends PDOStatement
{
    /** @var list<array<string, mixed>> */
    private array $rows;
    private int $cursor = 0;
    private bool $executed = false;
    private int $executeCalls;
    private int $closeCursorCalls;
    private bool $throwOnExecute;
    private bool $throwOnFetch;
    private bool $throwOnClose;
    /** @var list<array<string, mixed>> */
    private array $executedBindings;

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $executedBindings
     */
    public function __construct(
        int &$executeCalls,
        int &$closeCursorCalls,
        array &$executedBindings,
        array $rows,
        bool $throwOnExecute = false,
        bool $throwOnFetch = false,
        bool $throwOnClose = false
    ) {
        $this->executeCalls = &$executeCalls;
        $this->closeCursorCalls = &$closeCursorCalls;
        $this->executedBindings = &$executedBindings;
        $this->rows = array_values($rows);
        $this->throwOnExecute = $throwOnExecute;
        $this->throwOnFetch = $throwOnFetch;
        $this->throwOnClose = $throwOnClose;
    }

    public function execute(?array $params = null): bool
    {
        $this->executeCalls++;
        $this->executedBindings[] = $params ?? [];
        if ($this->throwOnExecute) {
            throw new \PDOException('SQLSTATE[08006]: connection failure: execute failed');
        }
        $this->executed = true;
        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        unset($mode, $cursorOrientation, $cursorOffset);
        if ($this->throwOnFetch) {
            throw new \PDOException('SQLSTATE[08006]: connection failure: fetch failed');
        }
        if (!$this->executed) {
            return false;
        }
        return $this->rows[$this->cursor++] ?? false;
    }

    public function nextRowset(): bool
    {
        return false;
    }

    public function closeCursor(): bool
    {
        $this->closeCursorCalls++;
        if ($this->throwOnClose) {
            throw new \PDOException('SQLSTATE[HY000]: close cursor failed');
        }
        return true;
    }
}

final class PgsqlPrepareLifecycleQuery extends Query
{
    public int $prepareCalls = 0;
    public int $executeCalls = 0;
    public int $closeCursorCalls = 0;
    /** @var list<string> */
    public array $preparedSql = [];
    /** @var list<array<string, mixed>> */
    public array $executedBindings = [];
    public bool $prepareReturnsFalse = false;
    public bool $throwOnExecute = false;
    public bool $throwOnFetch = false;
    public bool $throwOnClose = false;

    private PDO $pdo;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
        $this->pdo = new PgsqlPrepareLifecycleFakePdo();
    }

    public function getLink(): PDO
    {
        return $this->pdo;
    }

    protected function preparePgsql(string $sql, array $options = []): PDOStatement|false
    {
        unset($options);
        $this->prepareCalls++;
        $this->preparedSql[] = $sql;
        if ($this->prepareReturnsFalse) {
            return false;
        }
        return new PgsqlPrepareLifecycleStatement(
            $this->executeCalls,
            $this->closeCursorCalls,
            $this->executedBindings,
            $this->rows,
            $this->throwOnExecute,
            $this->throwOnFetch,
            $this->throwOnClose
        );
    }
}

final class PgsqlPrepareLifecycleTest extends TestCase
{
    public function testOrdinaryFetchPreparesAndExecutesExactlyOnce(): void
    {
        $query = new PgsqlPrepareLifecycleQuery([['probe' => 7]]);
        $query->query('SELECT :probe AS probe');
        $query->bound_values = [':probe' => 7];

        $result = $query->fetch();

        self::assertSame([['probe' => 7]], $result);
        self::assertSame(['SELECT :probe AS probe'], $query->preparedSql);
        self::assertSame(1, $query->prepareCalls);
        self::assertSame(1, $query->executeCalls);
        self::assertSame([[':probe' => 7]], $query->executedBindings);
        self::assertSame(1, $query->closeCursorCalls);
        self::assertNull($query->PDOStatement);
    }

    public function testIteratorExecutesPreparedStatementAndClosesCursor(): void
    {
        $query = new PgsqlPrepareLifecycleQuery([
            ['probe' => 7],
            ['probe' => 8],
        ]);
        $query->query('SELECT :probe AS probe');
        $query->bound_values = [':probe' => 7];

        $result = iterator_to_array($query->fetchIterator(), false);

        self::assertSame([['probe' => 7], ['probe' => 8]], $result);
        self::assertSame(1, $query->prepareCalls);
        self::assertSame(1, $query->executeCalls);
        self::assertSame([[':probe' => 7]], $query->executedBindings);
        self::assertSame(1, $query->closeCursorCalls);
        self::assertNull($query->PDOStatement);
    }

    public function testIteratorPreparesWhenNoEagerStatementExists(): void
    {
        $query = new PgsqlPrepareLifecycleQuery([['probe' => 7]]);
        $query->sql = 'SELECT :probe AS probe';
        $query->bound_values = [':probe' => 7];

        $result = iterator_to_array($query->fetchIterator(), false);

        self::assertSame([['probe' => 7]], $result);
        self::assertSame(1, $query->prepareCalls);
        self::assertSame(1, $query->executeCalls);
        self::assertSame(1, $query->closeCursorCalls);
        self::assertNull($query->PDOStatement);
        self::assertSame('', $query->sql);
    }

    public function testIteratorPrepareFailureStillResetsQueryState(): void
    {
        $query = new PgsqlPrepareLifecycleQuery([]);
        $query->prepareReturnsFalse = true;
        $query->sql = 'SELECT :probe AS probe';
        $query->bound_values = [':probe' => 7];

        try {
            iterator_to_array($query->fetchIterator(), false);
            self::fail('Expected prepare failure.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('Prepare failed', $exception->getMessage());
        }

        self::assertSame(1, $query->prepareCalls);
        self::assertSame(0, $query->executeCalls);
        self::assertSame(0, $query->closeCursorCalls);
        self::assertNull($query->PDOStatement);
        self::assertSame('', $query->sql);
    }

    public function testIteratorPreservesExecuteExceptionWhenCursorCleanupAlsoFails(): void
    {
        $query = new PgsqlPrepareLifecycleQuery([]);
        $query->throwOnExecute = true;
        $query->throwOnClose = true;
        $query->query('SELECT :probe AS probe');
        $query->bound_values = [':probe' => 7];

        try {
            iterator_to_array($query->fetchIterator(), false);
            self::fail('Expected execute failure.');
        } catch (\PDOException $exception) {
            self::assertStringContainsString('execute failed', $exception->getMessage());
            self::assertStringNotContainsString('close cursor failed', $exception->getMessage());
        }

        self::assertSame(1, $query->executeCalls);
        self::assertSame(1, $query->closeCursorCalls);
        self::assertNull($query->PDOStatement);
        self::assertSame('', $query->sql);
    }

    public function testIteratorPreservesFetchExceptionWhenCursorCleanupAlsoFails(): void
    {
        $query = new PgsqlPrepareLifecycleQuery([]);
        $query->throwOnFetch = true;
        $query->throwOnClose = true;
        $query->query('SELECT :probe AS probe');
        $query->bound_values = [':probe' => 7];

        try {
            iterator_to_array($query->fetchIterator(), false);
            self::fail('Expected fetch failure.');
        } catch (\PDOException $exception) {
            self::assertStringContainsString('fetch failed', $exception->getMessage());
            self::assertStringNotContainsString('close cursor failed', $exception->getMessage());
        }

        self::assertSame(1, $query->executeCalls);
        self::assertSame(1, $query->closeCursorCalls);
        self::assertNull($query->PDOStatement);
        self::assertSame('', $query->sql);
    }

    public function testIteratorEarlyTerminationClosesCursorAndResetsState(): void
    {
        $query = new PgsqlPrepareLifecycleQuery([
            ['probe' => 7],
            ['probe' => 8],
        ]);
        $query->query('SELECT :probe AS probe');
        $query->bound_values = [':probe' => 7];

        $iterator = $query->fetchIterator();
        self::assertSame(['probe' => 7], $iterator->current());
        unset($iterator);
        gc_collect_cycles();

        self::assertSame(1, $query->executeCalls);
        self::assertSame(1, $query->closeCursorCalls);
        self::assertNull($query->PDOStatement);
        self::assertSame('', $query->sql);
    }

    public function testIteratorPropagatesCleanupFailureAfterSuccessfulReadButStillResetsState(): void
    {
        $query = new PgsqlPrepareLifecycleQuery([['probe' => 7]]);
        $query->throwOnClose = true;
        $query->query('SELECT :probe AS probe');

        try {
            iterator_to_array($query->fetchIterator(), false);
            self::fail('Expected close cursor failure.');
        } catch (\PDOException $exception) {
            self::assertStringContainsString('close cursor failed', $exception->getMessage());
        }

        self::assertSame(1, $query->closeCursorCalls);
        self::assertNull($query->PDOStatement);
        self::assertSame('', $query->sql);
    }
}
