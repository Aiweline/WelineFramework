<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
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
use Weline\Product\Extends\Module\Weline_Framework\Schema\ProductShardSchemaProvider;
use Weline\Product\Model\ProductShardKey;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\AttributeValue;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Price;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\Shard\StoreOffer;
use Weline\Product\Model\Shard\StoreProduct;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Product\Service\CatalogConflictException;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\ProductShardSchemaCatalog;

/**
 * Current-source acceptance for TEST-P2A-04..07 on real disposable SQLite.
 */
final class CatalogRepositoryIntegrationTest extends TestCase
{
    public function testRepositoriesPersistIsolationClearedOwnedCasAndMediaCow(): void
    {
        self::assertContains(
            'sqlite',
            PDO::getAvailableDrivers(),
            'P2A-004 acceptance requires pdo_sqlite.',
        );

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p2a004_catalog_'
            . bin2hex(random_bytes(8))
            . '.sqlite';
        $connectionFactory = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connector = $connectionFactory->getConnector();
        $this->createRegistryTable($connector);
        $provisioner = $this->createProvisioner($connectionFactory);
        $tokens = $this->tokenFactory();

        try {
            self::assertTrue($provisioner->provisionWebsite(0)->isReady());
            self::assertTrue($provisioner->provisionWebsite(7)->isReady());
            self::assertSame(
                ProductShardSchemaCatalog::SCHEMA_VERSION,
                $this->registry($connectionFactory)->getSchemaVersion(0),
            );
            self::assertSame(
                ProductShardSchemaCatalog::SCHEMA_VERSION,
                $this->registry($connectionFactory)->getSchemaVersion(7),
            );

            $products = new ProductRepository(
                $provisioner,
                $this->modelFactory($connectionFactory, Product::class),
                $tokens,
            );
            $categories = new CategoryRepository(
                $provisioner,
                $this->modelFactory($connectionFactory, Category::class),
            );
            $offers = new OfferRepository(
                $provisioner,
                $this->modelFactory($connectionFactory, Offer::class),
                $tokens,
            );
            $attributes = new AttributeValueRepository(
                $provisioner,
                new CatalogOverlayResolver(),
                $this->modelFactory($connectionFactory, AttributeValue::class),
            );
            $prices = new PriceRepository(
                $provisioner,
                new CatalogOverlayResolver(),
                $this->modelFactory($connectionFactory, Price::class),
            );
            $storeProducts = new StoreProductRepository(
                $provisioner,
                $this->modelFactory($connectionFactory, StoreProduct::class),
            );
            $storeOffers = new StoreOfferRepository(
                $provisioner,
                $this->modelFactory($connectionFactory, StoreOffer::class),
            );
            $media = new MediaRepository(
                $provisioner,
                $connectionFactory,
                new DatabaseTransactionRunner(new TransactionCoordinator()),
                $this->modelFactory($connectionFactory, Media::class),
                $tokens,
            );

            $product0 = $products->create(0, [
                Product::schema_fields_ID => 999,
                Product::schema_fields_SKU => 'P2A004-SAME-SKU',
                Product::schema_fields_GLOBAL_PRODUCT_UUID => '00000000-0000-0000-0000-000000000040',
                Product::schema_fields_STATUS => Product::STATUS_PUBLISHED,
                Product::schema_fields_PUBLISH_VERSION => 99,
                Product::schema_fields_CAS_TOKEN => str_repeat('f', 64),
            ]);
            $categoryUuid = '00000000-0000-4000-8000-000000000041';
            $category = $categories->create(0, [
                Category::schema_fields_GLOBAL_CATEGORY_UUID => $categoryUuid,
                Category::schema_fields_PARENT_ID => null,
                Category::schema_fields_PATH => '',
                Category::schema_fields_STATUS => 'active',
            ]);
            self::assertSame(
                (int)$category->getId(),
                (int)$categories->findByGlobalUuid(0, $categoryUuid)?->getId(),
            );
            $product7 = $products->create(7, [
                Product::schema_fields_SKU => 'P2A004-SAME-SKU',
                Product::schema_fields_GLOBAL_PRODUCT_UUID => '00000000-0000-0000-0000-000000000040',
            ]);
            self::assertNotSame(999, (int)$product0->getId());
            self::assertSame(Product::STATUS_DRAFT, $product0->getData(Product::schema_fields_STATUS));
            self::assertSame(0, (int)$product0->getData(Product::schema_fields_PUBLISH_VERSION));
            self::assertSame('', (string)$product0->getData(Product::schema_fields_CAS_TOKEN));
            self::assertSame(
                (int)$product0->getId(),
                (int)$products->findBySku(0, 'P2A004-SAME-SKU')?->getId(),
            );
            self::assertSame(
                (int)$product7->getId(),
                (int)$products->findBySku(7, 'P2A004-SAME-SKU')?->getId(),
            );

            $offer0 = $offers->create(0, [
                Offer::schema_fields_PRODUCT_ID => (int)$product0->getId(),
                Offer::schema_fields_GLOBAL_OFFER_UUID => '00000000-0000-0000-0000-000000000041',
                Offer::schema_fields_STATUS => 'published',
                Offer::schema_fields_PUBLISH_VERSION => 31,
            ]);
            $offer7 = $offers->create(7, [
                Offer::schema_fields_PRODUCT_ID => (int)$product7->getId(),
                Offer::schema_fields_GLOBAL_OFFER_UUID => '00000000-0000-0000-0000-000000000041',
            ]);
            self::assertSame('draft', $offer0->getData(Offer::schema_fields_STATUS));
            self::assertSame(0, (int)$offer0->getData(Offer::schema_fields_PUBLISH_VERSION));

            $this->assertOverlayAndCleared(
                $attributes,
                $prices,
                (int)$product0->getId(),
                (int)$product7->getId(),
                (int)$offer0->getId(),
                (int)$offer7->getId(),
            );
            $this->assertStoreOnlySelection(
                $storeProducts,
                $storeOffers,
                (int)$product0->getId(),
                (int)$offer0->getId(),
            );
            $this->assertOwnedPublishCas(
                $connector,
                $provisioner,
                $connectionFactory,
                $products,
                $offers,
            );
            $this->assertMediaCowAndRollback(
                $provisioner,
                $connectionFactory,
                $media,
                $product0,
                $product7,
                $tokens,
            );

            foreach ([0, 7] as $websiteId) {
                foreach (['product', 'offer', 'media'] as $entity) {
                    $columns = $connector->query(
                        'PRAGMA table_info('
                        . ProductShardKey::tableName((string)$websiteId, $entity)
                        . ')'
                    )->fetch();
                    self::assertContains(
                        Media::schema_fields_CAS_TOKEN,
                        array_map(
                            static fn(array $row): string => (string)($row['name'] ?? ''),
                            $columns,
                        ),
                    );
                }
            }
        } finally {
            $connector->close();
            $connectionFactory->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }

        self::assertFileDoesNotExist($dbPath);
    }

