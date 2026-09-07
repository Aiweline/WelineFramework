<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Repository;

use PDO;
use PDOStatement;
use stdClass;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Query;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Schema\Shard\ShardSchemaProvisionerInterface;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Service\ProductShardProvisioner;

final class PriceAndMediaRepositoryLargeReadTest extends TestCase
{
    public function testPriceIteratorRetainsAllExplicitRowsOrderingAndClearedScope(): void
    {
        $pdo = $this->database();
        $schema = '(offer_id INTEGER, store_id INTEGER, currency TEXT, amount_minor INTEGER, scope_state TEXT, cleared INTEGER, version INTEGER)';
        $pdo->exec('CREATE TABLE price_fixture_0 ' . $schema);
        $pdo->exec('CREATE TABLE price_fixture_7 ' . $schema);
        $insert = $pdo->prepare('INSERT INTO price_fixture_0 VALUES (?, ?, ?, ?, ?, ?, ?)');
        $pdo->beginTransaction();
        for ($offerId = 10001; $offerId >= 1; --$offerId) {
            $insert->execute([
                $offerId, $offerId % 2 ? 0 : 7, $offerId % 3 ? 'USD' : 'CNY',
                $offerId === 3 ? 0 : $offerId * 10,
                $offerId === 1 ? 'cleared' : 'explicit', $offerId === 2 ? 1 : 0, $offerId + 5,
            ]);
        }
        foreach ([
            [1, 0, 'GBP', 1012, 'explicit', 0, 0],
            [1, 0, 'EUR', 1011, 'explicit', 0, 2],
            [1, 7, 'USD', 1013, 'explicit', 0, 3],
            [20000, 0, 'USD', 9999, 'explicit', 0, 1],
            [1, 9, 'USD', 9999, 'explicit', 0, 1],
        ] as $row) {
            $insert->execute($row);
        }
        $pdo->exec("INSERT INTO price_fixture_7 VALUES (1, 0, 'USD', 777, 'explicit', 0, 7)");
        $pdo->commit();
        $ledger = $this->ledger();
        $repository = new PriceRepository($this->provisioner(), modelFactory: $this->modelFactory($pdo, $ledger, 'price'));
        $offerIds = array_merge(range(1, 10001), [1, '2', 0, -1]);
        $rows = $repository->listExplicitRows(0, $offerIds, [0, '0', 7, -1]);

        self::assertCount(10004, $rows);
        $expectedKeys = $pdo->query('SELECT store_id, offer_id, currency FROM price_fixture_0 WHERE offer_id <= 10001 AND store_id IN (0, 7) ORDER BY offer_id, store_id, currency')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame($expectedKeys, array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['offer_id', 'store_id', 'currency'])), $rows));
        self::assertSame(['EUR', 'GBP', 'USD', 'USD'], array_column(array_slice($rows, 0, 4), 'currency'));
        self::assertSame([0, 0, 0, 7], array_column(array_slice($rows, 0, 4), 'store_id'));
        self::assertSame(['amount_minor' => null, 'scope_state' => 'cleared', 'cleared' => true], array_intersect_key($rows[2], array_flip(['amount_minor', 'scope_state', 'cleared'])));
        self::assertSame(2, $rows[4]['offer_id']);
        self::assertNull($rows[4]['amount_minor']);
        self::assertSame('cleared', $rows[4]['scope_state']);
        self::assertTrue($rows[4]['cleared']);
        self::assertSame(0, $rows[5]['amount_minor']);
        self::assertFalse($rows[5]['cleared']);
        self::assertSame(0, $rows[1]['version']);
        self::assertSame(10001, $rows[10003]['offer_id']);
        self::assertSame([['store_id' => 0, 'offer_id' => 1, 'currency' => 'USD', 'amount_minor' => 777, 'scope_state' => 'explicit', 'cleared' => false, 'version' => 7]], $repository->listExplicitRows(7, [1], [0]));
        self::assertSame([], $repository->listExplicitRows(0, [0, -1], [0]));
        self::assertSame([], $repository->listExplicitRows(0, [1], [-1]));
        self::assertCount(2, $ledger->sql);
        self::assertSame([0, 7], $ledger->websites);
        $this->assertQueriesCleanedUp($ledger);
    }

    public function testMediaIteratorRetainsAllRowsOrderingAndLegacyStoreFallback(): void
    {
        $pdo = $this->database();
        $pdo->exec('CREATE TABLE media_fixture_0 (media_id INTEGER, product_id INTEGER, store_id INTEGER, position INTEGER, path TEXT)');
        $pdo->exec('CREATE TABLE media_fixture_7 (media_id INTEGER, product_id INTEGER, position INTEGER, path TEXT)');
        $insert = $pdo->prepare('INSERT INTO media_fixture_0 VALUES (?, ?, ?, ?, ?)');
        $legacyInsert = $pdo->prepare('INSERT INTO media_fixture_7 VALUES (?, ?, ?, ?)');
        $pdo->beginTransaction();
        for ($mediaId = 10001; $mediaId >= 1; --$mediaId) {
            $insert->execute([$mediaId, 83, $mediaId % 2 ? 0 : 7, $mediaId % 3, '/fixture/' . $mediaId . '.jpg']);
            $legacyInsert->execute([$mediaId, 83, $mediaId % 3, '/legacy/' . $mediaId . '.jpg']);
        }
        $insert->execute([20001, 84, 0, 0, '/fixture/other-product.jpg']);
        $insert->execute([20002, 99, 0, 0, '/fixture/excluded-product.jpg']);
        $insert->execute([20003, 83, 9, 0, '/fixture/other-store.jpg']);
        $legacyInsert->execute([20001, 99, 0, '/legacy/excluded-product.jpg']);
        $pdo->commit();
        $ledger = $this->ledger();
        $repository = new MediaRepository(
            $this->provisioner(),
            $this->createStub(ConnectionFactory::class),
            $this->createStub(DatabaseTransactionRunnerInterface::class),
            modelFactory: $this->modelFactory($pdo, $ledger, 'media'),
        );
        $rows = $repository->listByProductIds(0, [84, 83, 83, 0, -1], [7, 0, '0', -1]);
        self::assertCount(10002, $rows);
        $expected = $pdo->query('SELECT * FROM media_fixture_0 WHERE product_id IN (83, 84) AND store_id IN (0, 7) ORDER BY product_id, store_id, position, media_id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame($expected, $rows);
        self::assertSame('/fixture/other-product.jpg', $rows[10001]['path']);
        $allStores = $repository->listByProductIds(0, [83, 84]);
        self::assertCount(10003, $allStores);
        self::assertSame($pdo->query('SELECT * FROM media_fixture_0 WHERE product_id IN (83, 84) ORDER BY product_id, store_id, position, media_id')->fetchAll(PDO::FETCH_ASSOC), $allStores);
        self::assertSame([], $repository->listByProductIds(0, [83], [-1]));
        self::assertSame([], $repository->listByProductIds(0, [0, -1]));

        $beforeLegacy = count($ledger->sql);
        $legacy = $repository->listByProductIds(7, [83], [7]);
        self::assertCount(10001, $legacy);
        self::assertSame($pdo->query('SELECT * FROM media_fixture_7 WHERE product_id = 83 ORDER BY product_id, position, media_id')->fetchAll(PDO::FETCH_ASSOC), $legacy);
        self::assertCount(2, array_slice($ledger->sql, $beforeLegacy), 'The real missing store_id failure retries once without the optional store filter.');
        self::assertCount(1, $ledger->failures);
        self::assertInstanceOf(\PDOException::class, $ledger->failures[0]);
        self::assertStringContainsString('store_id', $ledger->failures[0]->getMessage());
        self::assertStringContainsString('store_id', $ledger->sql[$beforeLegacy]);
        self::assertStringNotContainsString('store_id', $ledger->sql[$beforeLegacy + 1]);
        // Verify completed streams; failed-query diagnostic state is outside
        // this repository change and remains owned by the database adapter.
        foreach ([$ledger->queries[0], $ledger->queries[1], $ledger->queries[array_key_last($ledger->queries)]] as $query) {
            self::assertNull($query->PDOStatement);
            self::assertSame('', $query->sql);
        }
    }

    private function database(): PDO
    {
        return new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function ledger(): stdClass
    {
        return (object)['sql' => [], 'bindings' => [], 'websites' => [], 'queries' => [], 'failures' => []];
    }

    private function provisioner(): ProductShardProvisioner
    {
        $registry = new class extends ProductShardRegistry {
            public function __construct() {}
            public function isReady(int $websiteId): bool { return in_array($websiteId, [0, 7], true); }
        };
        return new ProductShardProvisioner($registry, $this->createStub(ShardSchemaProvisionerInterface::class));
    }

    private function modelFactory(PDO $pdo, stdClass $ledger, string $kind): \Closure
    {
        return static function (int $websiteId) use ($pdo, $ledger, $kind): AbstractWebsiteShardModel {
            $ledger->websites[] = $websiteId;
            $table = $kind . '_fixture_' . $websiteId;
            $query = new class($pdo, $ledger, $table) extends Query {
                public function __construct(private PDO $database, private stdClass $ledger, string $table)
                {
                    parent::__construct();
                    $this->db_name = '';
                    $this->table = $table;
                    $this->table_alias = 'main_table';
                    $this->fields = 'main_table.*';
                }
                public function getLink(): PDO { return $this->database; }
                protected function preparePgsql(string $sql, array $options = []): PDOStatement|false
                {
                    // SQLite accepts an unknown unqualified double-quoted
                    // identifier as a string literal. Qualifying this generated
                    // column preserves PostgreSQL's actual missing-column error
                    // for legacy shards; SQL still runs on a real PDO statement.
                    $sql = preg_replace('/(?<!\\.)"store_id"/', '"main_table"."store_id"', $sql);
                    $this->ledger->sql[] = $sql;
                    $this->ledger->bindings[] = $this->bound_values;
                    try {
                        return $this->database->prepare($sql, $options);
                    } catch (\PDOException $failure) {
                        $this->ledger->failures[] = $failure;
                        throw $failure;
                    }
                }
            };
            $ledger->queries[] = $query;
            return new class($query, $table) extends AbstractWebsiteShardModel {
                public function __construct(private QueryInterface $fixtureQuery, private string $fixtureTable) {}
                public static function entityCode(): string { return 'secondary_read_fixture'; }
                public function getQuery(bool $keep_condition = true): QueryInterface { return $this->fixtureQuery->table($this->fixtureTable); }
                public function newQuery(bool $really_new = true): QueryInterface { return $this->getQuery(); }
            };
        };
    }

    private function assertQueriesCleanedUp(stdClass $ledger): void
    {
        foreach ($ledger->queries as $query) {
            self::assertNull($query->PDOStatement);
            self::assertSame('', $query->sql);
        }
    }
}
