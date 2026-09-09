<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
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
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Price;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Service\StorefrontProductDetailProjector;
use Weline\Product\Service\StorefrontProductMediaUrlResolver;
use Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProvider\ProductCatalogCartItemSnapshotResolver;
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

    public function testSummaryReusesAnAlreadyBuiltListingInTheSameRequest(): void
    {
        $previous = Context::getCurrent();
        Context::enter(new Context());
        try {
            \Weline\Framework\Runtime\RequestContext::set('product.catalog.full_rows.request', [
                ['product_id' => 25, 'name' => 'Already resolved'], ['product_id' => 26, 'name' => 'Second'],
            ]);
            $catalog = (new \ReflectionClass(StorefrontCatalogViewService::class))->newInstanceWithoutConstructor();
            try {
                self::assertSame([['product_id' => 25, 'name' => 'Already resolved']], $catalog->publishedOfferSummaries(1));
            } catch (\Throwable $error) {
                self::fail('An existing request projection must not need another catalog dependency: ' . $error->getMessage());
            }
        } finally {
            Context::leave();
            if ($previous !== null) { Context::enter($previous); }
        }
    }

    public function testRepresentativeCursorDoesNotRepeatLaterVariantsOfPreviousProducts(): void
    {
        $pdo = $this->connection->getConnector()->getWrappedConnection()->getPdo();
        $pdo->exec('CREATE TABLE product_ws_0_offer (offer_id INTEGER PRIMARY KEY, product_id INTEGER, status TEXT)');
        $pdo->exec("INSERT INTO product_ws_0_offer VALUES (10,1,'published'),(20,1,'published'),(30,2,'published'),(40,2,'published'),(50,3,'published'),(60,4,'draft')");
        $offers = new OfferRepository($this->provisioner, fn(int $id): Offer => $this->model(Offer::class, $id));
        self::assertSame([10, 30], array_column($offers->listPublishedRepresentativePage(0, 2), 'offer_id'));
        self::assertSame([50], array_column($offers->listPublishedRepresentativePage(0, 2, 30), 'offer_id'));
        self::assertSame([], $offers->listPublishedRepresentativePage(0, 2, 50));
    }

    public function testBoundedSummarySkipsDraftCandidatePagesAndDoesNotReadTheWholeWebsite(): void
    {
        $pdo = $this->connection->getConnector()->getWrappedConnection()->getPdo();
        $pdo->exec('CREATE TABLE product_read_source (product_id INTEGER PRIMARY KEY, status TEXT, sku TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE product_ws_0_offer (offer_id INTEGER PRIMARY KEY, product_id INTEGER, global_offer_uuid TEXT, status TEXT, sku TEXT)');
        $insertProduct = $pdo->prepare('INSERT INTO product_read_source (product_id,status,sku) VALUES (?, ?, ?)');
        $insertOffer = $pdo->prepare('INSERT INTO product_ws_0_offer VALUES (?, ?, ?, ?, ?)');
        for ($id = 1; $id <= 100; ++$id) {
            $insertProduct->execute([$id, in_array($id, [25, 26], true) ? 'published' : 'draft', 'SKU-' . $id]);
            $insertOffer->execute([$id, $id, '00000000-0000-4000-8000-' . sprintf('%012d', $id), 'published', 'SKU-' . $id]);
        }
        $readIds = [];
        $pdo->sqliteCreateFunction('catalog_read_status', static function (int $id, string $status) use (&$readIds): string {
            $readIds[] = $id;
            return $status;
        }, 2);
        $pdo->exec('CREATE VIEW product_ws_0_product AS SELECT product_id, catalog_read_status(product_id,status) AS status, sku, created_at FROM product_read_source');
        $pdo->exec('CREATE TABLE product_ws_0_price (price_id INTEGER PRIMARY KEY, offer_id INTEGER, store_id INTEGER, currency TEXT, amount_minor INTEGER, scope_state TEXT, cleared INTEGER, version INTEGER)');
        $pdo->exec('CREATE TABLE product_ws_0_media (media_id INTEGER PRIMARY KEY, product_id INTEGER, store_id INTEGER, position INTEGER, path TEXT)');
        $products = new ProductRepository($this->provisioner, fn(int $id): Product => $this->model(Product::class, $id));
        $offers = new OfferRepository($this->provisioner, fn(int $id): Offer => $this->model(Offer::class, $id));
        $attributes = new AttributeValueRepository($this->provisioner, new CatalogOverlayResolver());
        $prices = new PriceRepository($this->provisioner, modelFactory: fn(int $id): Price => $this->model(Price::class, $id));
        $media = new MediaRepository($this->provisioner, $this->connection,
            $this->createStub(\Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface::class),
            fn(int $id): Media => $this->model(Media::class, $id));
        $mediaUrls = new StorefrontProductMediaUrlResolver($this->createStub(\Weline\FileManager\Api\FileAssetManagerInterface::class));
        $snapshots = new ProductCatalogCartItemSnapshotResolver($offers, $products, $attributes, $prices, $media,
            new StoreOfferRepository($this->provisioner),
            $this->createStub(\Weline\Websites\Api\Catalog\StoreCatalogInterface::class),
            static fn(): string => 'USD', static fn(): string => 'en_US', static fn(): array => [], mediaUrls: $mediaUrls);
        $catalog = (new \ReflectionClass(StorefrontCatalogViewService::class))->newInstanceWithoutConstructor();
        foreach (['products' => $products, 'offers' => $offers, 'snapshots' => $snapshots,
            'detailProjector' => (new \ReflectionClass(StorefrontProductDetailProjector::class))->newInstanceWithoutConstructor(),
            'mediaUrls' => $mediaUrls] as $property => $value) {
            (new \ReflectionProperty($catalog, $property))->setValue($catalog, $value);
        }
        $rows = (new \ReflectionMethod($catalog, 'buildPublishedOffers'))->invoke(
            $catalog, 0, ScopeIdentity::website(0, 'default'), [], true, false, 2,
        );
        self::assertSame([25, 26], array_column($rows, 'product_id'), 'Unavailable early candidates must not hide later published products.');
        self::assertLessThanOrEqual(48, count($readIds), 'A two-card summary must not fetch all 100 product rows.');

        // The newest 48 products have no published offer. Older products inside
        // the day window must still be returned as dated new-arrival cards.
        $pdo->exec("UPDATE product_read_source SET status='published'");
        $pdo->exec('DELETE FROM product_ws_0_offer WHERE product_id NOT IN (25,26)');
        $manager = $this->getMockBuilder(CacheManager::class)->disableOriginalConstructor()->onlyMethods(['pool'])->getMock();
        $manager->method('pool')->willReturn(new CachePool('new_arrival_fixture', new CatalogReadMemoryAdapter(), jitterRatio: 0.0));
        $flight = $this->createStub(SingleFlightInterface::class);
        $flight->method('acquire')->willReturn(null);
        $cache = new StorefrontScopeHotCache($manager, null, $flight);
        (new \ReflectionProperty($catalog, 'hotCache'))->setValue($catalog, $cache);
        (new \ReflectionProperty($catalog, 'catalogCache'))->setValue($catalog,
            (new \ReflectionClass(\Weline\Product\Service\StorefrontCatalogCacheCoordinator::class))->newInstanceWithoutConstructor());
        $previous = Context::getCurrent();
        Context::enter(new Context());
        \Weline\Framework\Manager\ObjectManager::setInstance(StorefrontScopeHotCache::class, $cache);
        try {
            $widget = new \Weline\Product\Service\StorefrontProductWidgetCatalog($catalog, $products);
            $cards = $widget->newArrivalCards(2, 30);
            self::assertSame([26, 25], array_column($cards, 'product_id'));
            self::assertArrayHasKey('created_at', $cards[0], 'Continue the new-arrival candidates instead of falling back to an undated ordinary recommendation.');
        } finally {
            Context::leave();
            if ($previous !== null) { Context::enter($previous); }
            \Weline\Framework\Manager\ObjectManager::clearInstances();
            StorefrontScopeHotCache::resetProcessCache();
        }
    }

    public function testRecentCandidateQueryReadsOnlyIdsAndDatesAndSupportsTheNextPage(): void
    {
        $pdo = $this->connection->getConnector()->getWrappedConnection()->getPdo();
        $pdo->exec('CREATE TABLE product_read_source (product_id INTEGER PRIMARY KEY, status TEXT, created_at TEXT, sku TEXT)');
        $pdo->exec("INSERT INTO product_read_source VALUES (1,'published','2026-09-08 10:00:00','one'),(2,'published','2026-09-08 09:00:00','two'),(3,'published','2026-09-07 09:00:00','three'),(4,'draft','2026-09-08 11:00:00','draft')");
        $payloadReads = 0;
        $pdo->sqliteCreateFunction('candidate_payload', static function (string $sku) use (&$payloadReads): string {
            ++$payloadReads;
            return $sku;
        }, 1);
        $pdo->exec('CREATE VIEW product_ws_0_product AS SELECT product_id,status,created_at,candidate_payload(sku) AS sku FROM product_read_source');
        $products = new ProductRepository($this->provisioner, fn(int $id): Product => $this->model(Product::class, $id));
        self::assertSame([1 => '2026-09-08 10:00:00'], $products->listRecentPublishedCreatedAt(0, '2026-09-08 00:00:00', 1));
        self::assertSame([2 => '2026-09-08 09:00:00'], $products->listRecentPublishedCreatedAt(0, '2026-09-08 00:00:00', 1, 1));
        self::assertSame(0, $payloadReads, 'Candidate discovery must not materialize discarded product payload columns.');
    }

    public function testExplicitLocalesShareOnlyTheirOwnPresentationAndHonorCatalogGeneration(): void
    {
        $pdo = $this->connection->getConnector()->getWrappedConnection()->getPdo();
        $pdo->exec('CREATE TABLE product_ws_0_category (category_id INTEGER PRIMARY KEY, global_category_uuid TEXT, parent_id INTEGER, path TEXT, position INTEGER, status TEXT)');
        $pdo->exec("INSERT INTO product_ws_0_category VALUES (10, 'uuid-10', 0, 'first', 0, 'active')");
        $pdo->exec('CREATE TABLE product_ws_0_attribute_value (value_id INTEGER PRIMARY KEY, store_id INTEGER, entity_type TEXT, entity_id INTEGER, attribute_code TEXT, locale TEXT, value_type TEXT, value_string TEXT, scope_state TEXT, cleared INTEGER, is_required INTEGER)');
        $pdo->exec("INSERT INTO product_ws_0_attribute_value VALUES (1, 0, 'category', 10, 'name', 'en_US', 'string', 'English', 'explicit', 0, 1), (2, 0, 'category', 10, 'name', 'zh_Hans_CN', 'string', '中文', 'explicit', 0, 1)");
        $reads = 0;
        $attributes = new AttributeValueRepository($this->provisioner, new CatalogOverlayResolver(),
            function (int $websiteId) use (&$reads): AttributeValue {
                ++$reads;
                return $this->model(AttributeValue::class, $websiteId);
            });
        $manager = $this->getMockBuilder(CacheManager::class)->disableOriginalConstructor()->onlyMethods(['pool'])->getMock();
        $manager->method('pool')->willReturn(new CachePool('category_locale_fixture', new CatalogReadMemoryAdapter(), jitterRatio: 0.0));
        $revision = 1;
        $generations = $this->createStub(NamespaceGenerationInterface::class);
        $generations->method('fingerprint')->willReturnCallback(static function () use (&$revision): string {
            return hash('sha256', 'catalog:' . $revision);
        });
        $flight = $this->createStub(SingleFlightInterface::class);
        $flight->method('acquire')->willReturn(null);
        $cache = new StorefrontScopeHotCache($manager, $generations, $flight);
        $url = $this->createStub(Url::class);
        $url->method('getFrontendUrl')->willReturn('/en_US/category/first');
        $index = new StorefrontCategoryTreeIndex(
            new CategoryRepository($this->provisioner, fn(int $id): Category => $this->model(Category::class, $id)),
            $cache, new ProductCategoryAttributeService($attributes), $url,
        );
        $previous = Context::getCurrent();
        $enter = static function (): void {
            Context::enter(new Context());
            StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
                ScopeIdentity::channel(0, 'default', 'fixture', 'web', 'normal'), 'en_US', 'USD',
                hash('sha256', 'namespaces'), hash('sha256', 'context'), true,
            ));
        };
        try {
            $enter();
            self::assertSame('English', $index->forWebsite(0, 'en_US')['by_id'][10]['name']);
            self::assertSame('中文', $index->forWebsite(0, 'zh_Hans_CN')['by_id'][10]['name']);
            self::assertSame(2, $reads);
            $enter();
            self::assertSame('English', $index->forWebsite(0, 'en_US')['by_id'][10]['name']);
            self::assertSame(2, $reads, 'Another request should reuse the shared language-specific presentation.');
            $pdo->exec("UPDATE product_ws_0_attribute_value SET value_string='Updated' WHERE value_id=1");
            ++$revision;
            $enter();
            self::assertSame('Updated', $index->forWebsite(0, 'en_US')['by_id'][10]['name']);
            self::assertSame(3, $reads);
        } finally {
            Context::leave();
            if ($previous !== null) { Context::enter($previous); }
            StorefrontScopeHotCache::resetProcessCache();
        }
    }

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
        $presentation = $service->readPresentationMaps(0, [10, 20], 'en_US');
        $result = (new \ReflectionMethod($index, 'applyLocalizedNames'))->invoke($index, $tree, $presentation);

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

final class CatalogReadMemoryAdapter implements CacheAdapterInterface
{
    private array $data = [];
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value, int $ttl = 0): bool { $this->data[$key] = $value; return true; }
    public function delete(string $key): bool { unset($this->data[$key]); return true; }
    public function clear(): bool { $this->data = []; return true; }
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
}
