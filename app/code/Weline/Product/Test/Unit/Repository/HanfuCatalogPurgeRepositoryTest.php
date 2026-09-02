<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
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
use Weline\Product\Model\Shard\CategoryLink;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Price;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\Shard\StoreOffer;
use Weline\Product\Model\Shard\StoreProduct;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\ProductShardSchemaCatalog;
use Weline\Product\Service\StorefrontCategoryLinkIndex;

final class HanfuCatalogPurgeRepositoryTest extends TestCase
{
    public function testBoundedPurgeNormalizesIdsPreservesOtherRowsAndMaintainsMediaOwnership(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        $missingMethods = [];
        foreach ([
            ProductRepository::class => 'deleteByIds',
            OfferRepository::class => 'deleteByProductIds',
            PriceRepository::class => 'purgeOfferIds',
            StoreOfferRepository::class => 'purgeOfferIds',
            StoreProductRepository::class => 'purgeProductIds',
            CategoryLinkRepository::class => 'purgeProductIds',
            MediaRepository::class => 'countByBlobKey',
        ] as $class => $method) {
            if (!method_exists($class, $method)) {
                $missingMethods[] = $class . '::' . $method;
            }
        }
        self::assertSame([], $missingMethods, 'Missing bounded purge methods.');

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_hanfu_catalog_purge_'
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

            $products = new ProductRepository(
                $provisioner,
                $this->modelFactory($connectionFactory, Product::class),
                $tokens,
            );
            $offers = new OfferRepository(
                $provisioner,
                $this->modelFactory($connectionFactory, Offer::class),
                $tokens,
            );
            $prices = new PriceRepository(
                $provisioner,
                new CatalogOverlayResolver(),
                $this->modelFactory($connectionFactory, Price::class),
            );
            $storeOffers = new StoreOfferRepository(
                $provisioner,
                $this->modelFactory($connectionFactory, StoreOffer::class),
            );
            $storeProducts = new StoreProductRepository(
                $provisioner,
                $this->modelFactory($connectionFactory, StoreProduct::class),
            );
            $categoryLinks = new CategoryLinkRepository(
                $provisioner,
                $this->modelFactory($connectionFactory, CategoryLink::class),
            );
            $media = new MediaRepository(
                $provisioner,
                $connectionFactory,
                new DatabaseTransactionRunner(new TransactionCoordinator()),
                $this->modelFactory($connectionFactory, Media::class),
                $tokens,
            );

            $product1 = $products->create(0, [
                Product::schema_fields_SKU => 'HANFU-PURGE-1',
                Product::schema_fields_GLOBAL_PRODUCT_UUID => '20000000-0000-4000-8000-000000000001',
            ]);
            $product2 = $products->create(0, [
                Product::schema_fields_SKU => 'HANFU-PURGE-2',
                Product::schema_fields_GLOBAL_PRODUCT_UUID => '20000000-0000-4000-8000-000000000002',
            ]);
            $product99 = $products->create(0, [
                Product::schema_fields_SKU => 'HANFU-KEEP-99',
                Product::schema_fields_GLOBAL_PRODUCT_UUID => '20000000-0000-4000-8000-000000000099',
            ]);
            $otherWebsiteProduct = $products->create(7, [
                Product::schema_fields_SKU => 'HANFU-KEEP-W7',
                Product::schema_fields_GLOBAL_PRODUCT_UUID => '20000000-0000-4000-8000-000000000701',
            ]);
            $productIds = [(int)$product1->getId(), (int)$product2->getId()];

            $offer1 = $offers->create(0, [
                Offer::schema_fields_PRODUCT_ID => $productIds[0],
                Offer::schema_fields_GLOBAL_OFFER_UUID => '30000000-0000-4000-8000-000000000001',
            ]);
            $offer2 = $offers->create(0, [
                Offer::schema_fields_PRODUCT_ID => $productIds[1],
                Offer::schema_fields_GLOBAL_OFFER_UUID => '30000000-0000-4000-8000-000000000002',
            ]);
            $offer99 = $offers->create(0, [
                Offer::schema_fields_PRODUCT_ID => (int)$product99->getId(),
                Offer::schema_fields_GLOBAL_OFFER_UUID => '30000000-0000-4000-8000-000000000099',
            ]);
            $offerIds = [(int)$offer1->getId(), (int)$offer2->getId()];

            foreach ([...$offerIds, (int)$offer99->getId()] as $offerId) {
                $prices->writeExplicit(0, 0, $offerId, 'CNY', 10000 + $offerId);
                $storeOffers->select(0, 3, $offerId);
            }
            foreach ([...$productIds, (int)$product99->getId()] as $productId) {
                $storeProducts->select(0, 3, $productId);
                $categoryLinks->link(0, 100 + $productId, $productId);
            }

            $sharedOwner = $media->create(0, [
                Media::schema_fields_PRODUCT_ID => $productIds[0],
                Media::schema_fields_PATH => '/media/hanfu-shared.jpg',
                Media::schema_fields_BLOB_KEY => 'hanfu-shared-key',
            ]);
            $sharedCopy = $media->shareCopy(
                0,
                (int)$sharedOwner->getId(),
                $productIds[1],
                2,
            );
            $media->create(0, [
                Media::schema_fields_PRODUCT_ID => (int)$product99->getId(),
                Media::schema_fields_PATH => '/media/hanfu-exclusive.jpg',
                Media::schema_fields_BLOB_KEY => 'hanfu-exclusive-key',
            ]);
            $media->create(7, [
                Media::schema_fields_PRODUCT_ID => (int)$otherWebsiteProduct->getId(),
                Media::schema_fields_PATH => '/media/hanfu-shared-w7.jpg',
                Media::schema_fields_BLOB_KEY => 'hanfu-shared-key',
            ]);

            self::assertSame(2, $media->countByBlobKey(0, 'hanfu-shared-key'));
            self::assertSame(1, $media->countByBlobKey(0, 'hanfu-exclusive-key'));
            self::assertSame(1, $media->countByBlobKey(7, 'hanfu-shared-key'));
            self::assertSame(0, $media->countByBlobKey(0, '  '));

            self::assertSame(0, $products->deleteByIds(0, []));
            self::assertSame(
                ['deleted' => 0, 'offer_ids' => [], 'offer_uuids' => []],
                $offers->deleteByProductIds(0, []),
            );
            self::assertSame(0, $prices->purgeOfferIds(0, []));
            self::assertSame(0, $storeOffers->purgeOfferIds(0, []));
            self::assertSame(0, $storeProducts->purgeProductIds(0, []));
            self::assertSame(0, $categoryLinks->purgeProductIds(0, []));

            foreach ([
                static fn() => $products->deleteByIds(-1, [1]),
                static fn() => $offers->deleteByProductIds(-1, [1]),
                static fn() => $prices->purgeOfferIds(-1, [1]),
                static fn() => $storeOffers->purgeOfferIds(-1, [1]),
                static fn() => $storeProducts->purgeProductIds(-1, [1]),
                static fn() => $categoryLinks->purgeProductIds(-1, [1]),
                static fn() => $media->countByBlobKey(-1, 'key'),
            ] as $callback) {
                $this->assertInvalidArgument($callback);
            }

            $normalizedOfferIds = [$offerIds[1], $offerIds[0], $offerIds[1], -1, 0];
            $normalizedProductIds = [$productIds[1], $productIds[0], $productIds[1], -1, 0];
            self::assertSame(2, $prices->purgeOfferIds(0, $normalizedOfferIds));
            self::assertSame(2, $storeOffers->purgeOfferIds(0, $normalizedOfferIds));
            self::assertSame(2, $storeProducts->purgeProductIds(0, $normalizedProductIds));

            $pool = $this->createMock(CachePoolInterface::class);
            $pool->expects(self::once())->method('delete')->willReturn(true);
            $cacheManager = new class($pool) extends CacheManager {
                public function __construct(private readonly CachePoolInterface $pool)
                {
                }

                public function pool(string $identity): CachePoolInterface
                {
                    return $this->pool;
                }
            };
            $indexedCategoryLinks = new CategoryLinkRepository(
                $provisioner,
                $this->modelFactory($connectionFactory, CategoryLink::class),
                new StorefrontCategoryLinkIndex(
                    new StorefrontScopeHotCache($cacheManager),
                    $provisioner,
                ),
            );
            self::assertSame(2, $indexedCategoryLinks->purgeProductIds(0, $normalizedProductIds));

            $media->remove(0, (int)$sharedCopy->getId());
            self::assertSame(1, $media->countByBlobKey(0, 'hanfu-shared-key'));
            $media->remove(0, (int)$sharedOwner->getId());
            self::assertSame(0, $media->countByBlobKey(0, 'hanfu-shared-key'));

            $offerResult = $offers->deleteByProductIds(0, $normalizedProductIds);
            self::assertSame(2, $offerResult['deleted']);
            self::assertSame($offerIds, $offerResult['offer_ids']);
            self::assertSame([
                '30000000-0000-4000-8000-000000000001',
                '30000000-0000-4000-8000-000000000002',
            ], $offerResult['offer_uuids']);
            self::assertSame(2, $products->deleteByIds(0, $normalizedProductIds));

            self::assertNull($products->findById(0, $productIds[0]));
            self::assertNull($offers->findById(0, $offerIds[0]));
            self::assertNotNull($products->findById(0, (int)$product99->getId()));
            self::assertNotNull($offers->findById(0, (int)$offer99->getId()));
            self::assertSame(
                10000 + (int)$offer99->getId(),
                $prices->read(0, 0, (int)$offer99->getId(), 'CNY')->value,
            );
            self::assertNotNull($storeOffers->find(0, 3, (int)$offer99->getId()));
            self::assertNotNull($storeProducts->find(0, 3, (int)$product99->getId()));
            self::assertCount(
                1,
                $categoryLinks->listByProductIds(0, [(int)$product99->getId()], [0]),
            );
            self::assertSame(1, $media->countByBlobKey(0, 'hanfu-exclusive-key'));
            self::assertNotNull($products->findById(7, (int)$otherWebsiteProduct->getId()));
            self::assertSame(1, $media->countByBlobKey(7, 'hanfu-shared-key'));
        } finally {
            $connector->close();
            $connectionFactory->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }

        self::assertFileDoesNotExist($dbPath);
    }

    private function assertInvalidArgument(callable $callback): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
            return;
        }
        self::fail('Expected InvalidArgumentException.');
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

    /** @param class-string<AbstractWebsiteShardModel> $class */
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

    /** @return \Closure(): string */
    private function tokenFactory(): \Closure
    {
        $sequence = 0;
        return static function () use (&$sequence): string {
            $sequence++;
            return str_pad(dechex($sequence), 64, '0', STR_PAD_LEFT);
        };
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
