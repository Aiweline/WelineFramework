<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderRegistry;
use Weline\Framework\Database\Schema\Shard\ShardSchemaProvisioner;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Setup\Model\Migration;
use Weline\Inventory\Api\Data\AvailabilityResult;
use Weline\Inventory\Api\InventoryCapabilityInterface;
use Weline\Inventory\Api\InventoryCatalogCopyCapabilityInterface;
use Weline\Product\Api\Data\CopyDraft;
use Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProviderV2\ProductCatalogCartItemSnapshotResolver;
use Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProviderV2\ProductCartItemSnapshotProvider;
use Weline\Product\Extends\Module\Weline_Framework\Schema\ProductShardSchemaProvider;
use Weline\Product\Model\ProductCopyOperation;
use Weline\Product\Model\ProductShardKey;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\AttributeValue;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Model\Shard\CategoryLink;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Price;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\Shard\StoreOffer;
use Weline\Product\Model\Shard\StoreProduct;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductCopyOperationRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\ProductCopyDurableCatalogAdapter;
use Weline\Product\Service\ProductCopyService;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\ProductShardSchemaCatalog;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class ProductCopyDurableCatalogAdapterTest extends TestCase
{
    public function testCartV2ProviderResolvesDurableStoreOverlaySnapshot(): void
    {
        $environment = $this->environment();
        try {
            $this->seedCatalog($environment, includeSecondProduct: false);
            $productId = (int)$environment['source_product_id'];
            $offerId = (int)$environment['source_offer_id'];
            $environment['products']->publish(0, $productId, 0);
            $environment['offers']->publish(0, $offerId, 0);
            $environment['attributes']->writeExplicit(
                0,
                3,
                'product',
                $productId,
                'name',
                'zh_Hans_CN',
                '分店商品',
                true,
            );
            $environment['attributes']->writeExplicit(
                0,
                3,
                'product',
                $productId,
                'product_type',
                'zh_Hans_CN',
                'simple',
            );

            $stores = $this->createStub(StoreCatalogInterface::class);
            $stores->method('byCode')->willReturn(new StoreSummary(
                3,
                0,
                'store-a',
                'Store A',
                ScopeIdentity::MODE_NORMAL,
                false,
                true,
                'active',
                null,
            ));
            $catalogResolver = new ProductCatalogCartItemSnapshotResolver(
                $environment['offers'],
                $environment['products'],
                $environment['attributes'],
                $environment['prices'],
                $environment['media'],
                $environment['store_offers'],
                $stores,
                static fn(): string => 'CNY',
                static fn(): string => 'zh_Hans_CN',
                static fn(int $websiteId, int $storeId, int $resolvedOfferId): AvailabilityResult =>
                    $environment['inventory']->getAvailability(
                        $websiteId,
                        $storeId,
                        $resolvedOfferId,
                    ),
            );
            $provider = new ProductCartItemSnapshotProvider(
                catalogResolver: $catalogResolver,
            );
            $offerIdentity = new OfferIdentity(
                'product',
                '00000000-0000-4000-8000-000000000201',
                legacyProductId: $productId,
            );
            $scope = ScopeIdentity::channel(
                0,
                'default',
                'store-a',
                'web',
                ScopeIdentity::MODE_NORMAL,
            );

            $snapshot = $provider->resolveCartItemSnapshot(
                $offerIdentity,
                $scope,
                ['size' => 'M'],
            );

            self::assertNotNull($snapshot);
            self::assertTrue($snapshot->found);
            self::assertTrue($snapshot->sellable);
            self::assertSame('分店商品', $snapshot->name);
            self::assertSame('COPY-SKU-1', $snapshot->sku);
            self::assertSame('/media/copy-1.jpg', $snapshot->image);
            self::assertSame('CNY', $snapshot->currency);
            self::assertSame(1250, $snapshot->unitPriceMinor);
            self::assertSame(9, $snapshot->stock);
            self::assertSame(['size' => 'M'], $snapshot->selection);
            self::assertSame('simple', $snapshot->productType);

            $environment['store_offers']->select(0, 3, $offerId, false);
            $disabled = $provider->resolveCartItemSnapshot($offerIdentity, $scope);
            self::assertNotNull($disabled);
            self::assertTrue($disabled->found);
            self::assertFalse($disabled->sellable);
            self::assertStringContainsString('Store', $disabled->message);
        } finally {
            $this->cleanupEnvironment($environment);
        }
    }

    public function testRealDatabaseCopySupportsCrossSiteSameSiteReplayAndStoreSupplement(): void
    {
        $environment = $this->environment();
        try {
            $this->seedCatalog($environment);
            $service = $environment['service'];
            $inventory = $environment['inventory'];

            $sameSite = new CopyDraft();
            $sameSite->entry = CopyDraft::ENTRY_STORE_INHERIT;
            $sameSite->sourceWebsiteId = 0;
            $sameSite->sourceStoreId = 3;
            $sameSite->targetWebsiteId = 0;
            $sameSite->targetStoreId = 4;
            $sameSite->categoryIds = [$environment['root_category_id']];
            $sameSite->fieldPackages = [
                CopyDraft::PKG_IDENTITY,
                CopyDraft::PKG_ATTRS,
                CopyDraft::PKG_PRICE,
            ];
            $sameSite = $service->createDraft($sameSite);
            $sameSiteResult = $service->commit(
                $sameSite->draftId,
                hash('sha256', 'durable-same-site'),
            );
            self::assertTrue($sameSiteResult->success);
            self::assertTrue($environment['store_products']->isSelected(
                0,
                4,
                $environment['source_product_id'],
            ));
            self::assertTrue($environment['attributes']->read(
                0,
                4,
                'product',
                $environment['source_product_id'],
                'title',
            )->isCleared());
            self::assertSame(
                1250,
                $environment['prices']->read(
                    0,
                    4,
                    $environment['source_offer_id'],
                    'CNY',
                )->value,
            );

            $draft = new CopyDraft();
            $draft->entry = CopyDraft::ENTRY_STORE_INHERIT;
            $draft->sourceWebsiteId = 0;
            $draft->sourceStoreId = 3;
            $draft->targetWebsiteId = 7;
            $draft->targetStoreId = 4;
            $draft->categoryIds = [$environment['root_category_id']];
            $draft->fieldPackages = [
                CopyDraft::PKG_IDENTITY,
                CopyDraft::PKG_ATTRS,
                CopyDraft::PKG_PRICE,
                CopyDraft::PKG_MEDIA,
                CopyDraft::PKG_INVENTORY,
            ];
            $draft->inventoryCopyQty = false;
            $created = $service->createDraft($draft);
            $preview = $service->preview($created->draftId);
            self::assertSame(2, $preview->categoryCount);
            self::assertSame(1, $preview->productCount);
            self::assertSame(1, $preview->offerCount);
            self::assertSame(1, $preview->linkCount);

            $hash = hash('sha256', 'durable-cross-site');
            $result = $service->commit($created->draftId, $hash);
            self::assertTrue($result->success);
            self::assertSame(2, $result->counts['categories_created']);
            self::assertSame(1, $result->counts['products_created']);
            self::assertSame(1, $result->counts['offers_created']);
            self::assertSame(1, $result->counts['inventory_zeroed']);

            $targetProduct = $environment['products']->findByGlobalUuid(
                7,
                '00000000-0000-4000-8000-000000000101',
            );
            $targetOffer = $environment['offers']->findByGlobalUuid(
                7,
                '00000000-0000-4000-8000-000000000201',
            );
            self::assertNotNull($targetProduct);
            self::assertNotNull($targetOffer);
            self::assertSame(
                (int)$targetProduct->getId(),
                (int)$targetOffer->getData(Offer::schema_fields_PRODUCT_ID),
            );
            self::assertSame(
                'Website title',
                $environment['attributes']->read(
                    7,
                    0,
                    'product',
                    (int)$targetProduct->getId(),
                    'title',
                )->value,
            );
            self::assertTrue($environment['attributes']->read(
                7,
                4,
                'product',
                (int)$targetProduct->getId(),
                'title',
            )->isCleared());
            self::assertSame(
                1000,
                $environment['prices']->read(
                    7,
                    0,
                    (int)$targetOffer->getId(),
                    'CNY',
                )->value,
            );
            self::assertSame(
                1250,
                $environment['prices']->read(
                    7,
                    4,
                    (int)$targetOffer->getId(),
                    'CNY',
                )->value,
            );
            self::assertCount(
                1,
                $environment['media']->listByProductIds(7, [(int)$targetProduct->getId()]),
            );
            self::assertTrue($environment['store_products']->isSelected(
                7,
                4,
                (int)$targetProduct->getId(),
            ));
            self::assertTrue($environment['store_offers']->isSelected(
                7,
                4,
                (int)$targetOffer->getId(),
            ));
            self::assertSame(
                0,
                $inventory->getAvailability(7, 4, (int)$targetOffer->getId())->onHandMinor,
            );
            $targetCategories = $environment['categories']->listAll(7);
            self::assertCount(2, $targetCategories);
            self::assertNotContains(
                '00000000-0000-4000-8000-000000000001',
                array_column($targetCategories, Category::schema_fields_GLOBAL_CATEGORY_UUID),
            );

            $replay = $service->commit($created->draftId, $hash);
            self::assertSame($result->toArray(), $replay->toArray());
            $conflict = $service->commit(
                $created->draftId,
                hash('sha256', 'durable-cross-site-conflict'),
            );
            self::assertFalse($conflict->success);
            self::assertSame('copy_idempotency_conflict', $conflict->errorCode);

            $supplement = clone $draft;
            $supplement->draftId = '';
            $supplement->state = CopyDraft::STATE_DRAFT;
            $supplement->targetStoreId = 5;
            $supplement->fieldPackages = [CopyDraft::PKG_IDENTITY];
            $supplement->duplicatePolicy = CopyDraft::POLICY_SKIP;
            $supplement = $service->createDraft($supplement);
            self::assertSame(1, $service->preview($supplement->draftId)->skipCount);
            $supplementResult = $service->commit(
                $supplement->draftId,
                hash('sha256', 'durable-supplement'),
            );
            self::assertTrue($supplementResult->success);
            self::assertSame(1, $supplementResult->counts['products_skipped']);
            self::assertTrue($environment['store_products']->isSelected(
                7,
                5,
                (int)$targetProduct->getId(),
            ));
            self::assertTrue($environment['store_offers']->isSelected(
                7,
                5,
                (int)$targetOffer->getId(),
            ));
            self::assertCount(1, $environment['products']->listAll(7));
        } finally {
            $this->cleanupEnvironment($environment);
        }
    }

    public function testRealDatabaseCatalogAndInventoryRollBackAndDraftReopens(): void
    {
        $environment = $this->environment(failInventoryWrite: 2);
        try {
            $this->seedCatalog($environment, includeSecondProduct: true, secondSelected: true);
            $draft = new CopyDraft();
            $draft->entry = CopyDraft::ENTRY_STORE_INHERIT;
            $draft->sourceWebsiteId = 0;
            $draft->sourceStoreId = 3;
            $draft->targetWebsiteId = 7;
            $draft->targetStoreId = 8;
            $draft->categoryIds = [$environment['root_category_id']];
            $draft->fieldPackages = [
                CopyDraft::PKG_IDENTITY,
                CopyDraft::PKG_ATTRS,
                CopyDraft::PKG_PRICE,
                CopyDraft::PKG_MEDIA,
                CopyDraft::PKG_INVENTORY,
            ];
            $created = $environment['service']->createDraft($draft);
            self::assertSame(2, $environment['service']->preview($created->draftId)->productCount);

            $result = $environment['service']->commit(
                $created->draftId,
                hash('sha256', 'durable-rollback'),
            );

            self::assertFalse($result->success);
            self::assertSame('copy_commit_failed', $result->errorCode);
            self::assertSame([], $environment['products']->listAll(7));
            self::assertSame([], $environment['categories']->listAll(7));
            self::assertSame([], $environment['offers']->listByProductIds(7, [1, 2, 3, 4]));
            self::assertFalse($environment['inventory']->hasStockForWebsite(7));
            self::assertSame(
                CopyDraft::STATE_DRAFT,
                $environment['operations']->findDraft($created->draftId)?->state,
            );
        } finally {
            $this->cleanupEnvironment($environment);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(int $failInventoryWrite = 0): array
    {
        $pgsqlDatabase = trim((string)getenv('WELINE_PRODUCT_COPY_TEST_PGSQL_DATABASE'));
        $pgsql = $pgsqlDatabase !== '';
        if ($pgsql) {
            self::assertContains('pgsql', PDO::getAvailableDrivers());
            $path = '';
            $connection = ConnectionFactory::getInstance(new ConfigProvider([
                'type' => 'pgsql',
                'hostname' => getenv('WELINE_PRODUCT_COPY_TEST_PGSQL_HOST') ?: '127.0.0.1',
                'hostport' => getenv('WELINE_PRODUCT_COPY_TEST_PGSQL_PORT') ?: '5432',
                'database' => $pgsqlDatabase,
                'username' => getenv('WELINE_PRODUCT_COPY_TEST_PGSQL_USERNAME') ?: 'weline',
                'password' => getenv('WELINE_PRODUCT_COPY_TEST_PGSQL_PASSWORD') ?: 'weline',
                'charset' => 'utf8',
                'persistent' => false,
            ]));
        } else {
            self::assertContains('sqlite', PDO::getAvailableDrivers());
            $path = sys_get_temp_dir()
                . '/weline_product_copy_adapter_'
                . bin2hex(random_bytes(8))
                . '.sqlite';
            $connection = ConnectionFactory::getInstance(new ConfigProvider([
                'type' => 'sqlite',
                'database' => '',
                'path' => $path,
                'persistent' => false,
            ]));
        }
        $connector = $connection->getConnector();
        $this->createRegistryTable($connector, $pgsql);
        $this->createOperationTable($connector, $pgsql);
        $provisioner = $this->createProvisioner($connection);
        $websiteZero = $provisioner->provisionWebsite(0);
        self::assertTrue($websiteZero->isReady(), $websiteZero->errorMessage ?? $websiteZero->status);
        $websiteSeven = $provisioner->provisionWebsite(7);
        self::assertTrue($websiteSeven->isReady(), $websiteSeven->errorMessage ?? $websiteSeven->status);
        $transactions = new DatabaseTransactionRunner(new TransactionCoordinator());
        $tokens = $this->tokenFactory();

        $categories = new CategoryRepository(
            $provisioner,
            $this->modelFactory($connection, Category::class),
        );
        $products = new ProductRepository(
            $provisioner,
            $this->modelFactory($connection, Product::class),
            $tokens,
        );
        $offers = new OfferRepository(
            $provisioner,
            $this->modelFactory($connection, Offer::class),
            $tokens,
        );
        $categoryLinks = new CategoryLinkRepository(
            $provisioner,
            $this->modelFactory($connection, CategoryLink::class),
        );
        $attributes = new AttributeValueRepository(
            $provisioner,
            new CatalogOverlayResolver(),
            $this->modelFactory($connection, AttributeValue::class),
        );
        $prices = new PriceRepository(
            $provisioner,
            new CatalogOverlayResolver(),
            $this->modelFactory($connection, Price::class),
        );
        $media = new MediaRepository(
            $provisioner,
            $connection,
            $transactions,
            $this->modelFactory($connection, Media::class),
            $tokens,
        );
        $storeProducts = new StoreProductRepository(
            $provisioner,
            $this->modelFactory($connection, StoreProduct::class),
        );
        $storeOffers = new StoreOfferRepository(
            $provisioner,
            $this->modelFactory($connection, StoreOffer::class),
        );
        $sequence = 0;
        $operations = new ProductCopyOperationRepository(
            function () use ($connection): ProductCopyOperation {
                $model = new ProductCopyOperation();
                $model->setConnection($connection);
                $model->__init();
                return $model;
            },
            static function () use (&$sequence): string {
                $sequence++;
                return str_pad(dechex($sequence), 64, '0', STR_PAD_LEFT);
            },
        );
        $inventory = new TestInventoryCatalogCopyCapability($failInventoryWrite);
        $adapter = new ProductCopyDurableCatalogAdapter(
            $connection,
            $transactions,
            $categories,
            $products,
            $offers,
            $categoryLinks,
            $attributes,
            $prices,
            $media,
            $storeProducts,
            $storeOffers,
            $inventory,
        );
        $service = new ProductCopyService(
            operations: $operations,
            catalogAdapter: $adapter,
        );

        return [
            'path' => $path,
            'pgsql' => $pgsql,
            'connection' => $connection,
            'connector' => $connector,
            'categories' => $categories,
            'products' => $products,
            'offers' => $offers,
            'category_links' => $categoryLinks,
            'attributes' => $attributes,
            'prices' => $prices,
            'media' => $media,
            'store_products' => $storeProducts,
            'store_offers' => $storeOffers,
            'operations' => $operations,
            'inventory' => $inventory,
            'service' => $service,
        ];
    }

    /** @param array<string, mixed> $environment */
    private function cleanupEnvironment(array $environment): void
    {
        $path = (string)($environment['path'] ?? '');
        $pgsql = (bool)($environment['pgsql'] ?? false);
        $connector = $environment['connector'] ?? null;
        $connection = $environment['connection'] ?? null;

        if ($pgsql && $connector instanceof ConnectorInterface) {
            foreach ([7, 0] as $websiteId) {
                foreach (array_reverse(ProductShardSchemaCatalog::ENTITIES) as $entity) {
                    $connector->query(
                        'DROP TABLE IF EXISTS '
                        . ProductShardKey::tableName((string)$websiteId, $entity)
                        . ' CASCADE',
                    )->fetch();
                }
            }
            $connector->query(
                'DROP TABLE IF EXISTS product_copy_operation CASCADE',
            )->fetch();
            $connector->query(
                'DROP TABLE IF EXISTS product_shard_registry CASCADE',
            )->fetch();
        }

        if ($connector instanceof ConnectorInterface) {
            $connector->close();
        }
        if ($connection instanceof ConnectionFactory) {
            $connection->close();
        }
        if ($path !== '') {
            @unlink($path);
        }
    }

    /** @param array<string, mixed> $environment */
    private function seedCatalog(
        array &$environment,
        bool $includeSecondProduct = true,
        bool $secondSelected = false,
    ): void {
        $root = $environment['categories']->create(0, [
            Category::schema_fields_GLOBAL_CATEGORY_UUID => '00000000-0000-4000-8000-000000000001',
            Category::schema_fields_PARENT_ID => null,
            Category::schema_fields_PATH => '',
            Category::schema_fields_STATUS => 'active',
        ]);
        $child = $environment['categories']->create(0, [
            Category::schema_fields_GLOBAL_CATEGORY_UUID => '00000000-0000-4000-8000-000000000002',
            Category::schema_fields_PARENT_ID => (int)$root->getId(),
            Category::schema_fields_PATH => (string)$root->getId(),
            Category::schema_fields_STATUS => 'active',
        ]);
        $product = $environment['products']->create(0, [
            Product::schema_fields_SKU => 'COPY-SKU-1',
            Product::schema_fields_GLOBAL_PRODUCT_UUID => '00000000-0000-4000-8000-000000000101',
        ]);
        $offer = $environment['offers']->create(0, [
            Offer::schema_fields_PRODUCT_ID => (int)$product->getId(),
            Offer::schema_fields_GLOBAL_OFFER_UUID => '00000000-0000-4000-8000-000000000201',
        ]);
        $environment['category_links']->link(0, (int)$root->getId(), (int)$product->getId());
        $environment['attributes']->writeExplicit(
            0,
            0,
            'product',
            (int)$product->getId(),
            'title',
            '',
            'Website title',
        );
        $environment['attributes']->writeCleared(
            0,
            3,
            'product',
            (int)$product->getId(),
            'title',
            '',
        );
        $environment['prices']->writeExplicit(0, 0, (int)$offer->getId(), 'CNY', 1000);
        $environment['prices']->writeExplicit(0, 3, (int)$offer->getId(), 'CNY', 1250);
        $environment['media']->create(0, [
            Media::schema_fields_PRODUCT_ID => (int)$product->getId(),
            Media::schema_fields_PATH => '/media/copy-1.jpg',
            Media::schema_fields_BLOB_KEY => 'copy-blob-1',
            Media::schema_fields_POSITION => 1,
        ]);
        $environment['store_products']->select(0, 3, (int)$product->getId(), true);
        $environment['store_offers']->select(0, 3, (int)$offer->getId(), true);
        $environment['inventory']->seed(0, 3, (int)$offer->getId(), 9);

        if ($includeSecondProduct) {
            $product2 = $environment['products']->create(0, [
                Product::schema_fields_SKU => 'COPY-SKU-2',
                Product::schema_fields_GLOBAL_PRODUCT_UUID => '00000000-0000-4000-8000-000000000102',
            ]);
            $offer2 = $environment['offers']->create(0, [
                Offer::schema_fields_PRODUCT_ID => (int)$product2->getId(),
                Offer::schema_fields_GLOBAL_OFFER_UUID => '00000000-0000-4000-8000-000000000202',
            ]);
            $environment['category_links']->link(
                0,
                (int)$child->getId(),
                (int)$product2->getId(),
            );
            $environment['store_products']->select(
                0,
                3,
                (int)$product2->getId(),
                $secondSelected,
            );
            $environment['store_offers']->select(
                0,
                3,
                (int)$offer2->getId(),
                $secondSelected,
            );
            $environment['inventory']->seed(0, 3, (int)$offer2->getId(), 5);
        }

        $environment['root_category_id'] = (int)$root->getId();
        $environment['source_product_id'] = (int)$product->getId();
        $environment['source_offer_id'] = (int)$offer->getId();
    }

    /**
     * @param class-string<AbstractWebsiteShardModel> $class
     * @return \Closure(int): AbstractWebsiteShardModel
     */
    private function modelFactory(ConnectionFactory $connection, string $class): \Closure
    {
        return static function (int $websiteId) use ($connection, $class): AbstractWebsiteShardModel {
            $model = new $class();
            $model->setConnection($connection);
            $model->__init();
            return $model->forWebsite($websiteId);
        };
    }

    /** @return \Closure(): string */
    private function tokenFactory(): \Closure
    {
        $sequence = 0;
        return static function () use (&$sequence): string {
            $sequence++;
            return str_pad(dechex($sequence), 64, '0', STR_PAD_LEFT);
        };
    }

    private function createProvisioner(ConnectionFactory $connection): ProductShardProvisioner
    {
        $registry = new ProductShardRegistry();
        $registry->setConnection($connection);
        $registry->__init();
        $catalog = new ProductShardSchemaCatalog();
        $provider = new ProductShardSchemaProvider($registry, $catalog);
        $familyRegistry = new ShardSchemaFamilyProviderRegistry(
            manualFamilyProviders: [ProductShardKey::FAMILY_CODE => $provider],
            scanExtends: false,
        );
        $migration = $this->createMock(Migration::class);
        $migration->method('recordSchemaDdl')->willReturn(1);
        $migration->method('updateStatus')->willReturn(true);
        $generic = new ShardSchemaProvisioner(
            $connection,
            $familyRegistry,
            new DbSchemaReader(),
            new SchemaDiffEngine(),
            new SchemaMigrationExecutor(
                $this->createMock(EventsManager::class),
                $migration,
                $this->createMock(\Weline\Framework\Database\Service\BackupService::class),
            ),
        );
        return new ProductShardProvisioner($registry, $generic, $catalog);
    }

    private function createRegistryTable(ConnectorInterface $connector, bool $pgsql): void
    {
        $primaryKey = $pgsql
            ? 'INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $dateType = $pgsql ? 'TIMESTAMP' : 'DATETIME';
        $connector->query(
            'CREATE TABLE product_shard_registry ('
            . 'registry_id ' . $primaryKey . ', '
            . 'website_id INTEGER NOT NULL UNIQUE, '
            . 'shard_key VARCHAR(32) NOT NULL UNIQUE, '
            . "status VARCHAR(32) NOT NULL DEFAULT 'unprovisioned', "
            . "fingerprint VARCHAR(64) NOT NULL DEFAULT '', "
            . "schema_version VARCHAR(32) NOT NULL DEFAULT '1', "
            . 'error_message TEXT NULL, '
            . 'created_at ' . $dateType . ' NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at ' . $dateType . ' NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')',
        )->fetch();
    }

    private function createOperationTable(ConnectorInterface $connector, bool $pgsql): void
    {
        $primaryKey = $pgsql
            ? 'INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $dateType = $pgsql ? 'TIMESTAMP' : 'DATETIME';
        $connector->query(
            'CREATE TABLE product_copy_operation ('
            . 'operation_id ' . $primaryKey . ', '
            . 'draft_uuid VARCHAR(64) NOT NULL UNIQUE, '
            . "state VARCHAR(32) NOT NULL DEFAULT 'draft', "
            . 'entry VARCHAR(32) NOT NULL, '
            . 'target_website_id INTEGER NOT NULL, '
            . 'target_store_id INTEGER NOT NULL, '
            . 'source_website_id INTEGER NULL, '
            . 'source_store_id INTEGER NULL, '
            . 'draft_json TEXT NOT NULL, '
            . 'request_hash VARCHAR(128) NULL, '
            . 'claim_token VARCHAR(64) NULL, '
            . 'result_json TEXT NULL, '
            . 'error_code VARCHAR(64) NULL, '
            . 'created_at ' . $dateType . ' NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at ' . $dateType . ' NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')',
        )->fetch();
    }
}