    private function assertOverlayAndCleared(
        AttributeValueRepository $attributes,
        PriceRepository $prices,
        int $product0Id,
        int $product7Id,
        int $offer0Id,
        int $offer7Id,
    ): void {
        $attributes->writeExplicit(0, 0, 'product', $product0Id, 'name', '', 'Website v1', true);
        $attributes->writeExplicit(0, 22, 'product', $product0Id, 'name', '', 'Store B', true);
        $attributes->writeExplicit(7, 0, 'product', $product7Id, 'name', '', 'Other Website', true);

        self::assertSame(
            'Website v1',
            $attributes->read(0, 11, 'product', $product0Id, 'name')->value,
        );
        self::assertSame(
            'Store B',
            $attributes->read(0, 22, 'product', $product0Id, 'name')->value,
        );
        self::assertSame(
            'Other Website',
            $attributes->read(7, 11, 'product', $product7Id, 'name')->value,
        );

        $attributes->writeExplicit(0, 0, 'product', $product0Id, 'name', '', 'Website v2', true);
        self::assertSame(
            'Website v2',
            $attributes->read(0, 11, 'product', $product0Id, 'name')->value,
        );
        self::assertSame(
            'Store B',
            $attributes->read(0, 22, 'product', $product0Id, 'name')->value,
        );

        $attributes->writeCleared(0, 11, 'product', $product0Id, 'name', '', true);
        self::assertTrue(
            $attributes->read(0, 11, 'product', $product0Id, 'name')->isCleared(),
        );
        self::assertSame(
            'cleared_at_scope',
            $this->captureConflict(
                static fn() => $attributes->assertPublishable(
                    0,
                    11,
                    'product',
                    $product0Id,
                    'name',
                ),
            )->errorCode(),
        );
        $attributes->deleteOverlay(0, 11, 'product', $product0Id, 'name');
        self::assertSame(
            'Website v2',
            $attributes->read(0, 11, 'product', $product0Id, 'name')->value,
        );

        $prices->writeExplicit(0, 0, $offer0Id, 'cny', 1990);
        $prices->writeExplicit(7, 0, $offer7Id, 'cny', 7777);
        self::assertSame(1990, $prices->assertSellable(0, 11, $offer0Id, 'CNY'));
        self::assertSame(7777, $prices->assertSellable(7, 11, $offer7Id, 'CNY'));
        $prices->writeCleared(0, 11, $offer0Id, 'CNY');
        self::assertSame(
            'price_cleared_at_scope',
            $this->captureConflict(
                static fn() => $prices->assertSellable(0, 11, $offer0Id, 'CNY'),
            )->errorCode(),
        );
        $prices->deleteOverlay(0, 11, $offer0Id, 'CNY');
        self::assertSame(1990, $prices->assertSellable(0, 11, $offer0Id, 'CNY'));
    }

