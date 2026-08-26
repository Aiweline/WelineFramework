<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

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
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Setup\Model\Migration;
use Weline\Product\Api\Data\OfferIdentityV2;
use Weline\Product\Api\Data\ProductIdentityV2;
use Weline\Product\Api\ProductIdentityV2ResolverInterface;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;
use Weline\Product\Extends\Module\Weline_Framework\Schema\ProductShardSchemaProvider;
use Weline\Product\Model\ProductSearchProjectionStream;
use Weline\Product\Model\ProductShardKey;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\Shard\StoreOffer;
use Weline\Product\Model\Shard\StoreProduct;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Product\Service\ProductSearchProjectionService;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\ProductShardSchemaCatalog;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\Data\WebsiteSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Product-owned durable source acceptance for TEST-P3C-01/02.
 */
final class ProductSearchProjectionServiceIntegrationTest extends TestCase
{
    public function testDurableWatermarkProjectionAndStoreSelectionRollback(): void
    {
        self::assertContains(
            'sqlite',
            PDO::getAvailableDrivers(),
            'P3C-001 Product source acceptance requires pdo_sqlite.',
        );

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p3c001_product_source_'
            . bin2hex(random_bytes(8))
            . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connector = $connection->getConnector();

        try {
            $this->createRegistryTable($connector);
            $this->createProjectionStreamTable($connector);
            $provisioner = $this->provisioner($connection);
            self::assertTrue($provisioner->provisionWebsite(0)->isReady());

            $tokens = 0;
            $nextToken = static function () use (&$tokens): string {
                $tokens++;
                return str_pad(dechex($tokens), 64, '0', STR_PAD_LEFT);
            };
            $projectionMutations = new class implements ProductSearchProjectionMutationCoordinatorInterface {
                /** @var list<array{website_id:int,target_type:string,target_id:int,store_id:?int}> */
                private array $calls = [];

                public function execute(
                    ConnectionFactory $connection,
                    int $websiteId,
                    string $targetType,
                    int $targetId,
                    ?int $storeId,
                    callable $mutation,
                ): mixed {
                    $this->calls[] = [
                        'website_id' => $websiteId,
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'store_id' => $storeId,
                    ];
                    return $mutation();
                }

                /** @return list<array{website_id:int,target_type:string,target_id:int,store_id:?int}> */
                public function calls(): array
                {
                    return $this->calls;
                }
            };
            $products = new ProductRepository(
                $provisioner,
                $this->modelFactory($connection, Product::class),
                $nextToken,
                $projectionMutations,
            );
            $storeProducts = new StoreProductRepository(
                $provisioner,
                $this->modelFactory($connection, StoreProduct::class),
                $projectionMutations,
            );
            $offers = new OfferRepository(
                $provisioner,
                $this->modelFactory($connection, Offer::class),
                $nextToken,
                $projectionMutations,
            );
            $storeOffers = new StoreOfferRepository(
                $provisioner,
                $this->modelFactory($connection, StoreOffer::class),
                $offers,
                $projectionMutations,
            );
            $stream = new ProductSearchProjectionStream();
            $stream->setConnection($connection);
            $stream->__init();
            $transactions = new TransactionCoordinator();

            $website = new WebsiteSummary(0, 'Default', 'default', 'https://example.test');
            $storeA = new StoreSummary(
                11,
                0,
                'store-a',
                'Store A',
                'normal',
                true,
                true,
                'active',
                null,
            );
            $storeB = new StoreSummary(
                12,
                0,
                'store-b',
                'Store B',
                'normal',
                false,
                true,
                'active',
                null,
            );
            $channelA = new SalesChannelSummary(
                111,
                0,
                11,
                'channel-a',
                'Channel A',
                true,
                true,
                'active',
                true,
            );
            $channelB = new SalesChannelSummary(
                121,
                0,
                12,
                'channel-b',
                'Channel B',
                true,
                true,
                'active',
                true,
            );
            $websites = $this->createMock(WebsiteCatalogInterface::class);
            $websites->method('all')->willReturn([$website]);
            $stores = $this->createMock(StoreCatalogInterface::class);
            $stores->method('byWebsite')->with(0)->willReturn([$storeA, $storeB]);
            $channels = $this->createMock(SalesChannelCatalogInterface::class);
            $channels->method('byStore')->willReturnMap([
                [11, [$channelA]],
                [12, [$channelB]],
            ]);
            $identities = $this->createMock(ProductIdentityV2ResolverInterface::class);
            $identities->method('resolveProductByUuid')->willReturn(new ProductIdentityV2(
                301,
                '00000000-0000-4000-8000-000000000301',
                'P-000301',
                0,
                'builtin_configurable',
                'configurable',
                'published',
                3,
                'default_site',
            ));
            $identities->method('resolveOfferByUuid')->willReturnCallback(
                static fn(string $uuid): ?OfferIdentityV2 => match ($uuid) {
                    '00000000-0000-4000-8000-000000000401' => new OfferIdentityV2(
                        401,
                        $uuid,
                        '00000000-0000-4000-8000-000000000301',
                        'SKU-A',
                        'published',
                        4,
                    ),
                    '00000000-0000-4000-8000-000000000402' => new OfferIdentityV2(
                        402,
                        $uuid,
                        '00000000-0000-4000-8000-000000000301',
                        'SKU-B',
                        'published',
                        5,
                    ),
                    default => null,
                },
            );
            $service = new ProductSearchProjectionService(
                $products,
                $storeProducts,
                $offers,
                $storeOffers,
                $identities,
                $stream,
                $websites,
                $stores,
                $channels,
            );

            $product = $products->create(0, [
                Product::schema_fields_SKU => 'PRODUCT-301',
                Product::schema_fields_GLOBAL_PRODUCT_UUID
                    => '00000000-0000-4000-8000-000000000301',
            ]);
            $productId = (int)$product->getId();
            $products->publish(0, $productId, 0);
            $offerA = $offers->create(0, [
                Offer::schema_fields_PRODUCT_ID => $productId,
                Offer::schema_fields_GLOBAL_OFFER_UUID
                    => '00000000-0000-4000-8000-000000000401',
                Offer::schema_fields_SKU => 'SKU-A',
                Offer::schema_fields_IDENTITY_VERSION => 4,
                Offer::schema_fields_COMBINATION_KEY => 'color=red',
            ]);
            $offerB = $offers->create(0, [
                Offer::schema_fields_PRODUCT_ID => $productId,
                Offer::schema_fields_GLOBAL_OFFER_UUID
                    => '00000000-0000-4000-8000-000000000402',
                Offer::schema_fields_SKU => 'SKU-B',
                Offer::schema_fields_IDENTITY_VERSION => 5,
                Offer::schema_fields_COMBINATION_KEY => 'color=blue',
            ]);
            $offerAId = (int)$offerA->getId();
            $offerBId = (int)$offerB->getId();
            $offers->publish(0, $offerAId, 0);
            $offers->publish(0, $offerBId, 0);
            self::assertSame(
                1,
                $transactions->run($connection, static fn(): int => $stream->next(0)),
            );

            $full = $service->snapshotWebsite(0);
            self::assertSame('product.search_projection_snapshot.v1', $full['contract']);
            self::assertSame('product.offer_identity.v2', $full['identity_contract']);
            self::assertSame(1, $full['source_watermark']);
            self::assertSame(2, $full['scope_count']);
            self::assertSame(4, $full['document_count']);
            self::assertSame(
                [
                    [11, 111, 'SKU-A', '00000000-0000-4000-8000-000000000401'],
                    [11, 111, 'SKU-B', '00000000-0000-4000-8000-000000000402'],
                    [12, 121, 'SKU-A', '00000000-0000-4000-8000-000000000401'],
                    [12, 121, 'SKU-B', '00000000-0000-4000-8000-000000000402'],
                ],
                array_map(
                    static fn(array $row): array => [
                        (int)$row['store_id'],
                        (int)$row['channel_id'],
                        (string)$row['sku'],
                        (string)$row['global_offer_uuid'],
                    ],
                    $full['documents'],
                ),
            );
            self::assertSame(
                ['product_offer'],
                array_values(array_unique(array_column($full['documents'], 'entity_type'))),
            );
            self::assertSame('builtin_configurable', $full['documents'][0]['provider_code']);
            self::assertSame('configurable', $full['documents'][0]['product_type']);
            self::assertSame('product/' . $productId, $full['documents'][0]['url']);

            $storeOffers->select(0, 11, $offerBId, false);
            $afterOfferSelection = $service->snapshotWebsite(0);
            self::assertSame(3, $afterOfferSelection['document_count']);
            self::assertSame(
                ['SKU-A'],
                array_values(array_map(
                    static fn(array $row): string => (string)$row['sku'],
                    array_values(array_filter(
                        $afterOfferSelection['documents'],
                        static fn(array $row): bool => (int)$row['store_id'] === 11,
                    )),
                )),
            );

            $storeProducts->select(0, 12, $productId, false);
            self::assertSame(
                2,
                $transactions->run($connection, static fn(): int => $stream->next(0)),
            );
            $change = $service->projectChange([
                'website_id' => 0,
                'event_seq' => 2,
                'target_type' => 'store_product',
                'target_id' => $productId,
                'store_id' => 12,
            ]);
            self::assertSame('product.search_projection_change.v1', $change['contract']);
            self::assertSame([], $change['documents']);
            self::assertCount(3, $change['delete_keys']);
            self::assertContains('product', array_column($change['delete_keys'], 'entity_type'));
            self::assertSame(
                2,
                count(array_filter(
                    $change['delete_keys'],
                    static fn(array $row): bool => $row['entity_type'] === 'product_offer',
                )),
            );
            foreach ($change['delete_keys'] as $deleteKey) {
                self::assertSame(12, (int)$deleteKey['store_id']);
                self::assertSame(121, (int)$deleteKey['channel_id']);
            }

            $afterSelection = $service->snapshotWebsite(0);
            self::assertSame(2, $afterSelection['source_watermark']);
            self::assertSame(1, $afterSelection['document_count']);
            self::assertSame(11, (int)$afterSelection['documents'][0]['store_id']);
            self::assertSame(111, (int)$afterSelection['documents'][0]['channel_id']);
            self::assertSame('SKU-A', (string)$afterSelection['documents'][0]['sku']);

            $offerC = $offers->create(0, [
                Offer::schema_fields_PRODUCT_ID => $productId,
                Offer::schema_fields_GLOBAL_OFFER_UUID
                    => '00000000-0000-4000-8000-000000000403',
                Offer::schema_fields_SKU => 'SKU-C',
                Offer::schema_fields_IDENTITY_VERSION => 6,
                Offer::schema_fields_COMBINATION_KEY => 'color=green',
            ]);
            $offerCId = (int)$offerC->getId();
            $offers->updateVersioned(0, $offerCId, 0, [
                Offer::schema_fields_REQUIRES_SHIPPING => 0,
            ]);
            $offers->transition(0, $offerCId, 1, Offer::STATUS_DISABLED);
            $storeOffers->select(0, 12, $offerCId, false);
            $products->updateVersioned(0, $productId, 1, [
                Product::schema_fields_SKU => 'PRODUCT-301-UPDATED',
            ]);
            $products->transition(0, $productId, 2, 'disabled');

            self::assertSame(
                [
                    ['website_id' => 0, 'target_type' => 'product', 'target_id' => $productId, 'store_id' => null],
                    ['website_id' => 0, 'target_type' => 'product', 'target_id' => $productId, 'store_id' => null],
                    ['website_id' => 0, 'target_type' => 'product', 'target_id' => $productId, 'store_id' => null],
                    ['website_id' => 0, 'target_type' => 'product', 'target_id' => $productId, 'store_id' => null],
                    ['website_id' => 0, 'target_type' => 'product', 'target_id' => $productId, 'store_id' => null],
                    ['website_id' => 0, 'target_type' => 'store_product', 'target_id' => $productId, 'store_id' => 11],
                    ['website_id' => 0, 'target_type' => 'store_product', 'target_id' => $productId, 'store_id' => 12],
                    ['website_id' => 0, 'target_type' => 'product', 'target_id' => $productId, 'store_id' => null],
                    ['website_id' => 0, 'target_type' => 'product', 'target_id' => $productId, 'store_id' => null],
                    ['website_id' => 0, 'target_type' => 'product', 'target_id' => $productId, 'store_id' => null],
                    ['website_id' => 0, 'target_type' => 'store_product', 'target_id' => $productId, 'store_id' => 12],
                    ['website_id' => 0, 'target_type' => 'product', 'target_id' => $productId, 'store_id' => null],
                    ['website_id' => 0, 'target_type' => 'product', 'target_id' => $productId, 'store_id' => null],
                ],
                $projectionMutations->calls(),
            );

            try {
                $transactions->run($connection, static function () use ($stream): void {
                    self::assertSame(3, $stream->next(0));
                    throw new \RuntimeException('force_rollback');
                });
                self::fail('Expected forced rollback.');
            } catch (\RuntimeException $exception) {
                self::assertSame('force_rollback', $exception->getMessage());
            }
            self::assertSame(2, $stream->current(0));
        } finally {
            $connector->close();
            $connection->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }

        self::assertFileDoesNotExist($dbPath);
    }

    /**
     * @param class-string<AbstractWebsiteShardModel> $class
     * @return \Closure(int): AbstractWebsiteShardModel
     */
    private function modelFactory(
        ConnectionFactory $connection,
        string $class,
    ): \Closure {
        return static function (int $websiteId) use (
            $connection,
            $class,
        ): AbstractWebsiteShardModel {
            $model = new $class();
            $model->setConnection($connection);
            $model->__init();

            return $model->forWebsite($websiteId);
        };
    }

    private function provisioner(ConnectionFactory $connection): ProductShardProvisioner
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
            . ')',
        )->fetch();
    }

    private function createProjectionStreamTable(ConnectorInterface $connector): void
    {
        $connector->query(
            'CREATE TABLE product_search_projection_stream ('
            . 'stream_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'website_id INTEGER NOT NULL UNIQUE, '
            . 'event_seq INTEGER NOT NULL DEFAULT 0, '
            . "cas_token VARCHAR(64) NOT NULL DEFAULT '', "
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')',
        )->fetch();
    }
}
