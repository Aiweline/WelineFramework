<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Integration\Database;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Query;

final class PgsqlPrepareLifecycleIntegrationQuery extends Query
{
    public int $prepareCalls = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getLink(): PDO
    {
        return $this->pdo;
    }

    protected function preparePgsql(string $sql, array $options = []): PDOStatement|false
    {
        $this->prepareCalls++;
        return parent::preparePgsql($sql, $options);
    }
}

final class PgsqlPrepareLifecycleIntegrationTest extends TestCase
{
    public function testPrimaryPostgreSqlReadPathsPrepareOnceAndReturnExpectedResults(): void
    {
        if (getenv('WELINE_TEST_PRIMARY_PGSQL') !== '1') {
            self::markTestSkipped('Set WELINE_TEST_PRIMARY_PGSQL=1 for the explicit primary PostgreSQL gate.');
        }

        $pdo = $this->connectPrimaryPostgreSql();

        $ordinary = new PgsqlPrepareLifecycleIntegrationQuery($pdo);
        $ordinary->query('SELECT CAST(:probe AS INTEGER) AS probe');
        $ordinary->bound_values = [':probe' => 7];
        $ordinaryResult = $ordinary->fetch();

        self::assertSame(7, (int) ($ordinaryResult[0]['probe'] ?? -1));
        self::assertSame(1, $ordinary->prepareCalls);
        self::assertNull($ordinary->PDOStatement);

        $iterator = new PgsqlPrepareLifecycleIntegrationQuery($pdo);
        $iterator->query(
            'SELECT CAST(:probe AS INTEGER) AS probe '
            . 'UNION ALL SELECT CAST(:probe_next AS INTEGER) AS probe ORDER BY probe'
        );
        $iterator->bound_values = [':probe' => 7, ':probe_next' => 8];
        $iteratorResult = iterator_to_array($iterator->fetchIterator(), false);

        self::assertSame([7, 8], array_map(
            static fn (array $row): int => (int) $row['probe'],
            $iteratorResult
        ));
        self::assertSame(1, $iterator->prepareCalls);
        self::assertNull($iterator->PDOStatement);

        $pdo->beginTransaction();
        try {
            $transactional = new PgsqlPrepareLifecycleIntegrationQuery($pdo);
            $transactional->query('SELECT CAST(:probe AS INTEGER) AS probe');
            $transactional->bound_values = [':probe' => 9];
            self::assertSame(9, (int) ($transactional->fetch()[0]['probe'] ?? -1));
            self::assertSame(1, $transactional->prepareCalls);
            self::assertTrue($pdo->inTransaction());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        self::assertFalse($pdo->inTransaction());
    }

    private function connectPrimaryPostgreSql(): PDO
    {
        $config = include BP . 'app/etc/env.php';
        $master = $config['db']['master'] ?? [];
        self::assertSame('pgsql', $master['type'] ?? null);

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $master['hostname'],
            $master['hostport'],
            $master['database']
        );
        return new PDO(
            $dsn,
            $master['username'],
            $master['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
