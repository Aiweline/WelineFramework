<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Sample\HanfuCleanup;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Product\Model\OfferIdentityRegistry;
use Weline\Product\Model\ProductIdentityRegistry;
use Weline\Product\Model\ProductShardKey;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\SkuRegistry;
use Weline\Product\Sample\HanfuCleanup\HanfuIdentityCleanupService;

final class HanfuIdentityCleanupServiceTest extends TestCase
{
    public function testCrossWebsiteAndReferenceCountIdentitiesArePreserved(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        self::assertTrue(
            class_exists(HanfuIdentityCleanupService::class),
            'Missing Hanfu identity cleanup service.',
        );

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_hanfu_identity_cleanup_'
            . bin2hex(random_bytes(8))
            . '.sqlite';
        $connectionFactory = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connector = $connectionFactory->getConnector();
        $this->createTables($connector);
        $this->insertFixtures($connector);

        $service = new HanfuIdentityCleanupService(
            $connectionFactory,
            new DatabaseTransactionRunner(new TransactionCoordinator()),
            productRegistryFactory: $this->modelFactory($connectionFactory, ProductIdentityRegistry::class),
            offerRegistryFactory: $this->modelFactory($connectionFactory, OfferIdentityRegistry::class),
            skuRegistryFactory: $this->modelFactory($connectionFactory, SkuRegistry::class),
            shardRegistryFactory: $this->modelFactory($connectionFactory, ProductShardRegistry::class),
            shardProductFactory: $this->modelFactory($connectionFactory, Product::class),
            shardOfferFactory: $this->modelFactory($connectionFactory, Offer::class),
        );

        try {
            self::assertSame([
                'product_registry' => 0,
                'offer_registry' => 0,
                'sku_registry' => 0,
                'protected_references' => [],
            ], $service->preview([], []));
            self::assertSame([
                'product_registry' => 0,
                'offer_registry' => 0,
                'sku_registry' => 0,
            ], $service->purgeUnreferenced([], []));

            $before = $this->counts($connector);
            $preview = $service->preview(
                ['product-orphan', 'product-shared', 'product-ref', 'product-orphan', ''],
                ['offer-orphan', 'offer-shared', 'offer-ref', 'offer-orphan', ' '],
            );
            self::assertSame(1, $preview['product_registry']);
            self::assertSame(1, $preview['offer_registry']);
            self::assertSame(1, $preview['sku_registry']);
            self::assertSame($before, $this->counts($connector), 'Preview must be zero-write.');

            $protected = [];
            foreach ($preview['protected_references'] as $reference) {
                $protected[] = (string)$reference['reference_type']
                    . ':'
                    . (string)$reference['uuid'];
            }
            self::assertContains('product_shard:product-shared', $protected);
            self::assertContains('offer_shard:offer-shared', $protected);
            self::assertContains('sku_registry_ref_count:offer-ref', $protected);
            self::assertContains('dependent_offer_identity:product-shared', $protected);
            self::assertContains('dependent_offer_identity:product-ref', $protected);

            self::assertSame([
                'product_registry' => 1,
                'offer_registry' => 1,
                'sku_registry' => 1,
            ], $service->purgeUnreferenced(
                ['product-ref', 'product-shared', 'product-orphan'],
                ['offer-ref', 'offer-shared', 'offer-orphan'],
            ));

            self::assertFalse($this->rowExists(
                $connector,
                ProductIdentityRegistry::schema_table,
                ProductIdentityRegistry::schema_fields_UUID,
                'product-orphan',
            ));
            self::assertFalse($this->rowExists(
                $connector,
                OfferIdentityRegistry::schema_table,
                OfferIdentityRegistry::schema_fields_UUID,
                'offer-orphan',
            ));
            self::assertFalse($this->rowExists(
                $connector,
                SkuRegistry::schema_table,
                SkuRegistry::schema_fields_GLOBAL_OFFER_UUID,
                'offer-orphan',
            ));

            foreach (['product-shared', 'product-ref', 'product-keep'] as $uuid) {
                self::assertTrue($this->rowExists(
                    $connector,
                    ProductIdentityRegistry::schema_table,
                    ProductIdentityRegistry::schema_fields_UUID,
                    $uuid,
                ));
            }
            foreach (['offer-shared', 'offer-ref', 'offer-keep'] as $uuid) {
                self::assertTrue($this->rowExists(
                    $connector,
                    OfferIdentityRegistry::schema_table,
                    OfferIdentityRegistry::schema_fields_UUID,
                    $uuid,
                ));
                self::assertTrue($this->rowExists(
                    $connector,
                    SkuRegistry::schema_table,
                    SkuRegistry::schema_fields_GLOBAL_OFFER_UUID,
                    $uuid,
                ));
            }

            self::assertSame([
                'product_registry' => 0,
                'offer_registry' => 0,
                'sku_registry' => 0,
            ], $service->purgeUnreferenced(
                ['product-ref', 'product-shared', 'product-orphan'],
                ['offer-ref', 'offer-shared', 'offer-orphan'],
            ));
        } finally {
            $connector->close();
            $connectionFactory->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }

        self::assertFileDoesNotExist($dbPath);
    }

    /** @param class-string<Model> $class @return \Closure(): Model */
    private function modelFactory(ConnectionFactory $connectionFactory, string $class): \Closure
    {
        return static function () use ($connectionFactory, $class): Model {
            $model = new $class();
            $model->setConnection($connectionFactory);
            $model->__init();
            return $model;
        };
    }

