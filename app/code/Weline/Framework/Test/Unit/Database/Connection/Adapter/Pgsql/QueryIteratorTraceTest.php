<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Adapter\Pgsql;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Context;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Query;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class QueryIteratorTraceTest extends TestCase
{
    private PDO $pdo;
    private mixed $previousTrace;

    protected function setUp(): void
    {
        $this->previousTrace = Env::get('wls.debug.request_trace', false);
        Env::getInstance()->applyRuntimeConfig(['wls' => ['debug' => ['request_trace' => true]]]);
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context([
            'input' => ['uri' => '/products'],
            'runtime' => ['request_context' => ['initialized' => true, 'request_id' => uniqid('iterator-trace-', true)]],
        ]));
        self::assertTrue(RequestContext::isInitialized());
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE trace_fixture (id INTEGER, label TEXT)');
        $insert = $this->pdo->prepare('INSERT INTO trace_fixture VALUES (?, ?)');
        foreach ([1, 2, 3] as $id) {
            $insert->execute([$id, 'fixture bound value']);
        }
    }

    protected function tearDown(): void
    {
        RequestLifecycleTrace::reset();
        Context::leave();
        Runtime::resetModeCache();
        Env::getInstance()->applyRuntimeConfig(['wls' => ['debug' => ['request_trace' => $this->previousTrace]]]);
    }

    public function testOneSqlProducesOneSpanWithBoundPlaceholdersAndExcludesConsumerTime(): void
    {
        $this->pdo->sqliteCreateFunction('fixture_read', static function (int $id): int {
            usleep(3000);
            return $id;
        }, 1);
        foreach ([1, 2] as $batchSize) {
            RequestLifecycleTrace::reset();
            $query = $this->query('SELECT fixture_read(id) AS id, label FROM trace_fixture WHERE label = :wanted ORDER BY id', [':wanted' => 'fixture bound value']);
            self::assertTrue(RequestLifecycleTrace::isEnabled(), 'The actual Query iterator must run inside an enabled request trace.');
            $rows = [];
            $batchLengths = [];
            $consumerMs = 0.0;
            $started = hrtime(true);
            foreach ($query->fetchIterator('', $batchSize) as $yielded) {
                $batch = $batchSize === 1 ? [$yielded] : $yielded;
                $rows = array_merge($rows, $batch);
                $batchLengths[] = count($batch);
                $consumerStarted = hrtime(true);
                usleep(60000);
                $consumerMs += (hrtime(true) - $consumerStarted) / 1e6;
            }
            $elapsedMs = (hrtime(true) - $started) / 1e6;
            self::assertSame([1, 2, 3], array_column($rows, 'id'));
            self::assertSame($batchSize === 1 ? [1, 1, 1] : [2, 1], $batchLengths);
            self::assertCount(1, $query->preparedSql);
            $spans = $this->databaseSpans();
            self::assertCount(1, $spans, 'One SQL must produce one DB span, independently of row or batch yields.');
            self::assertSame(1, RequestLifecycleTrace::getAggregateSummary()['db_span_count']);
            self::assertSame($query->preparedSql[0], $spans[0]['meta']['sql']);
            self::assertStringContainsString(':wanted', $spans[0]['meta']['sql']);
            self::assertStringNotContainsString('fixture bound value', $spans[0]['meta']['sql']);
            self::assertSame('select', $spans[0]['meta']['operation']);
            self::assertSame('trace_fixture', $spans[0]['meta']['table']);
            self::assertSame('iterator', $spans[0]['meta']['fetch_mode']);
            self::assertSame('driver_active_excluding_yield', $spans[0]['meta']['measurement']);
            self::assertGreaterThanOrEqual(6.0, $spans[0]['duration_ms'], 'Real SQLite execution invokes the small delay function for all rows.');
            self::assertLessThan($elapsedMs - $consumerMs + 25.0, $spans[0]['duration_ms'], 'Consumer pauses must not be counted as database time.');
            self::assertNull($query->PDOStatement);
            self::assertSame('', $query->sql);
        }

        // A non-SELECT iterator delegates to fetchArray()/fetch(); only that
        // existing execution may emit a span, never a second iterator span.
        RequestLifecycleTrace::reset();
        $update = $this->query('UPDATE trace_fixture SET label = :replacement WHERE id = :id', [':replacement' => 'updated', ':id' => 1], 'update');
        $delegatedFailure = null;
        try {
            iterator_to_array($update->fetchIterator());
        } catch (\TypeError $failure) {
            $delegatedFailure = $failure;
        }
        // Existing fetchArray(): array rejects the UPDATE's integer result.
        // Preserve that delegated behavior; this tracing change does not fix it.
        self::assertInstanceOf(\TypeError::class, $delegatedFailure);
        self::assertSame('updated', $this->pdo->query('SELECT label FROM trace_fixture WHERE id = 1')->fetchColumn());
        self::assertCount(1, $this->databaseSpans());
        self::assertSame(1, RequestLifecycleTrace::getAggregateSummary()['db_span_count']);
    }

    public function testUnstartedEarlyDestroyedAndFailedIteratorsKeepTheirExistingContracts(): void
    {
        $unstartedQuery = $this->query('SELECT id FROM trace_fixture');
        $unstarted = $unstartedQuery->fetchIterator();
        unset($unstarted);
        self::assertSame([], $unstartedQuery->preparedSql);
        self::assertSame([], $this->databaseSpans(), 'Creating a generator without starting it executes no SQL.');

        $query = $this->query('SELECT id FROM trace_fixture ORDER BY id');
        $iterator = $query->fetchIterator();
        $started = hrtime(true);
        $iterator->rewind();
        self::assertSame(['id' => 1], $iterator->current());
        $statement = $query->PDOStatement;
        self::assertInstanceOf(PDOStatement::class, $statement);
        $consumerStarted = hrtime(true);
        usleep(60000);
        $consumerMs = (hrtime(true) - $consumerStarted) / 1e6;
        // A retained generator remains suspended after break; explicit release
        // is the existing PHP boundary that executes the iterator's finally.
        unset($iterator);
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        $spans = $this->databaseSpans();
        self::assertCount(1, $spans);
        self::assertLessThan($elapsedMs - $consumerMs + 25.0, $spans[0]['duration_ms']);
        self::assertNull($query->PDOStatement);
        self::assertSame('', $query->sql);
        self::assertFalse($statement->fetch(PDO::FETCH_ASSOC), 'The actual PDO cursor has been closed.');

        RequestLifecycleTrace::reset();
        $query = $this->query('SELECT id FROM trace_fixture');
        $iterator = $query->fetchIterator();
        $consumerFailure = new \RuntimeException('consumer fixture failure');
        try {
            foreach ($iterator as $row) {
                throw $consumerFailure;
            }
            self::fail('The consumer exception must escape.');
        } catch (\RuntimeException $caught) {
            self::assertSame($consumerFailure, $caught);
        } finally {
            unset($iterator);
        }
        self::assertCount(1, $this->databaseSpans());
        self::assertNull($query->PDOStatement);
        self::assertSame('', $query->sql);

        RequestLifecycleTrace::reset();
        $query = $this->query('SELECT nonexistent_column FROM trace_fixture');
        $this->pdo->beginTransaction();
        try {
            $caught = null;
            try {
                iterator_to_array($query->fetchIterator());
            } catch (\PDOException $failure) {
                $caught = $failure;
            }
            self::assertInstanceOf(\PDOException::class, $caught);
            self::assertSame($query->prepareFailure, $caught, 'Preserve the actual PDO exception object without wrapping it.');
            self::assertSame('HY000', $caught->errorInfo[0]);
            self::assertTrue($this->pdo->inTransaction(), 'The iterator does not own the outer transaction rollback.');
            self::assertCount(1, $this->databaseSpans());
            self::assertNull($query->PDOStatement);
            self::assertSame('', $query->sql);
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    private function databaseSpans(): array
    {
        return array_values(array_filter(RequestLifecycleTrace::getSpans(), static fn(array $span): bool => $span['category'] === 'db'));
    }

    private function query(string $sql, array $bindings = [], string $operation = 'select'): IteratorTraceSqliteQueryFixture
    {
        return new IteratorTraceSqliteQueryFixture($this->pdo, $sql, $bindings, $operation);
    }
}

final class IteratorTraceSqliteQueryFixture extends Query
{
    public array $preparedSql = [];
    public ?\Throwable $prepareFailure = null;

    public function __construct(private PDO $database, string $sql, array $bindings, string $operation)
    {
        parent::__construct();
        $this->db_name = '';
        $this->table = 'trace_fixture';
        $this->table_alias = 'main_table';
        $this->sql = $sql;
        $this->bound_values = $bindings;
        $this->fetch_type = $operation;
    }

    public function getLink(): PDO
    {
        return $this->database;
    }

    protected function preparePgsql(string $sql, array $options = []): PDOStatement|false
    {
        $this->preparedSql[] = $sql;
        try {
            return $this->database->prepare($sql, $options);
        } catch (\Throwable $failure) {
            $this->prepareFailure = $failure;
            throw $failure;
        }
    }
}
