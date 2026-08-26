<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Product\Api\ProductIdentityCutoverPolicyInterface;
use Weline\Product\Model\SkuAlias;
use Weline\Product\Model\SkuRegistry;
use Weline\Product\Service\SkuIdentityConflictException;
use Weline\Product\Service\SkuRegistryService;

final class SkuRegistryServiceIntegrationTest extends TestCase
{
    public function testRealSqliteIdentityLifecycleIsStableAndNonReusable(): void
    {
        self::assertContains(
            'sqlite',
            PDO::getAvailableDrivers(),
            'P2A-003 acceptance requires pdo_sqlite.',
        );

        $schema = (new SchemaParser())->parse(SkuRegistry::class);
        self::assertNotNull($schema);
        $requestHashColumn = null;
        foreach ($schema->columns as $column) {
            if ($column->name === SkuRegistry::schema_fields_REQUEST_HASH) {
                $requestHashColumn = $column;
                break;
            }
        }
        self::assertNotNull($requestHashColumn);
        self::assertSame(128, $requestHashColumn->length);
        $casTokenColumn = null;
        foreach ($schema->columns as $column) {
            if ($column->name === SkuRegistry::schema_fields_CAS_TOKEN) {
                $casTokenColumn = $column;
                break;
            }
        }
        self::assertNotNull($casTokenColumn);
        self::assertSame(64, $casTokenColumn->length);
        self::assertSame('', $casTokenColumn->default);
        self::assertSame(
            [
                'uk_sku_registry_sku',
                'uk_sku_registry_product_uuid',
                'uk_sku_registry_offer_uuid',
                'idx_sku_registry_status',
            ],
            array_map(static fn ($index): string => $index->name, $schema->indexes),
        );

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_p2a003_sku_'
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
        $registryFactory = static function () use ($connectionFactory): SkuRegistry {
            $model = new SkuRegistry();
            $model->setConnection($connectionFactory);
            $model->__init();
            return $model;
        };
        $aliasFactory = static function () use ($connectionFactory): SkuAlias {
            $model = new SkuAlias();
            $model->setConnection($connectionFactory);
            $model->__init();
            return $model;
        };
        $policy = new class implements ProductIdentityCutoverPolicyInterface {
            public function mode(): string
            {
                return self::MODE_LEGACY;
            }

            public function legacyWritesAllowed(): bool
            {
                return true;
            }
        };
        $service = new SkuRegistryService(
            $connectionFactory,
            new DatabaseTransactionRunner(new TransactionCoordinator()),
            $policy,
            $registryFactory,
            $aliasFactory,
        );
        $hash = str_repeat('ab', 64);

        try {
            $claimed = $service->claimLocked('SKU-ORIGINAL', $hash);
            self::assertGreaterThan(0, $claimed->registryId);
            self::assertSame(128, strlen($claimed->requestHash));
            self::assertSame($claimed->registryId, $service->claimLocked('SKU-ORIGINAL', $hash)->registryId);

            $hashRows = $connector->query(
                "SELECT request_hash FROM weline_sku_registry WHERE sku = 'SKU-ORIGINAL'"
            )->fetch();
            self::assertSame($hash, (string)($hashRows[0]['request_hash'] ?? ''));

            $hashConflict = $this->captureConflict(
                static fn () => $service->claimLocked('SKU-ORIGINAL', str_repeat('cd', 64)),
            );
            self::assertSame('sku_request_hash_conflict', $hashConflict->errorCode());

            $renamed = $service->renameSku('SKU-ORIGINAL', 'SKU-CANONICAL');
            self::assertSame($claimed->registryId, $renamed->registryId);
            self::assertSame('SKU-CANONICAL', $renamed->sku);
            self::assertSame(
                $claimed->registryId,
                $service->resolveBySku('SKU-ORIGINAL')?->registryId,
            );
            self::assertSame(
                $claimed->registryId,
                $service->resolveBySku('SKU-CANONICAL')?->registryId,
            );
            self::assertSame(
                $claimed->registryId,
                $service->resolveByProductUuid($claimed->globalProductUuid)?->registryId,
            );
            self::assertSame(
                $claimed->registryId,
                $service->resolveByOfferUuid($claimed->globalOfferUuid)?->registryId,
            );

            self::assertSame(1, $service->incrementRefCount($claimed->registryId));
            self::assertSame(2, $service->incrementRefCount($claimed->registryId));
            self::assertSame(1, $service->decrementRefCount($claimed->registryId));
            self::assertSame(
                'sku_identity_still_referenced',
                $this->captureConflict(
                    static fn () => $service->cleanupOrphanBySku('SKU-CANONICAL'),
                )->errorCode(),
            );
            self::assertSame(0, $service->decrementRefCount($claimed->registryId));
            self::assertSame(
                'sku_ref_count_underflow',
                $this->captureConflict(
                    static fn () => $service->decrementRefCount($claimed->registryId),
                )->errorCode(),
            );

            self::assertTrue($service->cleanupOrphanBySku('SKU-CANONICAL'));
            self::assertFalse($service->cleanupOrphanBySku('SKU-CANONICAL'));
            self::assertNull($service->resolveBySku('SKU-CANONICAL'));
            self::assertNull($service->resolveBySku('SKU-ORIGINAL'));

            $tombstoneRows = $connector->query(
                "SELECT status, ref_count, cas_token FROM weline_sku_registry"
                . " WHERE registry_id = {$claimed->registryId}"
            )->fetch();
            self::assertSame(
                SkuRegistry::STATUS_TOMBSTONED,
                (string)($tombstoneRows[0]['status'] ?? ''),
            );
            self::assertSame(0, (int)($tombstoneRows[0]['ref_count'] ?? -1));
            self::assertSame(64, strlen((string)($tombstoneRows[0]['cas_token'] ?? '')));
            $aliasRows = $connector->query(
                "SELECT sku, registry_id FROM weline_sku_alias WHERE sku = 'SKU-ORIGINAL'"
            )->fetch();
            self::assertCount(1, $aliasRows);
            self::assertSame($claimed->registryId, (int)$aliasRows[0]['registry_id']);

            self::assertSame(
                'sku_identity_tombstoned',
                $this->captureConflict(
                    static fn () => $service->claimLocked('SKU-CANONICAL', $hash),
                )->errorCode(),
            );
            self::assertSame(
                'sku_identity_tombstoned',
                $this->captureConflict(
                    static fn () => $service->claimLocked('SKU-ORIGINAL', $hash),
                )->errorCode(),
            );
        } finally {
            $connector->close();
            $connectionFactory->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }

        self::assertFileDoesNotExist($dbPath);
    }

    private function captureConflict(callable $callback): SkuIdentityConflictException
    {
        try {
            $callback();
        } catch (SkuIdentityConflictException $exception) {
            return $exception;
        }
        self::fail('Expected SkuIdentityConflictException.');
    }

    private function createTables(ConnectorInterface $connector): void
    {
        $connector->query(
            'CREATE TABLE weline_sku_registry ('
            . 'registry_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'sku VARCHAR(128) NOT NULL UNIQUE, '
            . 'global_product_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'global_offer_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'request_hash VARCHAR(128) NOT NULL, '
            . 'ref_count INTEGER NOT NULL DEFAULT 0, '
            . "cas_token VARCHAR(64) NOT NULL DEFAULT '', "
            . "status VARCHAR(32) NOT NULL DEFAULT 'active', "
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_sku_alias ('
            . 'alias_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'sku VARCHAR(128) NOT NULL UNIQUE, '
            . 'registry_id INTEGER NOT NULL, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')'
        )->fetch();
    }
}