    private function createTables(ConnectorInterface $connector): void
    {
        $connector->query(
            'CREATE TABLE ' . ProductShardRegistry::schema_table . ' ('
            . 'registry_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'website_id INTEGER NOT NULL UNIQUE, shard_key VARCHAR(32) NOT NULL UNIQUE)'
        )->fetch();
        $connector->query(
            'CREATE TABLE ' . ProductIdentityRegistry::schema_table . ' ('
            . 'registry_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'global_product_uuid VARCHAR(36) NOT NULL UNIQUE)'
        )->fetch();
        $connector->query(
            'CREATE TABLE ' . OfferIdentityRegistry::schema_table . ' ('
            . 'registry_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'global_offer_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'global_product_uuid VARCHAR(36) NOT NULL, sku VARCHAR(128) NOT NULL UNIQUE)'
        )->fetch();
        $connector->query(
            'CREATE TABLE ' . SkuRegistry::schema_table . ' ('
            . 'registry_id INTEGER PRIMARY KEY AUTOINCREMENT, sku VARCHAR(128) NOT NULL UNIQUE, '
            . 'global_product_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'global_offer_uuid VARCHAR(36) NOT NULL UNIQUE, ref_count INTEGER NOT NULL DEFAULT 0)'
        )->fetch();

        foreach ([0, 2] as $websiteId) {
            $shardKey = ProductShardKey::fromWebsiteId($websiteId);
            $connector->query(
                'CREATE TABLE ' . ProductShardKey::tableName($shardKey, Product::entityCode()) . ' ('
                . 'product_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'global_product_uuid VARCHAR(36) NOT NULL)'
            )->fetch();
            $connector->query(
                'CREATE TABLE ' . ProductShardKey::tableName($shardKey, Offer::entityCode()) . ' ('
                . 'offer_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'global_offer_uuid VARCHAR(36) NOT NULL, sku VARCHAR(128) NOT NULL)'
            )->fetch();
        }
    }

    private function insertFixtures(ConnectorInterface $connector): void
    {
        $connector->query(
            'INSERT INTO ' . ProductShardRegistry::schema_table
            . " (website_id, shard_key) VALUES (0, '0'), (2, '2')"
        )->fetch();

        foreach (['shared', 'orphan', 'ref', 'keep'] as $suffix) {
            $connector->query(
                'INSERT INTO ' . ProductIdentityRegistry::schema_table
                . " (global_product_uuid) VALUES ('product-{$suffix}')"
            )->fetch();
            $connector->query(
                'INSERT INTO ' . OfferIdentityRegistry::schema_table
                . " (global_offer_uuid, global_product_uuid, sku) VALUES "
                . "('offer-{$suffix}', 'product-{$suffix}', 'SKU-" . strtoupper($suffix) . "')"
            )->fetch();
            $refCount = $suffix === 'ref' ? 2 : 0;
            $connector->query(
                'INSERT INTO ' . SkuRegistry::schema_table
                . " (sku, global_product_uuid, global_offer_uuid, ref_count) VALUES "
                . "('SKU-" . strtoupper($suffix) . "', 'product-{$suffix}', 'offer-{$suffix}', {$refCount})"
            )->fetch();
        }

        $connector->query(
            'INSERT INTO ' . ProductShardKey::tableName('2', Product::entityCode())
            . " (global_product_uuid) VALUES ('product-shared')"
        )->fetch();
        $connector->query(
            'INSERT INTO ' . ProductShardKey::tableName('2', Offer::entityCode())
            . " (global_offer_uuid, sku) VALUES ('offer-shared', 'SKU-SHARED')"
        )->fetch();
        $connector->query(
            'INSERT INTO ' . ProductShardKey::tableName('0', Product::entityCode())
            . " (global_product_uuid) VALUES ('product-keep')"
        )->fetch();
        $connector->query(
            'INSERT INTO ' . ProductShardKey::tableName('0', Offer::entityCode())
            . " (global_offer_uuid, sku) VALUES ('offer-keep', 'SKU-KEEP')"
        )->fetch();
    }

    /** @return array<string, int> */
    private function counts(ConnectorInterface $connector): array
    {
        return [
            'product_registry' => $this->countRows($connector, ProductIdentityRegistry::schema_table),
            'offer_registry' => $this->countRows($connector, OfferIdentityRegistry::schema_table),
            'sku_registry' => $this->countRows($connector, SkuRegistry::schema_table),
            'product_ws_0' => $this->countRows($connector, ProductShardKey::tableName('0', 'product')),
            'offer_ws_0' => $this->countRows($connector, ProductShardKey::tableName('0', 'offer')),
            'product_ws_2' => $this->countRows($connector, ProductShardKey::tableName('2', 'product')),
            'offer_ws_2' => $this->countRows($connector, ProductShardKey::tableName('2', 'offer')),
        ];
    }

    private function countRows(ConnectorInterface $connector, string $table): int
    {
        $rows = $connector->query('SELECT COUNT(*) AS total FROM ' . $table)->fetch();
        return (int)($rows[0]['total'] ?? 0);
    }

    private function rowExists(
        ConnectorInterface $connector,
        string $table,
        string $field,
        string $value,
    ): bool {
        $quoted = $connector->getConnectionInterface()->quote($value);
        $rows = $connector->query(
            'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE ' . $field . ' = ' . $quoted
        )->fetch();
        return (int)($rows[0]['total'] ?? 0) > 0;
    }
}