final class TestInventoryCatalogCopyCapability implements InventoryCatalogCopyCapabilityInterface
{
    /** @var array<string, int> */
    private array $stock = [];
    private int $writeCount = 0;

    public function __construct(
        private readonly int $failOnWrite = 0,
    ) {
    }

    public function seed(int $websiteId, int $storeId, int $offerId, int $quantity): void
    {
        $this->stock[$this->key($websiteId, $storeId, $offerId)] = $quantity;
    }

    public function hasStockForWebsite(int $websiteId): bool
    {
        foreach (array_keys($this->stock) as $key) {
            if (str_starts_with($key, $websiteId . ':')) {
                return true;
            }
        }
        return false;
    }

    public function transactional(callable $callback): mixed
    {
        $stock = $this->stock;
        $writeCount = $this->writeCount;
        try {
            return $callback();
        } catch (Throwable $exception) {
            $this->stock = $stock;
            $this->writeCount = $writeCount;
            throw $exception;
        }
    }

    public function getAvailability(int $websiteId, int $storeId, int $offerId): AvailabilityResult
    {
        $quantity = $this->stock[$this->key($websiteId, $storeId, $offerId)] ?? 0;
        return new AvailabilityResult(
            $websiteId,
            $storeId,
            $offerId,
            InventoryCapabilityInterface::STRATEGY_STRICT,
            $quantity,
            0,
            $quantity,
            $quantity > 0,
            0,
        );
    }

    public function ensureStock(
        int $websiteId,
        int $storeId,
        int $offerId,
        string $strategy = InventoryCapabilityInterface::STRATEGY_STRICT,
        int $onHandMinor = 0,
        int $oversellAllowance = 0,
        int $preorderAllowance = 0,
    ): void {
        $key = $this->key($websiteId, $storeId, $offerId);
        $this->stock[$key] ??= $onHandMinor;
    }

    public function setOnHand(
        int $websiteId,
        int $storeId,
        int $offerId,
        int $onHandMinor,
        string $idempotencyKey,
        string $requestHash,
        ?string $strategy = null,
        ?int $oversellAllowance = null,
        ?int $preorderAllowance = null,
    ): AvailabilityResult {
        $this->writeCount++;
        if ($this->failOnWrite > 0 && $this->writeCount === $this->failOnWrite) {
            throw new \RuntimeException('simulated inventory copy failure');
        }
        $this->stock[$this->key($websiteId, $storeId, $offerId)] = $onHandMinor;
        return $this->getAvailability($websiteId, $storeId, $offerId);
    }

    private function key(int $websiteId, int $storeId, int $offerId): string
    {
        return $websiteId . ':' . $storeId . ':' . $offerId;
    }
}
