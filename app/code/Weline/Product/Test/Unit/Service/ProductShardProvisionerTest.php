<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\Shard\ShardProvisionResult;
use Weline\Framework\Database\Schema\Shard\ShardSchemaProvisionerInterface;
use Weline\Product\Model\ProductShardKey;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\ProductShardSchemaCatalog;

final class ProductShardProvisionerTest extends TestCase
{
    public function testCatalogBuildsNineTablesForShardZero(): void
    {
        $catalog = new ProductShardSchemaCatalog();
        $schemas = $catalog->schemasForShard('0');
        self::assertCount(count(ProductShardSchemaCatalog::ENTITIES), $schemas);
        self::assertSame('product_ws_0_product', $schemas[0]->tableName);
        self::assertSame('product_ws_0_store_offer', $schemas[array_key_last($schemas)]->tableName);
    }

    public function testProvisionReadyMarksRegistry(): void
    {
        $registry = $this->createMock(ProductShardRegistry::class);
        $registry->expects(self::once())->method('ensureWebsite')->with(0);
        $registry->method('getStatus')->willReturn(ProductShardRegistry::STATUS_UNPROVISIONED);
        $registry->method('getSchemaVersion')->willReturn('0');
        $registry->expects(self::once())
            ->method('compareAndSet')
            ->with(
                0,
                [
                    ProductShardRegistry::STATUS_UNPROVISIONED,
                    ProductShardRegistry::STATUS_FAILED,
                    ProductShardRegistry::STATUS_MAINTENANCE,
                ],
                ProductShardRegistry::STATUS_PROVISIONING,
            )
            ->willReturn(true);

        $schemaProvisioner = $this->createMock(ShardSchemaProvisionerInterface::class);
        $schemaProvisioner->expects(self::once())
            ->method('provision')
            ->with(ProductShardKey::FAMILY_CODE, '0', self::callback(
                static fn(array $ctx): bool => ($ctx['website_id'] ?? null) === 0
            ))
            ->willReturn(new ShardProvisionResult(
                familyCode: ProductShardKey::FAMILY_CODE,
                shardKey: '0',
                status: ShardProvisionResult::STATUS_READY,
                fingerprint: 'fp-ready',
                tableNames: $this->expectedTableNames('0'),
            ));

        $registry->expects(self::once())
            ->method('markReady')
            ->with(0, 'fp-ready', ProductShardSchemaCatalog::SCHEMA_VERSION);

        $provisioner = new ProductShardProvisioner($registry, $schemaProvisioner);
        $result = $provisioner->provisionWebsite(0);
        self::assertTrue($result->isReady());
        self::assertSame('fp-ready', $result->fingerprint);
    }

    public function testProvisionFailureMarksMaintenanceWithoutDeletingTables(): void
    {
        $registry = $this->createMock(ProductShardRegistry::class);
        $registry->method('ensureWebsite');
        $registry->method('getStatus')->willReturn(ProductShardRegistry::STATUS_FAILED);
        $registry->method('getSchemaVersion')->willReturn('1');
        $registry->method('compareAndSet')->willReturn(true);

        $schemaProvisioner = $this->createMock(ShardSchemaProvisionerInterface::class);
        $schemaProvisioner->method('provision')->willReturn(new ShardProvisionResult(
            familyCode: ProductShardKey::FAMILY_CODE,
            shardKey: '1',
            status: ShardProvisionResult::STATUS_MAINTENANCE,
            fingerprint: 'partial',
            tableNames: ['product_ws_1_product'],
            errorMessage: 'simulated ddl failure',
        ));

        $registry->expects(self::once())
            ->method('markMaintenance')
            ->with(1, 'simulated ddl failure');
        $registry->expects(self::never())->method('markReady');

        $provisioner = new ProductShardProvisioner($registry, $schemaProvisioner);
        $result = $provisioner->provisionWebsite(1);
        self::assertSame(ShardProvisionResult::STATUS_MAINTENANCE, $result->status);
        self::assertSame(['product_ws_1_product'], $result->tableNames);
    }

    public function testNegativeWebsiteFailsClosed(): void
    {
        $registry = $this->createMock(ProductShardRegistry::class);
        $schemaProvisioner = $this->createMock(ShardSchemaProvisionerInterface::class);
        $schemaProvisioner->expects(self::never())->method('provision');

        $provisioner = new ProductShardProvisioner($registry, $schemaProvisioner);
        $result = $provisioner->provisionWebsite(-1);
        self::assertSame(ShardProvisionResult::STATUS_FAILED, $result->status);
    }

