<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

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
use Weline\Search\Extends\Module\Weline_Framework\Schema\SearchShardSchemaProvider;
use Weline\Search\Model\SearchShardKey;
use Weline\Search\Model\SearchShardRegistry;
use Weline\Search\Model\Shard\AbstractSearchWebsiteShardModel;
use Weline\Search\Model\Shard\SearchAppliedEvent;
use Weline\Search\Model\Shard\SearchDocument;
use Weline\Search\Model\Shard\SearchWatermark;
use Weline\Search\Service\ArrayProductSearchProjectionSource;
use Weline\Search\Service\DatabaseSearchIndexStore;
use Weline\Search\Service\SearchIndexBuilder;
use Weline\Search\Service\SearchIndexIncrementalApplier;
use Weline\Search\Service\SearchShardProvisioner;
use Weline\Search\Service\SearchShardSchemaCatalog;

/**
 * TEST-P3C-01/02 durable acceptance on a real SQLite shard.
 */
final class SearchShardPersistenceIntegrationTest extends TestCase
{
    public function testProvisionBuildIncrementalReplayAndScopeIsolationAreDurable(): void
    {
        self::assertContains(
            'sqlite',
            PDO::getAvailableDrivers(),
            'P3C-001 acceptance requires pdo_sqlite.',
        );

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p3c001_search_'
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
            $registry = new SearchShardRegistry();
            $registry->setConnection($connection);
            $registry->__init();
            $catalog = new SearchShardSchemaCatalog();
            $provider = new SearchShardSchemaProvider($registry, $catalog);
            $familyRegistry = new ShardSchemaFamilyProviderRegistry(
                manualFamilyProviders: [SearchShardKey::FAMILY_CODE => $provider],
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
            $provisioner = new SearchShardProvisioner($registry, $genericProvisioner);

            $source = ArrayProductSearchProjectionSource::forTesting();
            $source->seedSnapshot(0, [
                $this->document('301', 2, 11, 111, 'A-v2', 'SAME-SKU'),
                $this->document('301', 2, 12, 121, 'B-v2', 'SAME-SKU'),
            ], 2);
            $store = new DatabaseSearchIndexStore(
                $this->model($connection, SearchDocument::class),
                $this->model($connection, SearchWatermark::class),
                $this->model($connection, SearchAppliedEvent::class),
                new TransactionCoordinator(),
            );
            $builder = new SearchIndexBuilder(
                $registry,
                $store,
                $source,
                $catalog,
                $provisioner,
            );
            $built = $builder->rebuildWebsite(0);

            self::assertTrue($built['ok']);
            self::assertSame(SearchShardRegistry::STATUS_READY, $registry->getStatus(0));
            self::assertSame(SearchShardSchemaCatalog::SCHEMA_VERSION, $registry->getSchemaVersion(0));
            self::assertSame($registry->getFingerprint(0), $built['fingerprint']);
            self::assertSame(
                $registry->getFingerprint(0),
                $built['watermark']['shard_fingerprint'],
            );
            self::assertSame(3, $ddlCount);
            foreach (SearchShardSchemaCatalog::ENTITIES as $entity) {
                self::assertTrue(
                    $connector->tableExist(SearchShardKey::tableName('0', $entity)),
                    $entity,
                );
            }
            self::assertSame(2, $built['source_watermark']);
            self::assertSame(1, $built['generation']);
            self::assertSame(2, $built['document_count']);
            self::assertSame('A-v2', $store->documentsForScope(0, 11, 111)[0]['title']);
            self::assertSame('B-v2', $store->documentsForScope(0, 12, 121)[0]['title']);

            $rebuilt = $builder->rebuildWebsite(0);
            self::assertTrue($rebuilt['ok']);
            self::assertSame(2, $rebuilt['generation']);
            self::assertSame($registry->getFingerprint(0), $rebuilt['fingerprint']);
            self::assertSame(3, $ddlCount);

            $identity = $this->identity('301', 11, 111);
            $current = $this->document('301', 4, 11, 111, 'A-v4', 'SAME-SKU');
            $source->seedChange(0, 3, [
                'documents' => [$current],
                'delete_keys' => [$identity],
                'source_watermark' => 4,
            ]);
            $source->seedChange(0, 4, [
                'documents' => [$current],
                'delete_keys' => [$identity],
                'source_watermark' => 4,
            ]);
            $applier = new SearchIndexIncrementalApplier($registry, $store, $source);

            $four = $applier->apply($this->event(4, 'evt-4'));
            self::assertSame(2, (int)$four['watermark']['incremental_watermark']);
            self::assertSame('A-v4', $store->documentsForScope(0, 11, 111)[0]['title']);

            $three = $applier->apply($this->event(3, 'evt-3'));
            self::assertSame(4, (int)$three['watermark']['incremental_watermark']);
            self::assertSame('A-v4', $store->documentsForScope(0, 11, 111)[0]['title']);
            self::assertSame('B-v2', $store->documentsForScope(0, 12, 121)[0]['title']);

            $replayed = $applier->apply($this->event(4, 'evt-4'));
            self::assertTrue($replayed['replayed']);
            self::assertFalse($replayed['applied']);
            self::assertSame(4, (int)$replayed['watermark']['incremental_watermark']);

            $second = $provisioner->provisionWebsite(0);
            self::assertTrue($second->isReady(), (string)$second->errorMessage);
            self::assertSame(3, $ddlCount, 'Ready schema must not execute DDL again.');
            self::assertSame('A-v4', $store->documentsForScope(0, 11, 111)[0]['title']);
        } finally {
            $connector->close();
            $connection->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }

        self::assertFileDoesNotExist($dbPath);
    }

    /** @param class-string<AbstractSearchWebsiteShardModel> $class */
    private function model(
        ConnectionFactory $connection,
        string $class,
    ): AbstractSearchWebsiteShardModel {
        $model = new $class();
        $model->setConnection($connection);
        $model->__init();

        return $model;
    }

    /** @return array<string,mixed> */
    private function document(
        string $entityId,
        int $version,
        int $storeId,
        int $channelId,
        string $title,
        string $sku,
    ): array {
        return $this->identity($entityId, $storeId, $channelId) + [
            'sku' => $sku,
            'title' => $title,
            'status' => 'published',
            'document_version' => $version,
        ];
    }

    /** @return array<string,mixed> */
    private function identity(string $entityId, int $storeId, int $channelId): array
    {
        return [
            'entity_type' => 'product',
            'entity_id' => $entityId,
            'website_id' => 0,
            'website_code' => 'default',
            'store_id' => $storeId,
            'store_code' => 'store-' . $storeId,
            'channel_id' => $channelId,
            'channel_code' => 'channel-' . $channelId,
            'locale' => '',
            'currency' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function event(int $sequence, string $idempotencyKey): array
    {
        return [
            'website_id' => 0,
            'event_seq' => $sequence,
            'idempotency_key' => $idempotencyKey,
            'target_type' => 'product',
            'target_id' => 301,
        ];
    }

    private function createRegistryTable(ConnectorInterface $connector): void
    {
        $connector->query(
            'CREATE TABLE search_shard_registry ('
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
}
