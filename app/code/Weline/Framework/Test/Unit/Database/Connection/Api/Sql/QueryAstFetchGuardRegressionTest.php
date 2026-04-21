<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Api\Sql;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\Sql\QueryAst;
use Weline\Framework\Database\Exception\DbException;

class QueryAstFetchGuardRegressionFakeStatement extends PDOStatement
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;

    private int $cursor = 0;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }

        return $this->rows[$this->cursor++];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($this->cursor >= count($this->rows)) {
            return [];
        }

        $rows = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);
        return $rows;
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

final class QueryAstFetchGuardRegressionUnsupportedNextRowsetStatement extends QueryAstFetchGuardRegressionFakeStatement
{
    public function nextRowset(): bool
    {
        throw new \PDOException('driver does not support multiple rowsets');
    }
}

final class QueryAstFetchGuardRegressionQuery extends QueryAst
{
    public int $rowLimit = 10000;

    protected function prepareSql(string $action): void
    {
    }

    public function getLink(): PDO
    {
        throw new \RuntimeException('Database link is not needed for this unit test.');
    }

    public function getColumnDefinition(string $tableName, string $fieldName): ?array
    {
        return null;
    }

    protected function getFetchRowLimit(): int
    {
        return $this->rowLimit;
    }

    public function collectForTest(PDOStatement $statement, int $threshold = 0): array
    {
        return $this->collectStatementResultSets($statement, $threshold);
    }
}

final class QueryAstFetchSmartRegressionQuery extends QueryAst
{
    public int $rowLimit = 10000;

    public string $path = '';

    protected function prepareSql(string $action): void
    {
    }

    public function getLink(): PDO
    {
        throw new \RuntimeException('Database link is not needed for this unit test.');
    }

    public function getColumnDefinition(string $tableName, string $fieldName): ?array
    {
        return null;
    }

    protected function getFetchRowLimit(): int
    {
        return $this->rowLimit;
    }

    public function fetch(string $model_class = ''): mixed
    {
        $this->path = 'fetch';
        return [['id' => 1]];
    }

    public function fetchIterator(string $model_class = '', int $batchSize = 1): \Generator
    {
        $this->path = 'iterator';
        yield ['id' => 1];
    }
}

final class QueryAstFetchGuardRegressionTest extends TestCase
{
    public function testFetchAllowsSmallUnboundedSelectsAndReturnsRows(): void
    {
        $query = new QueryAstFetchGuardRegressionQuery();
        $query->rowLimit = 2;
        $query->fetch_type = 'select';
        $query->PDOStatement = new QueryAstFetchGuardRegressionFakeStatement([
            ['id' => 1],
            ['id' => 2],
        ]);

        self::assertSame(
            [
                ['id' => 1],
                ['id' => 2],
            ],
            $query->fetch()
        );
    }

    public function testFetchThrowsOnlyAfterThresholdIsActuallyExceeded(): void
    {
        $query = new QueryAstFetchGuardRegressionQuery();
        $query->rowLimit = 1;
        $query->fetch_type = 'select';
        $query->PDOStatement = new QueryAstFetchGuardRegressionFakeStatement([
            ['id' => 1],
            ['id' => 2],
        ]);

        $this->expectException(DbException::class);
        $this->expectExceptionMessage('Current threshold: 1');

        $query->fetch();
    }

    public function testFetchSmartStreamsUnboundedSelects(): void
    {
        $query = new QueryAstFetchSmartRegressionQuery();
        $query->rowLimit = 5;
        $query->fetch_type = 'select';
        $query->sql = 'SELECT * FROM demo';

        $result = $query->fetchSmart();

        self::assertSame([['id' => 1]], iterator_to_array($result, false));
        self::assertSame('iterator', $query->path);
    }

    public function testFetchSmartUsesFetchForExplicitlyBoundedSelects(): void
    {
        $query = new QueryAstFetchSmartRegressionQuery();
        $query->rowLimit = 5;
        $query->fetch_type = 'select';
        $query->sql = 'SELECT * FROM demo LIMIT 2';
        $query->limit = 'LIMIT 2';

        $result = $query->fetchSmart();

        self::assertSame([['id' => 1]], $result);
        self::assertSame('fetch', $query->path);
    }

    public function testFetchSmartTreatsMysqlStyleLimitAsBoundedByPageSize(): void
    {
        $query = new QueryAstFetchSmartRegressionQuery();
        $query->rowLimit = 5;
        $query->fetch_type = 'select';
        $query->sql = 'SELECT * FROM demo LIMIT 10, 2';
        $query->limit = 'LIMIT 10, 2';

        $result = $query->fetchSmart();

        self::assertSame([['id' => 1]], $result);
        self::assertSame('fetch', $query->path);
    }

    public function testUnsupportedNextRowsetIsTreatedAsSingleResultSet(): void
    {
        $query = new QueryAstFetchGuardRegressionQuery();

        self::assertSame(
            [
                ['id' => 1],
            ],
            $query->collectForTest(new QueryAstFetchGuardRegressionUnsupportedNextRowsetStatement([
                ['id' => 1],
            ]))
        );
    }
}