    public function testWritableGateRequiresReady(): void
    {
        $registry = $this->createMock(ProductShardRegistry::class);
        $registry->method('isWritable')->willReturnMap([
            [0, true],
            [2, false],
        ]);
        $provisioner = new ProductShardProvisioner(
            $registry,
            $this->createMock(ShardSchemaProvisionerInterface::class),
        );
        self::assertTrue($provisioner->isWritable(0));
        self::assertFalse($provisioner->isWritable(2));
    }

    public function testSchemaBumpAllowsReadyToProvisioning(): void
    {
        $registry = $this->createMock(ProductShardRegistry::class);
        $registry->method('ensureWebsite');
        $registry->method('getStatus')->willReturn(ProductShardRegistry::STATUS_READY);
        $registry->method('getSchemaVersion')->willReturn('1');
        $registry->expects(self::once())
            ->method('compareAndSet')
            ->with(
                0,
                [
                    ProductShardRegistry::STATUS_UNPROVISIONED,
                    ProductShardRegistry::STATUS_FAILED,
                    ProductShardRegistry::STATUS_MAINTENANCE,
                    ProductShardRegistry::STATUS_READY,
                ],
                ProductShardRegistry::STATUS_PROVISIONING,
            )
            ->willReturn(true);

        $schemaProvisioner = $this->createMock(ShardSchemaProvisionerInterface::class);
        $schemaProvisioner->expects(self::once())->method('provision')->willReturn(
            new ShardProvisionResult(
                familyCode: ProductShardKey::FAMILY_CODE,
                shardKey: '0',
                status: ShardProvisionResult::STATUS_READY,
                fingerprint: 'fp-v2',
                tableNames: $this->expectedTableNames('0'),
            ),
        );
        $registry->expects(self::once())
            ->method('markReady')
            ->with(0, 'fp-v2', ProductShardSchemaCatalog::SCHEMA_VERSION);

        $provisioner = new ProductShardProvisioner($registry, $schemaProvisioner);
        self::assertTrue($provisioner->provisionWebsite(0)->isReady());
    }

    public function testCurrentSchemaVersionShortCircuitsReady(): void
    {
        $registry = $this->createMock(ProductShardRegistry::class);
        $registry->method('ensureWebsite');
        $registry->method('getStatus')->willReturn(ProductShardRegistry::STATUS_READY);
        $registry->method('getSchemaVersion')->willReturn(ProductShardSchemaCatalog::SCHEMA_VERSION);
        $registry->method('getFingerprint')->willReturn('fp-current');
        $registry->expects(self::never())->method('compareAndSet');

        $schemaProvisioner = $this->createMock(ShardSchemaProvisionerInterface::class);
        $schemaProvisioner->expects(self::never())->method('provision');

        $provisioner = new ProductShardProvisioner($registry, $schemaProvisioner);
        $result = $provisioner->provisionWebsite(0);
        self::assertTrue($result->isReady());
        self::assertSame('fp-current', $result->fingerprint);
    }

    public function testReadyResultWithWrongIdentityFailsClosed(): void
    {
        $registry = $this->provisioningRegistry(0);
        $registry->expects(self::once())
            ->method('markMaintenance')
            ->with(0, self::stringContains('身份不匹配'));
        $registry->expects(self::never())->method('markReady');

        $schemaProvisioner = $this->createMock(ShardSchemaProvisionerInterface::class);
        $schemaProvisioner->method('provision')->willReturn(new ShardProvisionResult(
            familyCode: 'forged.family',
            shardKey: '0',
            status: ShardProvisionResult::STATUS_READY,
            fingerprint: 'forged',
            tableNames: $this->expectedTableNames('0'),
        ));

        $result = (new ProductShardProvisioner($registry, $schemaProvisioner))->provisionWebsite(0);
        self::assertSame(ShardProvisionResult::STATUS_MAINTENANCE, $result->status);
        self::assertStringContainsString('身份不匹配', (string)$result->errorMessage);
    }

