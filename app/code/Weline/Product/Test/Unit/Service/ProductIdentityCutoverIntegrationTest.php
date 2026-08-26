<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Product\Api\ProductIdentityCutoverPolicyInterface;
use Weline\Product\Model\OfferIdentityRegistry;
use Weline\Product\Model\OfferSkuAlias;
use Weline\Product\Model\ProductAuditLog;
use Weline\Product\Model\ProductIdentityCutoverState;
use Weline\Product\Model\ProductIdentityRegistry;
use Weline\Product\Model\ProductMigrationConflict;
use Weline\Product\Model\SkuAlias;
use Weline\Product\Model\SkuRegistry;
use Weline\Product\Service\CompatibleProductIdentityResolver;
use Weline\Product\Service\ProductIdentityCutoverService;
use Weline\Product\Service\ProductIdentityV2Service;
use Weline\Product\Service\ProductV2ConflictException;
use Weline\Product\Service\ProductV2MigrationService;
use Weline\Product\Service\SkuIdentityConflictException;
use Weline\Product\Service\SkuRegistryService;

final class ProductIdentityCutoverIntegrationTest extends TestCase
{
    public function testCloneMigrationVerificationCutoverAndRollbackAreDurable(): void
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_product_identity_cutover_'
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
        $transactions = new DatabaseTransactionRunner(new TransactionCoordinator());

        $legacyFactory = $this->factory(SkuRegistry::class, $connectionFactory);
        $aliasFactory = $this->factory(SkuAlias::class, $connectionFactory);
        $productFactory = $this->factory(ProductIdentityRegistry::class, $connectionFactory);
        $offerFactory = $this->factory(OfferIdentityRegistry::class, $connectionFactory);
        $offerAliasFactory = $this->factory(OfferSkuAlias::class, $connectionFactory);
        $auditFactory = $this->factory(ProductAuditLog::class, $connectionFactory);
        $conflictFactory = $this->factory(ProductMigrationConflict::class, $connectionFactory);
        $stateFactory = $this->factory(ProductIdentityCutoverState::class, $connectionFactory);

        $cutover = new ProductIdentityCutoverService(
            $connectionFactory,
            $transactions,
            $stateFactory,
        );
        $legacy = new SkuRegistryService(
            $connectionFactory,
            $transactions,
            $cutover,
            $legacyFactory,
            $aliasFactory,
        );
        $identities = new ProductIdentityV2Service(
            $connectionFactory,
            $transactions,
            $productFactory,
            $offerFactory,
            $offerAliasFactory,
            $auditFactory,
        );
        $migration = new ProductV2MigrationService(
            $identities,
            $cutover,
            $legacyFactory,
            $productFactory,
            $offerFactory,
            $conflictFactory,
        );
        $resolver = new CompatibleProductIdentityResolver($identities, $legacy, $cutover);