    private function assertStoreOnlySelection(
        StoreProductRepository $storeProducts,
        StoreOfferRepository $storeOffers,
        int $productId,
        int $offerId,
    ): void {
        self::assertTrue($storeProducts->isSelected(0, 11, $productId));
        self::assertTrue($storeOffers->isSelected(0, 11, $offerId));
        $storeProducts->select(0, 11, $productId, false);
        $storeOffers->select(0, 11, $offerId, false);
        self::assertFalse($storeProducts->isSelected(0, 11, $productId));
        self::assertFalse($storeOffers->isSelected(0, 11, $offerId));

        $this->expectInvalid(static fn() => $storeProducts->find(0, 0, $productId));
        $this->expectInvalid(static fn() => $storeOffers->find(0, 0, $offerId));
    }

    private function assertOwnedPublishCas(
        ConnectorInterface $connector,
        ProductShardProvisioner $provisioner,
        ConnectionFactory $connectionFactory,
        ProductRepository $products,
        OfferRepository $offers,
    ): void {
        $publishedProduct = $products->create(0, [
            Product::schema_fields_SKU => 'P2A004-PUBLISH-OK',
            Product::schema_fields_GLOBAL_PRODUCT_UUID => '00000000-0000-0000-0000-000000000042',
        ]);
        $publishedProduct = $products->publish(0, (int)$publishedProduct->getId(), 0);
        self::assertSame(1, (int)$publishedProduct->getData(Product::schema_fields_PUBLISH_VERSION));
        self::assertSame(
            64,
            strlen((string)$publishedProduct->getData(Product::schema_fields_CAS_TOKEN)),
        );

        $racedProduct = $products->create(0, [
            Product::schema_fields_SKU => 'P2A004-PUBLISH-RACE',
            Product::schema_fields_GLOBAL_PRODUCT_UUID => '00000000-0000-0000-0000-000000000043',
        ]);
        $productCalls = 0;
        $productId = (int)$racedProduct->getId();
        $productFactory = function (int $websiteId) use (
            &$productCalls,
            $connectionFactory,
            $connector,
            $productId,
        ): Product {
            $productCalls++;
            if ($productCalls === 2) {
                $connector->query(
                    "UPDATE product_ws_0_product SET status='published',"
                    . " publish_version=1, cas_token='" . str_repeat('a', 64) . "'"
                    . " WHERE product_id={$productId}"
                )->fetch();
            }
            /** @var Product $model */
            $model = $this->boundModel($connectionFactory, Product::class, $websiteId);
            return $model;
        };
        $racingProducts = new ProductRepository(
            $provisioner,
            $productFactory,
            static fn(): string => str_repeat('b', 64),
        );
        self::assertSame(
            'publish_version_conflict',
            $this->captureConflict(
                static fn() => $racingProducts->publish(0, $productId, 0),
            )->errorCode(),
        );
        self::assertSame(
            str_repeat('a', 64),
            (string)$products->findById(0, $productId)?->getData(Product::schema_fields_CAS_TOKEN),
        );

        $racedOffer = $offers->create(0, [
            Offer::schema_fields_PRODUCT_ID => $productId,
            Offer::schema_fields_GLOBAL_OFFER_UUID => '00000000-0000-0000-0000-000000000044',
        ]);
        $offerCalls = 0;
        $offerId = (int)$racedOffer->getId();
        $offerFactory = function (int $websiteId) use (
            &$offerCalls,
            $connectionFactory,
            $connector,
            $offerId,
        ): Offer {
            $offerCalls++;
            if ($offerCalls === 2) {
                $connector->query(
                    "UPDATE product_ws_0_offer SET status='published',"
                    . " publish_version=1, cas_token='" . str_repeat('c', 64) . "'"
                    . " WHERE offer_id={$offerId}"
                )->fetch();
            }
            /** @var Offer $model */
            $model = $this->boundModel($connectionFactory, Offer::class, $websiteId);
            return $model;
        };
        $racingOffers = new OfferRepository(
            $provisioner,
            $offerFactory,
            static fn(): string => str_repeat('d', 64),
        );
        self::assertSame(
            'publish_version_conflict',
            $this->captureConflict(
                static fn() => $racingOffers->publish(0, $offerId, 0),
            )->errorCode(),
        );
        self::assertSame(
            str_repeat('c', 64),
            (string)$offers->findById(0, $offerId)?->getData(Offer::schema_fields_CAS_TOKEN),
        );
    }