    public function testReadyResultWithUnknownTableFailsClosed(): void
    {
        $registry = $this->provisioningRegistry(0);
        $registry->expects(self::once())
            ->method('markMaintenance')
            ->with(0, self::stringContains('未声明表'));
        $registry->expects(self::never())->method('markReady');

        $schemaProvisioner = $this->createMock(ShardSchemaProvisionerInterface::class);
        $tables = $this->expectedTableNames('0');
        $tables[] = 'product_ws_0_audit_log';
        $schemaProvisioner->method('provision')->willReturn(new ShardProvisionResult(
            familyCode: ProductShardKey::FAMILY_CODE,
            shardKey: '0',
            status: ShardProvisionResult::STATUS_READY,
            fingerprint: 'forged',
            tableNames: $tables,
        ));

        $result = (new ProductShardProvisioner($registry, $schemaProvisioner))->provisionWebsite(0);
        self::assertSame(ShardProvisionResult::STATUS_MAINTENANCE, $result->status);
        self::assertStringContainsString('未声明表', (string)$result->errorMessage);
    }

    public function testReadyResultWithEmptyFingerprintFailsClosed(): void
    {
        $registry = $this->provisioningRegistry(0);
        $registry->expects(self::once())
            ->method('markMaintenance')
            ->with(0, self::stringContains('指纹不能为空'));
        $registry->expects(self::never())->method('markReady');

        $schemaProvisioner = $this->createMock(ShardSchemaProvisionerInterface::class);
        $schemaProvisioner->method('provision')->willReturn(new ShardProvisionResult(
            familyCode: ProductShardKey::FAMILY_CODE,
            shardKey: '0',
            status: ShardProvisionResult::STATUS_READY,
            fingerprint: '',
            tableNames: $this->expectedTableNames('0'),
        ));

        $result = (new ProductShardProvisioner($registry, $schemaProvisioner))->provisionWebsite(0);
        self::assertSame(ShardProvisionResult::STATUS_MAINTENANCE, $result->status);
        self::assertStringContainsString('指纹不能为空', (string)$result->errorMessage);
    }

    public function testCurrentVersionWithEmptyFingerprintReprovisions(): void
    {
        $registry = $this->createMock(ProductShardRegistry::class);
        $registry->method('ensureWebsite');
        $registry->method('getStatus')->willReturn(ProductShardRegistry::STATUS_READY);
        $registry->method('getSchemaVersion')->willReturn(ProductShardSchemaCatalog::SCHEMA_VERSION);
        $registry->method('getFingerprint')->willReturn('');
        $registry->expects(self::once())
            ->method('compareAndSet')
            ->with(
                0,
                [
                    ProductShardRegistry::STATUS_UNPROVISIONED,
                    ProductShardRegistry::STATUS_FAILED,
                    ProductShardRegistry::STATUS_MAINTENANCE,
                    ProductShardRegistry::STATUS_READY,
                ],
                ProductShardRegistry::STATUS_PROVISIONING,
            )
            ->willReturn(true);
        $registry->expects(self::once())
            ->method('markReady')
            ->with(0, 'repaired-fingerprint', ProductShardSchemaCatalog::SCHEMA_VERSION);

        $schemaProvisioner = $this->createMock(ShardSchemaProvisionerInterface::class);
        $schemaProvisioner->expects(self::once())->method('provision')->willReturn(
            new ShardProvisionResult(
                familyCode: ProductShardKey::FAMILY_CODE,
                shardKey: '0',
                status: ShardProvisionResult::STATUS_READY,
                fingerprint: 'repaired-fingerprint',
                tableNames: $this->expectedTableNames('0'),
            ),
        );

        $result = (new ProductShardProvisioner($registry, $schemaProvisioner))->provisionWebsite(0);
        self::assertTrue($result->isReady());
        self::assertSame('repaired-fingerprint', $result->fingerprint);
    }

    private function provisioningRegistry(int $websiteId): ProductShardRegistry
    {
        $registry = $this->createMock(ProductShardRegistry::class);
        $registry->method('ensureWebsite');
        $registry->method('getStatus')->willReturn(ProductShardRegistry::STATUS_UNPROVISIONED);
        $registry->method('getSchemaVersion')->willReturn('1');
        $registry->method('getFingerprint')->willReturn('');
        $registry->expects(self::once())
            ->method('compareAndSet')
            ->with(
                $websiteId,
                [
                    ProductShardRegistry::STATUS_UNPROVISIONED,
                    ProductShardRegistry::STATUS_FAILED,
                    ProductShardRegistry::STATUS_MAINTENANCE,
                ],
                ProductShardRegistry::STATUS_PROVISIONING,
            )
            ->willReturn(true);

        return $registry;
    }

    /**
     * @return list<string>
     */
    private function expectedTableNames(string $shardKey): array
    {
        return array_map(
            static fn(string $entity): string => ProductShardKey::tableName($shardKey, $entity),
            ProductShardSchemaCatalog::ENTITIES,
        );
    }
}