        try {
            $first = $legacy->claimLocked('SKU-CUTOVER-1', str_repeat('a1', 64));
            self::assertSame(ProductIdentityCutoverPolicyInterface::MODE_LEGACY, $cutover->mode());

            $applied = $migration->migrate(false);
            self::assertTrue($applied['ok']);
            self::assertSame(1, $applied['created_products']);
            self::assertSame(1, $applied['created_offers']);
            self::assertSame(
                ProductIdentityCutoverPolicyInterface::MODE_DUAL_READ,
                $applied['cutover_state']['mode'],
            );
            $firstDigest = (string)$applied['source_digest'];

            $verified = $migration->verify();
            self::assertTrue($verified['ok']);
            self::assertSame(1, $verified['scanned']);
            self::assertSame(0, $verified['mismatch_count']);
            self::assertSame($firstDigest, $verified['cutover_state']['verified_digest']);

            $authoritative = $migration->cutover(
                (int)$verified['cutover_state']['version'],
            );
            self::assertSame(
                ProductIdentityCutoverPolicyInterface::MODE_V2_AUTHORITATIVE,
                $authoritative['cutover_state']['mode'],
            );
            self::assertFalse($cutover->legacyWritesAllowed());

            $compat = $resolver->resolveBySku('SKU-CUTOVER-1');
            self::assertNotNull($compat);
            self::assertSame($first->globalProductUuid, $compat->globalProductUuid);
            self::assertSame($first->globalOfferUuid, $compat->globalOfferUuid);
            self::assertSame($first->requestHash, $compat->requestHash);
            self::assertSame(
                $first->globalOfferUuid,
                $resolver->resolveByProductUuid($first->globalProductUuid)?->globalOfferUuid,
            );
            self::assertSame(
                $first->sku,
                $resolver->resolveByOfferUuid($first->globalOfferUuid)?->sku,
            );

            foreach ([
                static fn () => $legacy->claimLocked('SKU-BLOCKED', str_repeat('b2', 64)),
                static fn () => $legacy->renameSku('SKU-CUTOVER-1', 'SKU-RENAMED'),
                static fn () => $legacy->incrementRefCount($first->registryId),
                static fn () => $legacy->decrementRefCount($first->registryId),
                static fn () => $legacy->cleanupOrphanBySku('SKU-CUTOVER-1'),
            ] as $mutation) {
                self::assertSame(
                    'legacy_identity_writes_disabled',
                    $this->captureLegacyConflict($mutation)->errorCode(),
                );
            }

            $rolledDual = $migration->rollback(
                (int)$authoritative['cutover_state']['version'],
            );
            self::assertSame(
                ProductIdentityCutoverPolicyInterface::MODE_DUAL_READ,
                $rolledDual['cutover_state']['mode'],
            );
            self::assertTrue($cutover->legacyWritesAllowed());

            $second = $legacy->claimLocked('SKU-CUTOVER-2', str_repeat('c3', 64));
            $staleVerification = $migration->verify();
            self::assertFalse($staleVerification['ok']);
            self::assertSame(1, $staleVerification['mismatch_count']);
            self::assertNotSame($firstDigest, $staleVerification['source_digest']);
            self::assertSame('', $staleVerification['cutover_state']['verified_digest']);
            self::assertSame(
                'product_v2_cutover_not_verified',
                $this->captureCutoverConflict(
                    static fn () => $migration->cutover(
                        (int)$staleVerification['cutover_state']['version'],
                    ),
                )->errorCode,
            );

            $reapplied = $migration->migrate(false);
            self::assertTrue($reapplied['ok']);
            self::assertSame(1, $reapplied['created_products']);
            self::assertSame(1, $reapplied['created_offers']);
            $reverified = $migration->verify();
            self::assertTrue($reverified['ok']);
            self::assertSame(2, $reverified['scanned']);

            $secondCutover = $migration->cutover(
                (int)$reverified['cutover_state']['version'],
            );
            self::assertSame(
                ProductIdentityCutoverPolicyInterface::MODE_V2_AUTHORITATIVE,
                $secondCutover['cutover_state']['mode'],
            );
            self::assertSame(
                $second->globalOfferUuid,
                $resolver->resolveBySku('SKU-CUTOVER-2')?->globalOfferUuid,
            );

            $replayed = $migration->migrate(false);
            self::assertTrue($replayed['ok']);
            self::assertSame(0, $replayed['created_products']);
            self::assertSame(0, $replayed['created_offers']);
            self::assertSame(2, $replayed['skipped']);
            self::assertSame(
                ProductIdentityCutoverPolicyInterface::MODE_V2_AUTHORITATIVE,
                $replayed['cutover_state']['mode'],
            );
            self::assertSame(2, $this->countRows($connector, ProductIdentityRegistry::schema_table));
            self::assertSame(2, $this->countRows($connector, OfferIdentityRegistry::schema_table));

            $rolledLegacy = $migration->rollback(
                (int)$replayed['cutover_state']['version'],
                ProductIdentityCutoverPolicyInterface::MODE_LEGACY,
            );
            self::assertSame(
                ProductIdentityCutoverPolicyInterface::MODE_LEGACY,
                $rolledLegacy['cutover_state']['mode'],
            );
            self::assertTrue($cutover->legacyWritesAllowed());
            self::assertSame(
                'SKU-AFTER-ROLLBACK',
                $legacy->claimLocked('SKU-AFTER-ROLLBACK', str_repeat('d4', 64))->sku,
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

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return \Closure(): T
     */
    private function factory(string $class, ConnectionFactory $connectionFactory): \Closure
    {
        return static function () use ($class, $connectionFactory): Model {
            $model = new $class();
            $model->setConnection($connectionFactory);
            $model->__init();
            return $model;
        };
    }

    private function captureLegacyConflict(callable $callback): SkuIdentityConflictException
    {
        try {
            $callback();
        } catch (SkuIdentityConflictException $exception) {
            return $exception;
        }
        self::fail('Expected SkuIdentityConflictException.');
    }

    private function captureCutoverConflict(callable $callback): ProductV2ConflictException
    {
        try {
            $callback();
        } catch (ProductV2ConflictException $exception) {
            return $exception;
        }
        self::fail('Expected ProductV2ConflictException.');
    }

    private function countRows(ConnectorInterface $connector, string $table): int
    {
        $rows = $connector->query('SELECT COUNT(*) AS total FROM ' . $table)->fetch();
        return (int)($rows[0]['total'] ?? 0);
    }

    private function createTables(ConnectorInterface $connector): void
    {
        foreach ([
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
                . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE weline_sku_alias ('
                . 'alias_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'sku VARCHAR(128) NOT NULL UNIQUE, '
                . 'registry_id INTEGER NOT NULL, '
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE weline_product_identity_v2 ('
                . 'registry_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'global_product_uuid VARCHAR(36) NOT NULL UNIQUE, '
                . 'product_code VARCHAR(32) NOT NULL UNIQUE, '
                . 'owner_website_id INTEGER NOT NULL, '
                . 'provider_code VARCHAR(64) NOT NULL, '
                . 'product_type VARCHAR(64) NOT NULL, '
                . "lifecycle_status VARCHAR(32) NOT NULL DEFAULT 'draft', "
                . 'version INTEGER NOT NULL DEFAULT 1, '
                . "share_policy VARCHAR(64) NOT NULL DEFAULT 'private', "
                . 'request_hash VARCHAR(128) NOT NULL UNIQUE, '
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE weline_offer_identity_v2 ('
                . 'registry_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'global_offer_uuid VARCHAR(36) NOT NULL UNIQUE, '
                . 'global_product_uuid VARCHAR(36) NOT NULL, '
                . 'sku VARCHAR(128) NOT NULL UNIQUE, '
                . "status VARCHAR(32) NOT NULL DEFAULT 'active', "
                . 'version INTEGER NOT NULL DEFAULT 1, '
                . 'request_hash VARCHAR(128) NOT NULL UNIQUE, '
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE weline_offer_sku_alias_v2 ('
                . 'alias_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'sku VARCHAR(128) NOT NULL UNIQUE, '
                . 'global_offer_uuid VARCHAR(36) NOT NULL, '
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE weline_product_audit_log ('
                . 'event_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'global_product_uuid VARCHAR(36) NOT NULL, '
                . 'global_offer_uuid VARCHAR(36), '
                . 'website_id INTEGER NOT NULL, '
                . 'action VARCHAR(64) NOT NULL, '
                . 'before_version INTEGER NOT NULL DEFAULT 0, '
                . 'after_version INTEGER NOT NULL DEFAULT 0, '
                . 'request_hash VARCHAR(128) NOT NULL, '
                . 'payload_json TEXT NOT NULL, '
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE weline_product_migration_conflict ('
                . 'conflict_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'source_kind VARCHAR(32) NOT NULL, '
                . 'source_key VARCHAR(255) NOT NULL, '
                . 'conflict_code VARCHAR(64) NOT NULL, '
                . 'details_json TEXT NOT NULL, '
                . "resolution_status VARCHAR(32) NOT NULL DEFAULT 'open', "
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'UNIQUE(source_kind, source_key, conflict_code))',
            'CREATE TABLE weline_product_identity_cutover_state ('
                . 'state_id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'state_key VARCHAR(32) NOT NULL UNIQUE, '
                . "mode VARCHAR(32) NOT NULL DEFAULT 'legacy', "
                . 'version INTEGER NOT NULL DEFAULT 0, '
                . "source_digest VARCHAR(64) NOT NULL DEFAULT '', "
                . "verified_digest VARCHAR(64) NOT NULL DEFAULT '', "
                . 'verified_count INTEGER NOT NULL DEFAULT 0, '
                . 'verification_error_count INTEGER NOT NULL DEFAULT 0, '
                . 'verified_at DATETIME, '
                . 'switched_at DATETIME, '
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        ] as $sql) {
            $connector->query($sql)->fetch();
        }
    }
}
