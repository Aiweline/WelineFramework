<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Repository;

use PDO;
use PDOStatement;
use stdClass;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Query;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Schema\Shard\ShardSchemaProvisionerInterface;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\ProductShardProvisioner;

final class AttributeValueRepositoryLargeReadTest extends TestCase
{
    public function testOneProductOverTenThousandRowsRetainsEveryExplicitValueAndScope(): void
    {
        [$repository, $ledger] = $this->fixtureRepository();

        // Production repository, Model->__call, QueryDelegator, PostgreSQL query and fetch guard/iterator.
        // Only PDO preparation is directed at a private SQLite database; no result or fetch method is mocked.
        $rows = $repository->listExplicitRows(0, ' product ', [83, 83, 0, -1], [0, '0', -1]);
        self::assertCount(10001, $rows);
        self::assertSame(array_map(static fn(int $i): string => sprintf('a%05d', $i), range(0, 10000)), array_column($rows, 'attribute_code'));
        self::assertSame([83], array_values(array_unique(array_column($rows, 'entity_id'))));
        self::assertSame([0], array_values(array_unique(array_column($rows, 'store_id'))));
        self::assertSame(['product'], array_values(array_unique(array_column($rows, 'entity_type'))));
        self::assertSame(['', 'en_US'], array_values(array_unique(array_column($rows, 'locale'))));
        self::assertSame(9.5, $rows[0]['value']);
        self::assertTrue($rows[1]['value']);
        self::assertSame(['label' => '中文'], $rows[2]['value']);
        self::assertTrue($rows[3]['cleared']);
        self::assertNull($rows[3]['value']);
        self::assertTrue($rows[4]['cleared']);
        self::assertNull($rows[4]['value']);
        self::assertTrue($rows[5]['is_required']);
        self::assertSame('v10000', $rows[10000]['value']);
        $otherWebsite = $repository->listExplicitRows(7, 'product', [83], [0]);
        self::assertCount(1, $otherWebsite);
        self::assertSame('website seven', $otherWebsite[0]['value']);
        self::assertSame('zh_Hans_CN', $otherWebsite[0]['locale']);
        self::assertSame([0, 7], $ledger->websites);
        self::assertCount(2, $ledger->sql);
        $query = $ledger->queries[0];
        self::assertNull($query->PDOStatement);
        self::assertSame('', $query->sql);

        // The framework guard remains active for eager unbounded reads.
        $this->expectException(\Weline\Framework\Database\Exception\DbException::class);
        $this->expectExceptionMessage('Current threshold: 10000');
        $query->table('attribute_fixture_0')
            ->where('entity_type', 'product')
            ->where('entity_id', [83], 'IN')
            ->where('store_id', [0], 'IN')
            ->select()
            ->fetchArray();
    }

    public function testLocaleFilterAlwaysIncludesEmptyLocaleAndPushesSqlInClause(): void
    {
        [$repository, $ledger] = $this->fixtureRepository();

        $rows = $repository->listExplicitRows(0, 'product', [83], [0], ['en_US']);
        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            self::assertContains($row['locale'], ['', 'en_US'], 'locale filter must keep baseline + requested locales only');
        }
        self::assertContains('', array_column($rows, 'locale'));
        self::assertContains('en_US', array_column($rows, 'locale'));
        self::assertCount(10001, $rows, 'fixture alternates locale; en_US+\'\' covers every row');

