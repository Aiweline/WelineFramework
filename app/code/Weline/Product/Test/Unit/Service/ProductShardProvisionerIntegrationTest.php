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
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Setup\Model\Migration;
use Weline\Product\Extends\Module\Weline_Framework\Schema\ProductShardSchemaProvider;
use Weline\Product\Model\ProductShardKey;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\ProductShardSchemaCatalog;

final class ProductShardProvisionerIntegrationTest extends TestCase
{
    public function testWebsiteZeroCreatesExactlyNineTablesAndRepeatPreservesDataWithoutDdl(): void
    {
        self::assertContains(
            'sqlite',
            PDO::getAvailableDrivers(),
            'P2A-002 acceptance requires pdo_sqlite.',
        );

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p2a002_product_'
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
        $registry = new ProductShardRegistry();
        $registry->setConnection($connectionFactory);
        $registry->__init();
        $catalog = new ProductShardSchemaCatalog();
        $provider = new ProductShardSchemaProvider($registry, $catalog);
        $familyRegistry = new ShardSchemaFamilyProviderRegistry(
            manualFamilyProviders: [ProductShardKey::FAMILY_CODE => $provider],
            scanExtends: false,
        );
        $migration = $this->createMock(Migration::class);
        $ddlCount = 0;
        $migration->method('recordSchemaDdl')->willReturnCallback(
            static function () use (&$ddlCount): int {
                $ddlCount++;
                return $ddlCount;
            },
        );
        $migration->method('updateStatus')->willReturn(true);
        $genericProvisioner = new ShardSchemaProvisioner(
            $connectionFactory,
            $familyRegistry,
            new DbSchemaReader(),
            new SchemaDiffEngine(),
            new SchemaMigrationExecutor(
                $this->createMock(EventsManager::class),
                $migration,
            ),
        );
        $provisioner = new ProductShardProvisioner($registry, $genericProvisioner, $catalog);

        try {
            $first = $provisioner->provisionWebsite(0);
            self::assertTrue($first->isReady(), (string)$first->errorMessage);
            self::assertNotSame('', $first->fingerprint);
            self::assertSame(ProductShardRegistry::STATUS_READY, $registry->getStatus(0));
            self::assertSame(ProductShardSchemaCatalog::SCHEMA_VERSION, $registry->getSchemaVersion(0));
            self::assertTrue($provisioner->isWritable(0));
            self::assertCount(9, $first->tableNames);
            self::assertCount(9, $first->tableFingerprints);
            self::assertCount(9, $first->ops);
            self::assertSame(9, $ddlCount);

            $expectedTables = array_map(
                static fn(string $entity): string => ProductShardKey::tableName('0', $entity),
                ProductShardSchemaCatalog::ENTITIES,
            );
            self::assertSame($expectedTables, $first->tableNames);
            foreach ($expectedTables as $tableName) {
                self::assertTrue($connector->tableExist($tableName), $tableName);
            }

            $connector->query(
                "INSERT INTO product_ws_0_product (sku, global_product_uuid)"
                . " VALUES ('P2A002-SKU', '00000000-0000-0000-0000-000000000002')"
            )->fetch();

            $second = $provisioner->provisionWebsite(0);
            self::assertTrue($second->isReady(), (string)$second->errorMessage);
            self::assertSame($first->fingerprint, $second->fingerprint);
            self::assertSame(9, $ddlCount, 'Current ready schema must not execute DDL again.');

            $rows = $connector->query(
                "SELECT sku FROM product_ws_0_product WHERE sku = 'P2A002-SKU'"
            )->fetch();
            self::assertSame('P2A002-SKU', (string)($rows[0]['sku'] ?? ''));
        } finally {
            $connector->close();
            $connectionFactory->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }

        self::assertFileDoesNotExist($dbPath);
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
