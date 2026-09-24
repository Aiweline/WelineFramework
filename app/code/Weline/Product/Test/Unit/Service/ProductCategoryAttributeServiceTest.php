<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Model\CategoryAttributeEntity;
use Weline\Product\Service\ProductCategoryAttributeService;

final class ProductCategoryAttributeServiceTest extends TestCase
{
    public function testEntityTypeMatchesCategoryEavEntity(): void
    {
        self::assertSame(CategoryAttributeEntity::entity_code, ProductCategoryAttributeService::ENTITY_TYPE);
    }

    public function testServiceRoutesWritesThroughAttributeRepository(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCategoryAttributeService.php',
        );

        self::assertStringContainsString('$this->attributes->writeExplicit', $source);
        self::assertStringContainsString("'name'", $source);
        self::assertStringContainsString("'code'", $source);
        self::assertStringContainsString('purgeEntity', $source);
        self::assertStringContainsString('copyExplicitAttributes', $source);
        self::assertStringContainsString('pickLocalizedAttributeValue', $source);
    }

    public function testPickLocalizedAttributeValuePrefersCurrentLocaleOnly(): void
    {
        self::assertSame(
            '小说',
            ProductCategoryAttributeService::pickLocalizedAttributeValue(
                ['en_US' => 'Fiction', 'zh_Hans_CN' => '小说'],
                'zh_Hans_CN',
            ),
        );
        self::assertSame(
            'Fiction',
            ProductCategoryAttributeService::pickLocalizedAttributeValue(
                ['en_US' => 'Fiction', 'zh_Hans_CN' => '小说'],
                'en_US',
            ),
        );
    }

    public function testPickLocalizedAttributeValueDoesNotFallbackToOtherLanguages(): void
    {
        self::assertSame(
            '',
            ProductCategoryAttributeService::pickLocalizedAttributeValue(
                ['en_US' => 'Fiction'],
                'zh_Hans_CN',
            ),
        );
    }

    public function testPickLocalizedAttributeValueUsesLanguageNeutralDefault(): void
    {
        self::assertSame(
            '通用名称',
            ProductCategoryAttributeService::pickLocalizedAttributeValue(
                ['' => '通用名称', 'en_US' => 'Fiction'],
                'zh_Hans_CN',
            ),
        );
    }

    public function testAdminPresentationReadsAllSevenFieldsOnceWithoutChangingLocaleFallbacks(): void
    {
        [$admin, $attributes, $ledger, $pdo] = $this->presentationFixture();
        $read = new \ReflectionMethod($admin, 'enrichedRows');
        $rows = $read->invoke($admin, 0, 'en_US');
        self::assertSame([
            'name' => 'English name',
            'google_taxonomy_id' => '123',
            'image' => '/neutral.jpg',
            'banner' => '',
            'summary' => '',
            'description' => 'English description',
            'source_platform' => 'marketplace',
        ], array_intersect_key($rows[0], array_flip([
            'name', 'google_taxonomy_id', 'image', 'banner', 'summary', 'description', 'source_platform',
        ])));
        self::assertSame('second category', $rows[1]['name']);
        self::assertSame(1, $ledger->attributeReads, 'All seven maps must share one repository read.');

        // Admin writes followed by a read retain immediate visibility: batching must not add a stale memo.
        $pdo->exec("UPDATE category_attributes SET value_text = 'Updated name' WHERE attribute_code = 'name' AND locale = 'en_US'");
        $updated = $read->invoke($admin, 0, 'en_US');
        self::assertSame('Updated name', $updated[0]['name']);
        self::assertSame(2, $ledger->attributeReads);
        $localized = $read->invoke($admin, 0, 'zh_Hans_CN');
        self::assertSame('中文名称', $localized[0]['name']);
        self::assertSame('', $localized[0]['description']);
        self::assertSame('/neutral.jpg', $localized[0]['image']);
        self::assertSame(3, $ledger->attributeReads);
    }

    public function testPresentationBatchKeepsDefaultShapeAndAcceptsAdditionalFields(): void
    {
        [, $attributes, $ledger] = $this->presentationFixture();
        self::assertSame(
            ['name' => [], 'image' => [], 'banner' => [], 'summary' => [], 'description' => []],
            $attributes->readPresentationMaps(0, [], 'en_US'),
        );
        self::assertSame(0, $ledger->attributeReads);
        $defaults = $attributes->readPresentationMaps(0, [1], 'en_US');
        self::assertSame(['name', 'image', 'banner', 'summary', 'description'], array_keys($defaults));
        $extended = $attributes->readPresentationMaps(
            0, [1, 1, -1, 0], 'en_US', ['google_taxonomy_id', 'source_platform', 'name'],
        );
        self::assertSame('123', $extended['google_taxonomy_id'][1] ?? null);
        self::assertSame('marketplace', $extended['source_platform'][1] ?? null);
        self::assertSame($defaults['name'], $extended['name']);
        self::assertCount(7, $extended);
        self::assertSame(2, $ledger->attributeReads);
    }

    public function testDedupeBatchesChildNamesAndPreservesMoveDeleteOrder(): void
    {
        [$admin, , $ledger, $pdo] = $this->presentationFixture();
        // 隔离写边界：原服务只移除 final 并改测试类名，所有读取与去重代码保持原样。
        $source = file_get_contents(dirname(__DIR__, 3) . '/Service/ProductCategoryAdminService.php');
        $source = str_replace('final class ProductCategoryAdminService', 'class CategoryDedupeReadHarness', $source);
        if (!class_exists('Weline\\Product\\Service\\CategoryDedupeReadHarness', false)) {
            eval(substr($source, strpos($source, 'declare(')));
        }
        $probe = new class extends \Weline\Product\Service\CategoryDedupeReadHarness {
            public array $writes = [];
            public function __construct() {}
            public function save(int $websiteId, int $categoryId, int $parentId, string $name, string $status,
                string $code = '', string $locale = '', ?string $googleTaxonomyId = null, ?string $image = null,
                ?string $banner = null, ?string $summary = null, ?string $description = null): array {
                $this->writes[] = ['save', $categoryId, $parentId, $name, $code];
                return [];
            }
            public function delete(int $websiteId, int $categoryId, array $selectedProductIds = []): void {
                $this->writes[] = ['delete', $categoryId];
            }
        };
        foreach (['categories', 'categoryAttributes'] as $field) {
            $value = (new \ReflectionProperty($admin, $field))->getValue($admin);
            (new \ReflectionProperty('Weline\\Product\\Service\\CategoryDedupeReadHarness', $field))->setValue($probe, $value);
        }
        $pdo->exec("UPDATE category_attributes SET value_text = 'Same' WHERE attribute_code = 'name'");
        $pdo->exec("INSERT INTO category_attributes VALUES ('category',2,0,'name','en_US','string','Same','explicit',0,0)");
        $pdo->exec("INSERT INTO category_rows VALUES (3,2,'/child-first',1,'active'),(4,2,'/fallback-child',2,'active'),(5,1,'/keep-one',1,'active'),(6,1,'/keep-two',2,'active'),(7,1,'/keep-three',3,'active'),(8,0,'/empty-duplicate',3,'active')");
        $pdo->exec("INSERT INTO category_attributes VALUES ('category',3,0,'name','en_US','string','Child name','explicit',0,0),('category',8,0,'name','en_US','string','Same','explicit',0,0)");
        self::assertSame([2, 8], $probe->dedupeSiblingsByLocalizedName(0, 0, 'Same', 'en_US'));
        self::assertSame([
            ['save', 3, 1, 'Child name', 'child-first'],
            ['save', 4, 1, 'fallback child', 'fallback-child'],
            ['delete', 2], ['delete', 8],
        ], $probe->writes);
        self::assertSame(2, $ledger->attributeReads, 'One sibling-name read plus one children batch; empty children do not query.');
        self::assertSame([1, 1, 1, 2, 8, 3], $ledger->fetchedEntities);
    }

    public function testPresentationOnlyFetchesRequestedAttributeCodes(): void
    {
        [, $attributes, $ledger] = $this->presentationFixture();
        $maps = $attributes->readPresentationMaps(0, [1], 'en_US');
        self::assertSame('English name', $maps['name'][1]);
        self::assertSame(1, $ledger->attributeReads);
        self::assertSame(['banner', 'description', 'image', 'name', 'name', 'name', 'summary'], $ledger->fetchedCodes);
    }

    /** Real repositories and query execution; only connection and shard readiness use a private fixture. */
    private function presentationFixture(): array
    {
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE category_rows (category_id INTEGER, parent_id INTEGER, path TEXT, position INTEGER, status TEXT)');
        $pdo->exec("INSERT INTO category_rows VALUES (1,0,'/first-category',1,'active'),(2,0,'/second-category',2,'active')");
        $pdo->exec('CREATE TABLE category_attributes (entity_type TEXT, entity_id INTEGER, store_id INTEGER, attribute_code TEXT, locale TEXT, value_type TEXT, value_text TEXT, scope_state TEXT, cleared INTEGER, is_required INTEGER)');
        $insert = $pdo->prepare('INSERT INTO category_attributes VALUES (?,1,0,?,?,?, ?,?, ?,0)');
        foreach ([
            ['name', '', 'Neutral name', 0],
            ['name', 'en_US', 'English name', 0],
            ['name', 'zh_Hans_CN', '中文名称', 0],
            ['google_taxonomy_id', '', '123', 0],
            ['image', '', '/neutral.jpg', 0],
            ['banner', 'en_US', '/cleared.jpg', 1],
            ['summary', 'en_US', '  ', 0],
            ['description', 'en_US', 'English description', 0],
            ['source_platform', '', 'marketplace', 0],
        ] as [$code, $locale, $value, $cleared]) {
            $insert->execute([ProductCategoryAttributeService::ENTITY_TYPE, $code, $locale, 'string', $value, $cleared ? 'cleared' : 'explicit', $cleared]);
        }
        $ledger = (object)['attributeReads' => 0, 'fetchedCodes' => [], 'fetchedEntities' => []];
        $registry = new class extends \Weline\Product\Model\ProductShardRegistry {
            public function __construct() {}
            public function isReady(int $websiteId): bool { return true; }
        };
        $provisioner = new \Weline\Product\Service\ProductShardProvisioner(
            $registry, $this->createStub(\Weline\Framework\Database\Schema\Shard\ShardSchemaProvisionerInterface::class),
        );
        $factory = static function (string $table) use ($pdo, $ledger): \Closure {
            return static function (int $websiteId) use ($pdo, $ledger, $table): \Weline\Product\Model\Shard\AbstractWebsiteShardModel {
                $query = new class($pdo, $ledger, $table) extends \Weline\Framework\Database\Connection\Adapter\Pgsql\Query {
                    public function __construct(private \PDO $database, private \stdClass $ledger, string $table)
                    {
                        parent::__construct();
                        $this->db_name = '';
                        $this->table = $table;
                        $this->table_alias = 'main_table';
                        $this->fields = 'main_table.*';
                    }
                    public function getLink(): \PDO { return $this->database; }
                    public function fetchIterator(string $model_class = '', int $batchSize = 1): \Generator
                    {
                        foreach (parent::fetchIterator($model_class, $batchSize) as $key => $row) {
                            if (is_array($row) && isset($row['attribute_code'])) {
                                $this->ledger->fetchedCodes[] = $row['attribute_code'];
                                $this->ledger->fetchedEntities[] = (int)$row['entity_id'];
                            }
                            yield $key => $row;
                        }
                    }
                    protected function preparePgsql(string $sql, array $options = []): \PDOStatement|false
                    {
                        if (str_contains($sql, 'category_attributes')) {
                            $this->ledger->attributeReads++;
                        }
                        return $this->database->prepare($sql, $options);
                    }
                };
                return new class($query, $table) extends \Weline\Product\Model\Shard\AbstractWebsiteShardModel {
                    public function __construct(private \Weline\Framework\Database\Connection\Api\Sql\QueryInterface $fixtureQuery, private string $fixtureTable) {}
                    public static function entityCode(): string { return 'category'; }
                    public function getQuery(bool $keep_condition = true): \Weline\Framework\Database\Connection\Api\Sql\QueryInterface { return $this->fixtureQuery->table($this->fixtureTable); }
                    public function newQuery(bool $really_new = true): \Weline\Framework\Database\Connection\Api\Sql\QueryInterface { return $this->getQuery(); }
                };
            };
        };
        $attributes = new ProductCategoryAttributeService(
            new \Weline\Product\Repository\AttributeValueRepository($provisioner, modelFactory: $factory('category_attributes')),
        );
        $class = new \ReflectionClass(\Weline\Product\Service\ProductCategoryAdminService::class);
        $admin = $class->newInstanceWithoutConstructor();
        $class->getProperty('categories')->setValue(
            $admin, new \Weline\Product\Repository\CategoryRepository($provisioner, modelFactory: $factory('category_rows')),
        );
        $class->getProperty('categoryAttributes')->setValue($admin, $attributes);
        return [$admin, $attributes, $ledger, $pdo];
    }
}
