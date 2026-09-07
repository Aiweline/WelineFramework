<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\Shard\ShardSchemaProvisionerInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\AttributeValue;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontCategoryTreeIndex;

/** Real repository reads against disposable SQLite; no storefront runtime or production writes. */
final class StorefrontCatalogReadEfficiencyTest extends TestCase
{
    private ConnectionFactory $connection;
    private ProductShardProvisioner $provisioner;
    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/weline_catalog_read_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite', 'database' => '', 'path' => $this->databasePath, 'persistent' => false,
        ]));
        $pdo = $this->connection->getConnector()->getWrappedConnection()->getPdo();
        $pdo->exec('CREATE TABLE product_shard_registry (registry_id INTEGER PRIMARY KEY, website_id INTEGER, status TEXT)');
        $pdo->exec("INSERT INTO product_shard_registry VALUES (1, 0, 'ready'), (2, 7, 'ready')");
        $registry = new ProductShardRegistry();
        $registry->setConnection($this->connection);
        $registry->__init();
        $this->provisioner = new ProductShardProvisioner(
            $registry,
            $this->createMock(ShardSchemaProvisionerInterface::class),
        );
    }

    protected function tearDown(): void
    {
        $this->connection->getConnector()->close();
        $this->connection->close();
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function testLocalizedTreeReadsAllPresentationAttributesOnce(): void
    {
        $pdo = $this->connection->getConnector()->getWrappedConnection()->getPdo();
        $pdo->exec('CREATE TABLE product_ws_0_attribute_value (value_id INTEGER PRIMARY KEY, store_id INTEGER, entity_type TEXT, entity_id INTEGER, attribute_code TEXT, locale TEXT, value_type TEXT, value_string TEXT, scope_state TEXT, cleared INTEGER, is_required INTEGER)');
        $insert = $pdo->prepare('INSERT INTO product_ws_0_attribute_value VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 0, 'category', 10, 'name', 'en_US', 'string', 'English category', 'explicit', 0, 1],
            [2, 0, 'category', 10, 'name', 'zh_Hans_CN', 'string', '中文分类', 'explicit', 0, 1],
            [3, 0, 'category', 10, 'image', '', 'string', '/shared.jpg', 'explicit', 0, 0],
            [4, 0, 'category', 10, 'banner', 'en_US', 'string', '/english.jpg', 'explicit', 0, 0],
            [5, 0, 'category', 10, 'summary', 'en_US', 'string', ' English summary ', 'explicit', 0, 0],
            [6, 0, 'category', 10, 'description', 'en_US', 'string', '<p>English</p>', 'explicit', 0, 0],
            [7, 0, 'category', 20, 'name', 'zh_Hans_CN', 'string', '仅中文', 'explicit', 0, 1],
            [8, 0, 'category', 20, 'image', '', 'string', '/cleared.jpg', 'cleared', 1, 0],
            [9, 0, 'category', 20, 'summary', '', 'string', 'Neutral summary', 'explicit', 0, 0],
            [10, 5, 'category', 10, 'name', 'en_US', 'string', 'Other store', 'explicit', 0, 1],
        ] as $row) {
            $insert->execute($row);
        }
        $reads = 0;
        $attributes = new AttributeValueRepository(
            $this->provisioner,
            new CatalogOverlayResolver(),
            function (int $websiteId) use (&$reads): AttributeValue {
                ++$reads;
                return $this->model(AttributeValue::class, $websiteId);
            },
        );
        $service = new ProductCategoryAttributeService($attributes);
        $urlCalls = [];
        $url = $this->createMock(Url::class);
        $url->method('getFrontendUrl')->willReturnCallback(static function (string $path) use (&$urlCalls): string {
            $urlCalls[] = $path;
            return '/en_US/' . $path;
        });
        $index = (new \ReflectionClass(StorefrontCategoryTreeIndex::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($index, 'categoryAttributes'))->setValue($index, $service);
        (new \ReflectionProperty($index, 'url'))->setValue($index, $url);
        $first = ['id' => 10, 'name' => 'Base category', 'path' => 'first'];
        $second = ['id' => 20, 'name' => 'Base second', 'path' => 'second'];
        $tree = ['by_id' => [10 => $first, 20 => $second], 'by_parent' => [0 => [$first, $second]], 'by_path' => ['first' => 10, 'second' => 20]];
        $result = (new \ReflectionMethod($index, 'applyLocalizedNames'))->invoke($index, 0, $tree, 'en_US');

        self::assertSame('English category', $result['by_id'][10]['name']);
        self::assertSame('/shared.jpg', $result['by_id'][10]['image']);
        self::assertSame('/english.jpg', $result['by_id'][10]['banner']);
        self::assertSame('English summary', $result['by_id'][10]['summary']);
        self::assertSame('<p>English</p>', $result['by_id'][10]['description']);
        self::assertSame('/en_US/category/first', $result['by_id'][10]['url']);
        self::assertSame('Base second', $result['by_id'][20]['name']);
        self::assertSame('', $result['by_id'][20]['image']);
        self::assertSame('Neutral summary', $result['by_id'][20]['summary']);
        self::assertSame($result['by_id'][10], $result['by_parent'][0][0]);
        self::assertSame($result['by_id'][20], $result['by_parent'][0][1]);
        self::assertCount(2, $urlCalls, 'Each category path should generate its storefront URL once per localized tree projection.');
        self::assertSame(1, $reads, 'One tree projection should fetch its category attribute rows once.');

        $empty = $service->readPresentationMaps(0, [0, -1], 'en_US');
        self::assertSame(['name' => [], 'image' => [], 'banner' => [], 'summary' => [], 'description' => []], $empty);
        self::assertSame(1, $reads);
        self::assertSame([10 => '中文分类', 20 => '仅中文'], $service->readNameMap(0, [10, 20], 'zh_Hans_CN'));
    }

    public function testCategoryTreeBuildDefersUrlGenerationToLocalizedProjection(): void
    {
        $pdo = $this->connection->getConnector()->getWrappedConnection()->getPdo();
        $pdo->exec('CREATE TABLE product_ws_0_category (category_id INTEGER PRIMARY KEY, global_category_uuid TEXT, parent_id INTEGER, path TEXT, position INTEGER, status TEXT)');
        $pdo->exec("INSERT INTO product_ws_0_category VALUES (10, 'uuid-10', 0, 'first', 0, 'active')");
        $categories = new \Weline\Product\Repository\CategoryRepository(
            $this->provisioner,
            fn(int $websiteId): Category => $this->model(Category::class, $websiteId),
        );
        $url = $this->createMock(Url::class);
        $url->expects(self::never())->method('getFrontendUrl');

        $index = (new \ReflectionClass(StorefrontCategoryTreeIndex::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($index, 'categories'))->setValue($index, $categories);
        (new \ReflectionProperty($index, 'url'))->setValue($index, $url);
        $result = (new \ReflectionMethod($index, 'build'))->invoke($index, 0);

        self::assertSame('', $result['by_id'][10]['url']);
    }

    public function testTargetedCatalogReadDoesNotMaterializeUnrequestedProducts(): void
    {
        $pdo = $this->connection->getConnector()->getWrappedConnection()->getPdo();
        $pdo->exec('CREATE TABLE product_read_source (product_id INTEGER PRIMARY KEY, status TEXT, sku TEXT)');
        $pdo->exec("INSERT INTO product_read_source VALUES (10, 'draft', 'TEN'), (20, 'draft', 'TWENTY'), (30, 'draft', 'THIRTY')");
        $readIds = [];
        $pdo->sqliteCreateFunction('catalog_read_status', static function (int $id, string $status) use (&$readIds): string {
            $readIds[] = $id;
            return $status;
        }, 2);
        $pdo->exec('CREATE VIEW product_ws_0_product AS SELECT product_id, catalog_read_status(product_id, status) AS status, sku FROM product_read_source');
        $products = new ProductRepository($this->provisioner, fn(int $websiteId): Product => $this->model(Product::class, $websiteId));
        $catalog = (new \ReflectionClass(StorefrontCatalogViewService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($catalog, 'products'))->setValue($catalog, $products);
        $rows = (new \ReflectionMethod($catalog, 'buildPublishedOffers'))->invoke($catalog, 0, ScopeIdentity::website(0, 'default'), [20]);

        self::assertSame([], $rows, 'Draft products remain excluded from storefront offers.');
        self::assertSame([20], $readIds, 'A product-specific refresh must not fetch unrelated product rows.');

        $readIds = [];
        self::assertSame([10, 30], array_column($products->listByIds(0, [30, -1, 10, 30, 0]), 'product_id'));
        self::assertSame([10, 30], $readIds);
        $readIds = [];
        self::assertSame([], $products->listByIds(0, [0, -1]));
        self::assertSame([], $readIds);
        $pdo->exec('CREATE TABLE product_ws_7_product (product_id INTEGER PRIMARY KEY, status TEXT, sku TEXT)');
        $pdo->exec("INSERT INTO product_ws_7_product VALUES (20, 'draft', 'OTHER-WEBSITE')");
        self::assertSame('OTHER-WEBSITE', $products->listByIds(7, [20])[0]['sku']);
    }

    /** @param class-string<AbstractWebsiteShardModel> $class */
    private function model(string $class, int $websiteId): AbstractWebsiteShardModel
    {
        $model = new $class();
        $model->setConnection($this->connection);
        $model->__init();
        return $model->forWebsite($websiteId);
    }
}
