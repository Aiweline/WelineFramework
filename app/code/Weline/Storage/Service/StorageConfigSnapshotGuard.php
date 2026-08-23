<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\StorageConfigSnapshotGuardInterface;
use Weline\Storage\Model\StorageConfig;

final class StorageConfigSnapshotGuard implements StorageConfigSnapshotGuardInterface
{
    public function __construct(
        private readonly StorageConfig $configs,
        private readonly StorageDriverProviderRegistry $providers,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    public function assertWritable(StorageConfigSnapshot $snapshot): void
    {
        $connection = $this->configs->getConnection();
        if (!$this->transactions->isActive($connection) || !$this->transactions->isWriteIntent($connection)) {
            throw new \LogicException((string)__('存储配置快照写入校验必须位于持久化事务内。'));
        }

        $row = clone $this->configs;
        $row->clearData()->reset();
        $sourceConfigId = $snapshot->sourceConfigId();
        if ($sourceConfigId !== null) {
            $row->where(StorageConfig::schema_fields_CONFIG_ID, $sourceConfigId);
        } else {
            $row->where(StorageConfig::schema_fields_NAME, $snapshot->diskCode);
        }
        if ($this->supportsForUpdate()) {
            $row->additional('FOR UPDATE');
        }
        $row->find()->fetch();
        if (!$row->getId()) {
            if ($snapshot->diskCode !== StorageDiskCode::BUILTIN_LOCAL_MEDIA) {
                throw new \RuntimeException((string)__('存储磁盘配置在对象提交前已不可用。'));
            }
            $builtin = [
                'root_path' => rtrim(PUB, '/\\') . DIRECTORY_SEPARATOR . 'media',
                'base_url' => '/pub/media',
                'visibility' => 'public',
            ];
            $currentFingerprint = $this->providers->objectNamespaceFingerprint('local::filesystem', $builtin);
            if (!hash_equals($snapshot->objectNamespaceFingerprint(), $currentFingerprint)) {
                throw new \RuntimeException((string)__('存储磁盘对象命名空间在上传期间已变更，请重试。'));
            }
            return;
        }

        if ($sourceConfigId === null) {
            throw new \RuntimeException((string)__('存储磁盘配置身份在对象提交前已变化。'));
        }

        if ((int)$row->getData(StorageConfig::schema_fields_STATUS) !== StorageConfig::STATUS_ENABLED) {
            throw new \RuntimeException((string)__('存储磁盘在对象提交前已停用。'));
        }
        $providerCode = StorageConfig::providerCodeForDriver(
            (string)$row->getData(StorageConfig::schema_fields_DRIVER),
        );
        if ($providerCode !== $snapshot->code()->providerCode()) {
            throw new \RuntimeException((string)__('存储磁盘 Provider 在上传期间已变更。'));
        }
        $config = $row->getConfigArray();
        $currentFingerprint = $this->providers->objectNamespaceFingerprint($providerCode, $config);
        if (!hash_equals($snapshot->objectNamespaceFingerprint(), $currentFingerprint)) {
            throw new \RuntimeException((string)__('存储磁盘对象命名空间在上传期间已变更，请重试。'));
        }
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->configs->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }
}
