<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Database\Schema\Shard\ShardProvisionResult;
use Weline\Framework\Database\Schema\Shard\ShardSchemaProvisionerInterface;
use Weline\Product\Model\ProductShardKey;
use Weline\Product\Model\ProductShardRegistry;

/**
 * Product website shard provisioner：状态机 + 通用 DDL Provisioner。
 * 不在业务请求路径执行 DDL；失败进入 maintenance，不删表。
 */
final class ProductShardProvisioner
{
    public function __construct(
        private readonly ProductShardRegistry $registry,
        private readonly ShardSchemaProvisionerInterface $schemaProvisioner,
        private readonly ProductShardSchemaCatalog $catalog = new ProductShardSchemaCatalog(),
    ) {
    }

    public function registerWebsite(int $websiteId): void
    {
        $this->registry->ensureWebsite($websiteId);
    }

    /**
     * Provision one website shard. website_id=0 is valid.
     * When already ready at current SCHEMA_VERSION, returns ready without DDL.
     * When ready but schema_version outdated, upgrades via ready→provisioning CAS.
     */
    public function provisionWebsite(int $websiteId, array $context = []): ShardProvisionResult
    {
        if ($websiteId < 0) {
            return new ShardProvisionResult(
                familyCode: ProductShardKey::FAMILY_CODE,
                shardKey: (string)$websiteId,
                status: ShardProvisionResult::STATUS_FAILED,
                fingerprint: '',
                errorMessage: __('website_id 不能为负数：%{1}', [$websiteId]),
            );
        }

        $shardKey = ProductShardKey::fromWebsiteId($websiteId);
        $this->registry->ensureWebsite($websiteId);

        $status = $this->registry->getStatus($websiteId);
        $schemaVersion = $this->registry->getSchemaVersion($websiteId);
        $fingerprint = trim($this->registry->getFingerprint($websiteId));
        if ($status === ProductShardRegistry::STATUS_READY
            && $schemaVersion === ProductShardSchemaCatalog::SCHEMA_VERSION
            && $fingerprint !== ''
        ) {
            return new ShardProvisionResult(
                familyCode: ProductShardKey::FAMILY_CODE,
                shardKey: $shardKey,
                status: ShardProvisionResult::STATUS_READY,
                fingerprint: $fingerprint,
            );
        }

        $fromStatuses = [
            ProductShardRegistry::STATUS_UNPROVISIONED,
            ProductShardRegistry::STATUS_FAILED,
            ProductShardRegistry::STATUS_MAINTENANCE,
        ];
        // Schema bump：允许 ready → provisioning（仍不在业务请求热路径调用）
        if ($status === ProductShardRegistry::STATUS_READY) {
            $fromStatuses[] = ProductShardRegistry::STATUS_READY;
        }

        $cas = $this->registry->compareAndSet(
            $websiteId,
            $fromStatuses,
            ProductShardRegistry::STATUS_PROVISIONING,
        );
        if (!$cas) {
            $status = $this->registry->getStatus($websiteId);
            if ($status === ProductShardRegistry::STATUS_READY
                && $this->registry->getSchemaVersion($websiteId) === ProductShardSchemaCatalog::SCHEMA_VERSION
                && trim($this->registry->getFingerprint($websiteId)) !== ''
            ) {
                return new ShardProvisionResult(
                    familyCode: ProductShardKey::FAMILY_CODE,
                    shardKey: $shardKey,
                    status: ShardProvisionResult::STATUS_READY,
                    fingerprint: $this->registry->getFingerprint($websiteId),
                );
            }
            if ($status === ProductShardRegistry::STATUS_PROVISIONING) {
                return new ShardProvisionResult(
                    familyCode: ProductShardKey::FAMILY_CODE,
                    shardKey: $shardKey,
                    status: ShardProvisionResult::STATUS_FAILED,
                    fingerprint: '',
                    errorMessage: __('Product shard 正在 provisioning：website_id=%{1}', [$websiteId]),
                );
            }
            return new ShardProvisionResult(
                familyCode: ProductShardKey::FAMILY_CODE,
                shardKey: $shardKey,
                status: ShardProvisionResult::STATUS_FAILED,
                fingerprint: '',
                errorMessage: __('Product shard 无法进入 provisioning：status=%{1}', [$status]),
            );
        }

        $result = $this->schemaProvisioner->provision(
            ProductShardKey::FAMILY_CODE,
            $shardKey,
            array_merge($context, [
                'family' => ProductShardKey::FAMILY_CODE,
                'shard_key' => $shardKey,
                'website_id' => $websiteId,
            ]),
        );

        $invalidResult = $this->validateProvisionResult($shardKey, $result);
        if ($invalidResult !== null) {
            $this->registry->markMaintenance($websiteId, $invalidResult);
            return new ShardProvisionResult(
                familyCode: ProductShardKey::FAMILY_CODE,
                shardKey: $shardKey,
                status: ShardProvisionResult::STATUS_MAINTENANCE,
                fingerprint: '',
                errorMessage: $invalidResult,
            );
        }

        if ($result->isReady()) {
            $this->registry->markReady(
                $websiteId,
                $result->fingerprint,
                ProductShardSchemaCatalog::SCHEMA_VERSION,
            );
            return $result;
        }

        $message = (string)($result->errorMessage ?? __('Product shard provision 失败'));
        if ($result->status === ShardProvisionResult::STATUS_MAINTENANCE) {
            $this->registry->markMaintenance($websiteId, $message);
        } else {
            $this->registry->markFailed($websiteId, $message);
        }

        return $result;
    }

    private function validateProvisionResult(string $shardKey, ShardProvisionResult $result): ?string
    {
        if ($result->familyCode !== ProductShardKey::FAMILY_CODE || $result->shardKey !== $shardKey) {
            return __(
                'Product shard provision 返回身份不匹配：expected=%{1}/%{2} actual=%{3}/%{4}',
                [
                    ProductShardKey::FAMILY_CODE,
                    $shardKey,
                    $result->familyCode,
                    $result->shardKey,
                ],
            );
        }

        if (!in_array($result->status, [
            ShardProvisionResult::STATUS_READY,
            ShardProvisionResult::STATUS_MAINTENANCE,
            ShardProvisionResult::STATUS_FAILED,
        ], true)) {
            return __('Product shard provision 返回非法状态：%{1}', [$result->status]);
        }

        $expectedTables = array_map(
            static fn($schema): string => $schema->tableName,
            $this->catalog->schemasForShard($shardKey),
        );
        $returnedTables = array_values(array_map('strval', $result->tableNames));
        if (count($returnedTables) !== count(array_unique($returnedTables))) {
            return __('Product shard provision 返回重复表名');
        }
        foreach ($returnedTables as $tableName) {
            if (!in_array($tableName, $expectedTables, true)) {
                return __('Product shard provision 返回未声明表：%{1}', [$tableName]);
            }
        }

        if ($result->isReady()) {
            $sortedExpected = $expectedTables;
            $sortedReturned = $returnedTables;
            sort($sortedExpected);
            sort($sortedReturned);
            if ($sortedReturned !== $sortedExpected) {
                return __('Product shard provision ready 未返回完整声明表集合');
            }
            if (trim($result->fingerprint) === '') {
                return __('Product shard provision ready 指纹不能为空');
            }
        }

        return null;
    }

    public function isReady(int $websiteId): bool
    {
        return $this->registry->isReady($websiteId);
    }

    public function assertReady(int $websiteId): void
    {
        $this->registry->assertReady($websiteId);
    }

    public function isWritable(int $websiteId): bool
    {
        return $this->registry->isWritable($websiteId);
    }
}
