<?php

declare(strict_types=1);

namespace Weline\UrlManager\Test\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Query;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\UrlManager\Model\UrlRewrite;

final class UrlRewriteLookupTest extends TestCase
{
    private PDO $database;
    private \stdClass $ledger;
    private UrlRewrite $model;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('CREATE TABLE url_rewrite_fixture (rewrite_id INTEGER PRIMARY KEY, website_id INTEGER NOT NULL, path TEXT NOT NULL, path_fingerprint TEXT, rewrite TEXT NOT NULL)');
        $this->ledger = (object)['queries' => []];
        $this->model = new class($this->database, $this->ledger) extends UrlRewrite {
            public function __construct(private PDO $database, private \stdClass $ledger)
            {
            }

            public function newQuery(bool $really_new = true): QueryInterface
            {
                return new class($this->database, $this->ledger) extends Query {
                    public function __construct(private PDO $database, private \stdClass $ledger)
                    {
                        $this->table = 'url_rewrite_fixture';
                        $this->table_alias = 'main_table';
                        $this->fields = 'main_table.*';
                        $this->identity_field = 'rewrite_id';
                        // A carried index hint must not rearrange the new OR predicate.
                        $this->_index_sort_keys = ['website_id', 'path_fingerprint'];
                    }

                    public function getLink(): PDO
                    {
                        return $this->database;
                    }

                    public function fetchArray(): array
                    {
                        // Keep the production PostgreSQL builder/compiler; replace only execution.
                        $statement = $this->database->prepare($this->sql);
                        $statement->execute($this->bound_values);
                        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                        $this->ledger->queries[] = ['sql' => $this->sql, 'bindings' => $this->bound_values, 'rows' => $rows];
                        return $rows;
                    }
                };
            }
        };
    }

    public function testMissUsesOneBoundQueryAndIsolatesBothWebsiteBranches(): void
    {
        $path = "/missing' OR 1=1 -- ";
        foreach ([UrlRewrite::pathFingerprint($path), null, ''] as $index => $fingerprint) {
            $this->insertRow(100 + $index, 8, $path, $fingerprint);
        }
        $this->insertRow(1, 7, '/another-path', null);

        self::assertNull($this->model->findLatestByWebsiteAndPath(7, $path));
        foreach ($this->ledger->queries as $query) {
            self::assertStringNotContainsString($path, $query['sql']);
            foreach ($query['rows'] as $row) {
                self::assertSame(7, (int)$row['website_id']);
            }
        }
        self::assertStringNotContainsString(UrlRewrite::pathFingerprint($path), $this->ledger->queries[0]['sql']);
        self::assertContains(UrlRewrite::pathFingerprint($path), array_values($this->ledger->queries[0]['bindings']));
        self::assertCount(1, $this->ledger->queries);
    }

    public function testFingerprintThenNullThenEmptyPriorityKeepsNewestRowWithinEachTier(): void
    {
        $path = '/priority';
        $fingerprint = UrlRewrite::pathFingerprint($path);
        foreach ([[10, $fingerprint], [20, $fingerprint], [30, null], [40, null], [50, ''], [60, '']] as [$id, $stored]) {
            $this->insertRow($id, 7, $path, $stored);
        }
        foreach ([$fingerprint, null, ''] as $index => $stored) {
            $this->insertRow(900 + $index, 8, $path, $stored);
        }

        self::assertSame(20, $this->model->findLatestByWebsiteAndPath(7, $path)['rewrite_id']);
        $this->database->exec('DELETE FROM url_rewrite_fixture WHERE rewrite_id IN (10, 20)');
        self::assertSame(40, $this->model->findLatestByWebsiteAndPath(7, $path)['rewrite_id']);
        $this->database->exec('DELETE FROM url_rewrite_fixture WHERE rewrite_id IN (30, 40)');
        self::assertSame(60, $this->model->findLatestByWebsiteAndPath(7, $path)['rewrite_id']);
        foreach ($this->ledger->queries as $query) {
            foreach ($query['rows'] as $row) {
                self::assertSame(7, (int)$row['website_id']);
            }
        }
        self::assertCount(3, $this->ledger->queries);
    }

    public function testRawPathBytesAndNonEmptyFingerprintMismatchRemainStrict(): void
    {
        $this->insertRow(10, 7, '/Case', UrlRewrite::pathFingerprint('/Case'));
        $this->insertRow(20, 7, '/case', null);
        $this->insertRow(30, 7, '/Case ', '');
        $this->insertRow(40, 7, "/quo'te\n", null);
        $this->insertRow(50, 7, '/broken', UrlRewrite::pathFingerprint('/another'));
        // These higher rows pass the digest index lookup but fail the raw byte comparison.
        $this->insertRow(90, 7, '/case', UrlRewrite::pathFingerprint('/Case'));
        $this->insertRow(91, 7, '/Case ', UrlRewrite::pathFingerprint('/Case'));

        foreach (['/Case' => 10, '/case' => 20, '/Case ' => 30, "/quo'te\n" => 40, '/CASE' => null, '/broken' => null] as $path => $id) {
            $row = $this->model->findLatestByWebsiteAndPath(7, $path);
            self::assertSame($id, $row['rewrite_id'] ?? null, 'Raw path: ' . json_encode($path));
        }
        self::assertCount(6, $this->ledger->queries);
    }

    public function testSameModelSeesInsertUpdateAndDeleteAfterPriorReads(): void
    {
        $path = '/future';
        self::assertNull($this->model->findLatestByWebsiteAndPath(7, $path));
        $this->insertRow(100, 7, $path, null);
        self::assertSame(100, $this->model->findLatestByWebsiteAndPath(7, $path)['rewrite_id']);
        $this->database->exec("UPDATE url_rewrite_fixture SET path = '/moved' WHERE rewrite_id = 100");
        self::assertNull($this->model->findLatestByWebsiteAndPath(7, $path));
        $this->insertRow(200, 7, $path, UrlRewrite::pathFingerprint($path));
        self::assertSame(200, $this->model->findLatestByWebsiteAndPath(7, $path)['rewrite_id']);
        $this->database->exec('DELETE FROM url_rewrite_fixture WHERE rewrite_id = 200');
        self::assertNull($this->model->findLatestByWebsiteAndPath(7, $path));
        self::assertCount(5, $this->ledger->queries);
    }

    private function insertRow(int $id, int $website, string $path, ?string $fingerprint): void
    {
        $statement = $this->database->prepare('INSERT INTO url_rewrite_fixture VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$id, $website, $path, $fingerprint, '/target-' . $id]);
    }
}