        $sql = \implode("\n", $ledger->sql);
        $bindingsFlat = [];
        foreach ($ledger->bindings as $batch) {
            foreach ($batch as $value) {
                $bindingsFlat[] = $value;
            }
        }
        self::assertMatchesRegularExpression('/locale/i', $sql);
        self::assertContains('en_US', $bindingsFlat);
        self::assertContains('', $bindingsFlat);
    }

    public function testAttributeCodeFilterPushesSqlAndReturnsOnlyRequestedCodes(): void
    {
        [$repository, $ledger] = $this->fixtureRepository();

        $rows = $repository->listExplicitRows(
            0,
            'product',
            [83],
            [0],
            null,
            ['a00000', 'a00002', 'missing_code'],
        );
        self::assertCount(2, $rows);
        self::assertSame(['a00000', 'a00002'], array_column($rows, 'attribute_code'));

        $sql = \implode("\n", $ledger->sql);
        $bindingsFlat = [];
        foreach ($ledger->bindings as $batch) {
            foreach ($batch as $value) {
                $bindingsFlat[] = $value;
            }
        }
        self::assertMatchesRegularExpression('/attribute_code/i', $sql);
        self::assertContains('a00000', $bindingsFlat);
        self::assertContains('a00002', $bindingsFlat);
    }

    public function testEmptyAttributeCodesAfterNormalizeReturnsEmptyWithoutQuery(): void
    {
        [$repository, $ledger] = $this->fixtureRepository();

        $rows = $repository->listExplicitRows(0, 'product', [83], [0], null, ['', '  ']);
        self::assertSame([], $rows);
        self::assertSame([], $ledger->sql);
    }

    /**
     * @return array{0: AttributeValueRepository, 1: stdClass}
     */
    private function fixtureRepository(): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ledger = (object)['sql' => [], 'bindings' => [], 'websites' => [], 'queries' => []];
        $columns = '(entity_type TEXT, entity_id INTEGER, store_id INTEGER, attribute_code TEXT, locale TEXT, value_type TEXT, value_text TEXT, value_number REAL, value_boolean INTEGER, value_json TEXT, scope_state TEXT, cleared INTEGER, is_required INTEGER)';
        $pdo->exec('CREATE TABLE attribute_fixture_0 ' . $columns);
        $pdo->exec('CREATE TABLE attribute_fixture_7 ' . $columns);
        $insert = $pdo->prepare('INSERT INTO attribute_fixture_0 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $pdo->beginTransaction();
        for ($index = 10000; $index >= 0; $index--) {
            $insert->execute(['product', 83, 0, sprintf('a%05d', $index), $index % 2 ? 'en_US' : '',
                match ($index) { 0 => 'number', 1 => 'boolean', 2 => 'json', default => 'string' },
                'v' . $index, $index === 0 ? 9.5 : null, $index === 1 ? 1 : null,
                $index === 2 ? '{"label":"中文"}' : null,
                $index === 3 ? 'cleared' : 'explicit', $index === 4 ? 1 : 0, $index === 5 ? 1 : 0]);
        }
        foreach ([['offer', 83, 0], ['product', 84, 0], ['product', 83, 9]] as $excluded) {
            $insert->execute([...$excluded, 'excluded', 'en_US', 'string', 'excluded', null, null, null, 'explicit', 0, 0]);
        }
        $pdo->exec("INSERT INTO attribute_fixture_7 VALUES ('product',83,0,'website_7','zh_Hans_CN','string','website seven',NULL,NULL,NULL,'explicit',0,0)");
        $pdo->commit();

        $registry = new class extends ProductShardRegistry {
            public function __construct() {}
            public function isReady(int $websiteId): bool { return in_array($websiteId, [0, 7], true); }
        };
        $provisioner = new ProductShardProvisioner($registry, $this->createStub(ShardSchemaProvisionerInterface::class));
        $repository = new AttributeValueRepository($provisioner, modelFactory: function (int $websiteId) use ($pdo, $ledger): AbstractWebsiteShardModel {
            $ledger->websites[] = $websiteId;
            $query = new class($pdo, $ledger, $websiteId) extends Query {
                public function __construct(private PDO $database, private stdClass $ledger, int $websiteId)
                {
                    parent::__construct();
                    $this->db_name = '';
                    $this->table = 'attribute_fixture_' . $websiteId;
                    $this->table_alias = 'main_table';
                    $this->fields = 'main_table.*';
                }
                public function getLink(): PDO { return $this->database; }
                protected function preparePgsql(string $sql, array $options = []): PDOStatement|false
                {
                    $this->ledger->sql[] = $sql;
                    $this->ledger->bindings[] = $this->bound_values;
                    return $this->database->prepare($sql, $options);
                }
            };
            $ledger->queries[] = $query;
            return new class($query, $websiteId) extends AbstractWebsiteShardModel {
                public function __construct(private QueryInterface $fixtureQuery, private int $fixtureWebsite) {}
                public static function entityCode(): string { return 'attribute_value'; }
                public function getQuery(bool $keep_condition = true): QueryInterface { return $this->fixtureQuery->table('attribute_fixture_' . $this->fixtureWebsite); }
                public function newQuery(bool $really_new = true): QueryInterface { return $this->getQuery(); }
            };
        });

        return [$repository, $ledger];
    }
}