    private function assertMediaCowAndRollback(
        ProductShardProvisioner $provisioner,
        ConnectionFactory $connectionFactory,
        MediaRepository $media,
        Product $product0,
        Product $product7,
        callable $tokens,
    ): void {
        $source = $media->create(0, [
            Media::schema_fields_ID => 999,
            Media::schema_fields_PRODUCT_ID => (int)$product0->getId(),
            Media::schema_fields_PATH => '/media/shared.jpg',
            Media::schema_fields_BLOB_KEY => 'blob-shared',
            Media::schema_fields_REF_COUNT => 77,
            Media::schema_fields_COW_SOURCE_MEDIA_ID => 88,
            Media::schema_fields_CAS_TOKEN => str_repeat('e', 64),
        ]);
        self::assertNotSame(999, (int)$source->getId());
        self::assertSame(1, (int)$source->getData(Media::schema_fields_REF_COUNT));
        self::assertNull($source->getData(Media::schema_fields_COW_SOURCE_MEDIA_ID));
        self::assertSame('', (string)$source->getData(Media::schema_fields_CAS_TOKEN));

        $copy = $media->shareCopy(0, (int)$source->getId(), (int)$product0->getId(), 2);
        $source = $media->findById(0, (int)$source->getId());
        self::assertNotNull($source);
        self::assertSame('blob-shared', $copy->getData(Media::schema_fields_BLOB_KEY));
        self::assertSame(2, (int)$source->getData(Media::schema_fields_REF_COUNT));
        self::assertSame(1, (int)$copy->getData(Media::schema_fields_REF_COUNT));
        self::assertSame(
            (int)$source->getId(),
            (int)$copy->getData(Media::schema_fields_COW_SOURCE_MEDIA_ID),
        );

        $fork = $media->cowEdit(0, (int)$copy->getId(), '/media/fork.jpg', 'blob-fork');
        self::assertTrue($fork['cow']);
        self::assertSame('blob-fork', $fork['media']->getData(Media::schema_fields_BLOB_KEY));
        $source = $media->findById(0, (int)$source->getId());
        self::assertSame('blob-shared', $source?->getData(Media::schema_fields_BLOB_KEY));
        self::assertSame(1, (int)$source?->getData(Media::schema_fields_REF_COUNT));

        $owner = $media->create(0, [
            Media::schema_fields_PRODUCT_ID => (int)$product0->getId(),
            Media::schema_fields_PATH => '/media/owner.jpg',
            Media::schema_fields_BLOB_KEY => 'blob-owner',
        ]);
        $copyA = $media->shareCopy(0, (int)$owner->getId(), (int)$product0->getId(), 3);
        $copyB = $media->shareCopy(0, (int)$owner->getId(), (int)$product0->getId(), 4);
        $ownerFork = $media->cowEdit(
            0,
            (int)$owner->getId(),
            '/media/owner-fork.jpg',
            'blob-owner-fork',
        );
        self::assertTrue($ownerFork['cow']);
        $copyA = $media->findById(0, (int)$copyA->getId());
        $copyB = $media->findById(0, (int)$copyB->getId());
        self::assertNotNull($copyA);
        self::assertNotNull($copyB);
        self::assertNull($copyA->getData(Media::schema_fields_COW_SOURCE_MEDIA_ID));
        self::assertSame(2, (int)$copyA->getData(Media::schema_fields_REF_COUNT));
        self::assertSame(
            (int)$copyA->getId(),
            (int)$copyB->getData(Media::schema_fields_COW_SOURCE_MEDIA_ID),
        );
        self::assertSame('blob-owner', $copyB->getData(Media::schema_fields_BLOB_KEY));

        $rollbackSource = $media->create(0, [
            Media::schema_fields_PRODUCT_ID => (int)$product0->getId(),
            Media::schema_fields_PATH => '/media/rollback.jpg',
            Media::schema_fields_BLOB_KEY => 'blob-rollback',
        ]);
        $factoryCalls = 0;
        $failingFactory = function (int $websiteId) use (
            &$factoryCalls,
            $connectionFactory,
        ): Media {
            $factoryCalls++;
            if ($factoryCalls === 6) {
                throw new \RuntimeException('simulated copy insert failure');
            }
            /** @var Media $model */
            $model = $this->boundModel($connectionFactory, Media::class, $websiteId);
            return $model;
        };
        $failingMedia = new MediaRepository(
            $provisioner,
            $connectionFactory,
            new DatabaseTransactionRunner(new TransactionCoordinator()),
            $failingFactory,
            $tokens,
        );
        try {
            $failingMedia->shareCopy(
                0,
                (int)$rollbackSource->getId(),
                (int)$product0->getId(),
            );
            self::fail('Expected simulated media insert failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated copy insert failure', $exception->getMessage());
        }
        $rollbackSource = $media->findById(0, (int)$rollbackSource->getId());
        self::assertSame(1, (int)$rollbackSource?->getData(Media::schema_fields_REF_COUNT));
        self::assertSame('', (string)$rollbackSource?->getData(Media::schema_fields_CAS_TOKEN));

        $otherWebsite = $media->create(7, [
            Media::schema_fields_PRODUCT_ID => (int)$product7->getId(),
            Media::schema_fields_PATH => '/media/other-website.jpg',
            Media::schema_fields_BLOB_KEY => 'blob-shared',
        ]);
        self::assertSame('blob-shared', $otherWebsite->getData(Media::schema_fields_BLOB_KEY));
        self::assertSame(1, (int)$otherWebsite->getData(Media::schema_fields_REF_COUNT));
        self::assertSame(
            'media_blob_key_conflict',
            $this->captureConflict(
                static fn() => $media->create(0, [
                    Media::schema_fields_PRODUCT_ID => (int)$product0->getId(),
                    Media::schema_fields_PATH => '/media/duplicate.jpg',
                    Media::schema_fields_BLOB_KEY => 'blob-shared',
                ]),
            )->errorCode(),
        );
    }

    /**
     * @param class-string<AbstractWebsiteShardModel> $class
     * @return \Closure(int): AbstractWebsiteShardModel
     */
    private function modelFactory(ConnectionFactory $connectionFactory, string $class): \Closure
    {
        return fn(int $websiteId): AbstractWebsiteShardModel => $this->boundModel(
            $connectionFactory,
            $class,
            $websiteId,
        );
    }

    /**
     * @param class-string<AbstractWebsiteShardModel> $class
     */
    private function boundModel(
        ConnectionFactory $connectionFactory,
        string $class,
        int $websiteId,
    ): AbstractWebsiteShardModel {
        $model = new $class();
        $model->setConnection($connectionFactory);
        $model->__init();
        return $model->forWebsite($websiteId);
    }

    /**
     * @return \Closure(): string
     */
    private function tokenFactory(): \Closure
    {
        $sequence = 0;
        return static function () use (&$sequence): string {
            $sequence++;
            return str_pad(dechex($sequence), 64, '0', STR_PAD_LEFT);
        };
    }

    private function captureConflict(callable $callback): CatalogConflictException
    {
        try {
            $callback();
        } catch (CatalogConflictException $exception) {
            return $exception;
        }
        self::fail('Expected CatalogConflictException.');
    }

    private function expectInvalid(callable $callback): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
            return;
        }
        self::fail('Expected InvalidArgumentException.');
    }

    private function createProvisioner(ConnectionFactory $connectionFactory): ProductShardProvisioner
    {
        $registry = $this->registry($connectionFactory);
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
            $connectionFactory,
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

    private function registry(ConnectionFactory $connectionFactory): ProductShardRegistry
    {
        $registry = new ProductShardRegistry();
        $registry->setConnection($connectionFactory);
        $registry->__init();
        return $registry;
    }

    private function createRegistryTable(ConnectorInterface $connector): void
    {
        $connector->query(
            'CREATE TABLE product_shard_registry ('
            . 'registry_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'website_id INTEGER NOT NULL UNIQUE, '
            . 'shard_key VARCHAR(32) NOT NULL UNIQUE, '
            . "status VARCHAR(32) NOT NULL DEFAULT 'unprovisioned', "
            . "fingerprint VARCHAR(64) NOT NULL DEFAULT '', "
            . "schema_version VARCHAR(32) NOT NULL DEFAULT '1', "
            . 'error_message TEXT NULL, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')'
        )->fetch();
    }
}
