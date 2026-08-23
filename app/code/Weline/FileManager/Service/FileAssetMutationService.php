<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Exception\FileAccessDeniedException;
use Weline\FileManager\Api\FileAccessPolicyInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Storage\Api\StorageDirectoryManagerInterface;
use Weline\Storage\Api\StorageConfigSnapshotGuardInterface;
use Weline\Storage\Api\StorageManagerInterface;
use Weline\Storage\Api\Data\StorageObjectReference;

/** Keeps durable FileAsset identities aligned with file-manager mutations. */
final class FileAssetMutationService
{
    private const MAX_DIRECTORY_ASSETS = 10000;

    public function __construct(
        private readonly FileAsset $assets,
        private readonly FileAssetLocale $locales,
        private readonly FileAssetReferenceIndexer $references,
        private readonly FileAccessPolicyInterface $accessPolicy,
        private readonly StorageManagerInterface $storage,
        private readonly StorageDirectoryManagerInterface $directories,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly StorageConfigSnapshotGuardInterface $snapshotGuard,
    ) {
    }

    public function moveObject(
        string $diskCode,
        string $from,
        string $to,
        FileAccessContext $access,
    ): void
    {
        $this->assertManagementContext($access);
        $disk = $this->storage->disk($diskCode);
        $canonical = $disk->diskCode();
        $from = $this->requireObjectKey($from);
        $to = $this->requireObjectKey($to);
        if ($from === $to) {
            throw new \InvalidArgumentException((string)__('源文件与目标文件不能相同。'));
        }
        $asset = $this->findStored($canonical, $from);
        if ($asset instanceof FileAsset) {
            $this->assertMutationAccess($asset, $access);
            if ($asset->isDeleted()) {
                throw new \RuntimeException((string)__('源文件资源已删除，不能移动。'));
            }
        }
        $targetAsset = $this->findStored($canonical, $to);
        if ($targetAsset instanceof FileAsset) {
            $this->assertMutationAccess($targetAsset, $access);
            if (!$targetAsset->isDeleted()) {
                throw new \RuntimeException((string)__('目标文件已存在，禁止覆盖。'));
            }
        }
        if (!($asset instanceof FileAsset) && !($targetAsset instanceof FileAsset)) {
            $disk->move($from, $to);
            return;
        }
        $connection = $asset instanceof FileAsset
            ? $asset->getConnection()
            : $this->assets->getConnection();
        $rollbackKey = 'file_asset_move_' . hash('sha256', $canonical . "\0" . $from . "\0" . $to);
        $compensate = function () use ($disk, $from, $to): void {
            if (!$disk->exists($to)) {
                return;
            }
            if ($disk->exists($from)) {
                if (!$disk->delete($to)) {
                    throw new \RuntimeException((string)__('文件移动补偿清理目标对象失败。'));
                }
                return;
            }
            $disk->move($to, $from);
        };
        $moved = false;
        $persist = function () use (
            $asset,
            $targetAsset,
            $from,
            $to,
            $disk,
            $access,
            $connection,
            $rollbackKey,
            $compensate,
            &$moved,
        ): void {
            $this->snapshotGuard->assertWritable($disk->snapshot());
            $locked = $this->lockAssetsForMutation(array_values(array_filter(
                [$asset, $targetAsset],
                static fn (mixed $item): bool => $item instanceof FileAsset,
            )));
            if ($targetAsset instanceof FileAsset) {
                $currentTarget = $locked[$targetAsset->getAssetId()];
                $this->assertMutationAccess($currentTarget, $access);
                if (!$currentTarget->isDeleted()) {
                    throw new \RuntimeException((string)__('目标文件已存在，禁止覆盖。'));
                }
            }
            if ($asset instanceof FileAsset) {
                $currentSource = $locked[$asset->getAssetId()];
                $this->assertMutationAccess($currentSource, $access);
                if ($currentSource->isDeleted()) {
                    throw new \RuntimeException((string)__('源文件资源已删除，不能移动。'));
                }
            }

            // Keep the durable identity locks while the provider commits the
            // move. Otherwise a concurrent delete can commit between the
            // provider move and our row lock, leaving a deleted orphan after
            // rollback compensation.
            $disk->move($from, $to);
            $moved = true;
            $this->transactions->afterRollback($connection, $rollbackKey, $compensate);

            if ($targetAsset instanceof FileAsset) {
                $this->purgeSoftDeleted($locked[$targetAsset->getAssetId()]);
            }
            if ($asset instanceof FileAsset) {
                $current = $locked[$asset->getAssetId()];
                $current->setData(FileAsset::schema_fields_OBJECT_KEY, $to);
                $current->save();
            }
        };
        try {
            if ($this->transactions->isActive($connection)) {
                $this->assertWriteIntent();
                $this->transactions->withSavepoint($connection, 'file_asset_move', $persist);
            } else {
                $this->transactions->runWrite($connection, $persist);
            }
        } catch (\Throwable $throwable) {
            if ($moved) {
                try {
                    $compensate();
                } catch (\Throwable $cleanupFailure) {
                    throw new \RuntimeException(
                        (string)__('文件移动失败且物理对象补偿失败。'),
                        0,
                        $cleanupFailure,
                    );
                }
            }
            throw $throwable;
        }
    }

    public function moveDirectory(
        string $diskCode,
        string $from,
        string $to,
        FileAccessContext $access,
    ): void
    {
        $this->assertManagementContext($access);
        $disk = $this->storage->disk($diskCode);
        $canonical = $disk->diskCode();
        $from = $this->requireObjectKey($from);
        $to = $this->requireObjectKey($to);
        if ($from === $to || str_starts_with($to . '/', $from . '/')) {
            throw new \InvalidArgumentException((string)__('目录不能移动到自身或其子目录。'));
        }
        $storedAssets = $this->storedOnDisk($canonical);
        $assets = $this->underPrefixFromRows($storedAssets, $from);
        foreach ($assets as $asset) {
            $this->assertMutationAccess($asset, $access);
        }
        $storedByKey = [];
        foreach ($storedAssets as $storedAsset) {
            $storedByKey[$storedAsset->getObjectKey()] = $storedAsset;
        }

        $sourceEntries = $this->directories->list($canonical, $from, true);
        if (count($sourceEntries) > self::MAX_DIRECTORY_ASSETS) {
            throw new \RuntimeException((string)__('目录中的文件数量超过单次操作上限。'));
        }
        $sourceFileKeys = [];
        $targetKeys = [];
        foreach ($sourceEntries as $entry) {
            if (($entry['type'] ?? '') === 'directory') {
                continue;
            }
            $path = trim((string)($entry['path'] ?? ''), '/');
            if ($path === '' || ($path !== $from && !str_starts_with($path, $from . '/'))) {
                throw new \RuntimeException((string)__('存储目录返回了越界文件。'));
            }
            $storedSource = $storedByKey[$path] ?? null;
            if ($storedSource instanceof FileAsset && $storedSource->isDeleted()) {
                $this->assertMutationAccess($storedSource, $access);
                throw new \RuntimeException((string)__('源目录包含已删除的文件资源，不能移动。'));
            }
            $sourceFileKeys[$path] = true;
            $suffix = ltrim(substr($path, strlen($from)), '/');
            $targetKeys[trim($to . ($suffix !== '' ? '/' . $suffix : ''), '/')] = true;
        }
        foreach ($assets as $asset) {
            if (!isset($sourceFileKeys[$asset->getObjectKey()])) {
                throw new \RuntimeException((string)__('文件资源对应的存储对象不存在。'));
            }
            $suffix = ltrim(substr($asset->getObjectKey(), strlen($from)), '/');
            $targetKeys[trim($to . ($suffix !== '' ? '/' . $suffix : ''), '/')] = true;
        }

        $staleTargets = [];
        foreach (array_keys($targetKeys) as $targetKey) {
            $targetAsset = $storedByKey[$targetKey] ?? null;
            if (!$targetAsset instanceof FileAsset) {
                continue;
            }
            $this->assertMutationAccess($targetAsset, $access);
            if (!$targetAsset->isDeleted()) {
                throw new \RuntimeException((string)__('目标目录包含已登记的同名文件资源。'));
            }
            $staleTargets[$targetAsset->getAssetId()] = $targetAsset;
        }
        $connection = $this->assets->getConnection();
        $rollbackKey = 'file_asset_directory_move_' . hash('sha256', $canonical . "\0" . $from . "\0" . $to);
        $compensate = function () use ($disk, $canonical, $from, $to): void {
            if (!$this->directoryEntryExists($canonical, $to)) {
                return;
            }
            $this->restoreDirectoryFromTarget($disk, $from, $to);
        };
        $moved = false;
        $persist = function () use (
            $assets,
            $staleTargets,
            $sourceFileKeys,
            $canonical,
            $from,
            $to,
            $disk,
            $access,
            $connection,
            $rollbackKey,
            $compensate,
            &$moved,
        ): void {
            $this->snapshotGuard->assertWritable($disk->snapshot());
            $locked = $this->lockAssetsForMutation(array_values(array_merge($assets, $staleTargets)));
            foreach ($assets as $asset) {
                $current = $locked[$asset->getAssetId()];
                $this->assertMutationAccess($current, $access);
                if ($current->isDeleted()) {
                    throw new \RuntimeException((string)__('源目录包含已删除的文件资源，不能移动。'));
                }
            }
            foreach ($staleTargets as $staleTarget) {
                $currentTarget = $locked[$staleTarget->getAssetId()];
                $this->assertMutationAccess($currentTarget, $access);
                if (!$currentTarget->isDeleted()) {
                    throw new \RuntimeException((string)__('目标目录包含已登记的同名文件资源。'));
                }
            }

            $currentFileKeys = [];
            $currentEntries = $this->directories->list($canonical, $from, true);
            if (count($currentEntries) > self::MAX_DIRECTORY_ASSETS) {
                throw new \RuntimeException((string)__('目录中的文件数量超过单次操作上限。'));
            }
            foreach ($currentEntries as $entry) {
                if (($entry['type'] ?? '') !== 'directory') {
                    $currentFileKeys[trim((string)($entry['path'] ?? ''), '/')] = true;
                }
            }
            if (count($currentFileKeys) !== count($sourceFileKeys)
                || array_diff_key($currentFileKeys, $sourceFileKeys) !== []
                || array_diff_key($sourceFileKeys, $currentFileKeys) !== []
            ) {
                throw new \RuntimeException((string)__('源目录已被其他请求修改，请刷新后重试。'));
            }

            if (!$this->directories->move($canonical, $from, $to)) {
                throw new \RuntimeException((string)__('存储目录移动失败。'));
            }
            $moved = true;
            $this->transactions->afterRollback($connection, $rollbackKey, $compensate);

            foreach ($staleTargets as $staleTarget) {
                $this->purgeSoftDeleted($locked[$staleTarget->getAssetId()]);
            }
            foreach ($assets as $asset) {
                $current = $locked[$asset->getAssetId()];
                $suffix = ltrim(substr($current->getObjectKey(), strlen($from)), '/');
                $current->setData(
                    FileAsset::schema_fields_OBJECT_KEY,
                    $to . ($suffix !== '' ? '/' . $suffix : ''),
                );
                $current->save();
            }
        };
        try {
            if ($this->transactions->isActive($connection)) {
                $this->assertWriteIntent();
                $this->transactions->withSavepoint($connection, 'file_asset_directory_move', $persist);
            } else {
                $this->transactions->runWrite($connection, $persist);
            }
        } catch (\Throwable $throwable) {
            if ($moved) {
                try {
                    $compensate();
                } catch (\Throwable $cleanupFailure) {
                    throw new \RuntimeException(
                        (string)__('目录移动失败且物理对象补偿失败。'),
                        0,
                        $cleanupFailure,
                    );
                }
            }
            throw $throwable;
        }
    }

    public function deleteObject(
        string $diskCode,
        string $objectKey,
        FileAccessContext $access,
    ): void
    {
        $this->assertManagementContext($access);
        $disk = $this->storage->disk($diskCode);
        $objectKey = $this->requireObjectKey($objectKey);
        $asset = $this->findStored($disk->diskCode(), $objectKey);
        if ($asset instanceof FileAsset && $asset->isDeleted()) {
            if ($asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE) {
                $this->assertMutationAccess($asset, $access);
            }
            if (!$disk->delete($objectKey)) {
                throw new \RuntimeException((string)__('存储对象删除失败。'));
            }
            return;
        }
        if (!$asset instanceof FileAsset) {
            if (!$disk->delete($objectKey)) {
                throw new \RuntimeException((string)__('存储对象删除失败。'));
            }
            return;
        }
        $this->assertMutationAccess($asset, $access);
        $this->assertUnreferenced($asset);
        $connection = $asset->getConnection();
        $deletePhysical = function () use ($disk, $objectKey): void {
            if (!$disk->delete($objectKey)) {
                throw new \RuntimeException((string)__('存储对象删除失败。'));
            }
        };
        $disable = function () use ($asset, $connection, $deletePhysical): void {
            $current = $this->lockAssetsForMutation([$asset])[$asset->getAssetId()];
            $this->assertUnreferenced($current);
            $current->setData(FileAsset::schema_fields_LIFECYCLE_STATE, FileAsset::STATE_DRAFT);
            $current->setData(FileAsset::schema_fields_DELETED_AT, date('Y-m-d H:i:s'));
            $current->save();
            $this->transactions->afterCommit(
                $connection,
                'file_asset_delete_' . $current->getAssetId(),
                $deletePhysical,
            );
        };
        if ($this->transactions->isActive($connection)) {
            $this->assertWriteIntent();
            $this->transactions->withSavepoint($connection, 'file_asset_delete', $disable);
        } else {
            $this->transactions->runWrite($connection, $disable);
        }
    }

    public function deleteDirectory(
        string $diskCode,
        string $prefix,
        FileAccessContext $access,
    ): void
    {
        $this->assertManagementContext($access);
        $disk = $this->storage->disk($diskCode);
        $prefix = $this->requireObjectKey($prefix);
        $assets = [];
        foreach ($this->underPrefixFromRows($this->storedOnDisk($disk->diskCode()), $prefix, true) as $asset) {
            if ($asset->isDeleted()) {
                if ($asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE) {
                    $this->assertMutationAccess($asset, $access);
                }
                continue;
            }
            $this->assertMutationAccess($asset, $access);
            $this->assertUnreferenced($asset);
            $assets[] = $asset;
        }
        if ($assets === []) {
            if (!$disk->deleteDirectory($prefix)) {
                throw new \RuntimeException((string)__('存储目录未完全删除。'));
            }
            return;
        }
        $connection = $this->assets->getConnection();
        $deletePhysical = static function () use ($disk, $prefix): void {
            if (!$disk->deleteDirectory($prefix)) {
                throw new \RuntimeException((string)__('存储目录未完全删除，相关资源已禁用并等待清理。'));
            }
        };
        $disable = function () use ($assets, $connection, $deletePhysical, $disk, $prefix): void {
            $locked = $this->lockAssetsForMutation($assets);
            $now = date('Y-m-d H:i:s');
            foreach ($assets as $asset) {
                $current = $locked[$asset->getAssetId()];
                $this->assertUnreferenced($current);
                $current->setData(FileAsset::schema_fields_LIFECYCLE_STATE, FileAsset::STATE_DRAFT);
                $current->setData(FileAsset::schema_fields_DELETED_AT, $now);
                $current->save();
            }
            $this->transactions->afterCommit(
                $connection,
                'file_asset_directory_delete_' . hash('sha256', $disk->diskCode() . "\0" . $prefix),
                $deletePhysical,
            );
        };
        if ($this->transactions->isActive($connection)) {
            $this->assertWriteIntent();
            $this->transactions->withSavepoint($connection, 'file_asset_directory_delete', $disable);
        } else {
            $this->transactions->runWrite($connection, $disable);
        }
    }

    private function assertUnreferenced(FileAsset $asset): void
    {
        if ($this->references->isReferenced($asset->getAssetId())) {
            throw new \RuntimeException((string)__('文件资源仍被业务内容引用，不能删除。'));
        }
    }

    private function assertMutationAccess(FileAsset $asset, FileAccessContext $access): void
    {
        $this->accessPolicy->assertCanManage($asset, $access);
    }

    private function assertManagementContext(FileAccessContext $access): void
    {
        if (!in_array($access->purpose, ['media_manager', 'metadata_edit'], true)) {
            throw new FileAccessDeniedException((string)__('当前文件访问上下文不允许修改存储对象。'));
        }
    }

    private function assertWriteIntent(): void
    {
        $connection = $this->assets->getConnection();
        if (!$this->transactions->isWriteIntent($connection)) {
            throw new \LogicException((string)__('文件资源修改必须位于写意图事务内。'));
        }
    }

    /** @param list<FileAsset> $expected @return array<string,FileAsset> */
    private function lockAssetsForMutation(array $expected): array
    {
        $expectedById = [];
        foreach ($expected as $asset) {
            if (!$asset instanceof FileAsset || $asset->getAssetId() === '') {
                throw new \RuntimeException((string)__('文件资源身份无效。'));
            }
            $expectedById[$asset->getAssetId()] = $asset;
        }
        $ids = array_keys($expectedById);
        sort($ids, SORT_STRING);
        $locked = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $query = clone $this->assets;
            $query->clearData()->reset()
                ->where(FileAsset::schema_fields_ID, $chunk, 'IN')
                ->order(FileAsset::schema_fields_ID, 'ASC');
            if ($this->supportsForUpdate()) {
                $query->additional('FOR UPDATE');
            }
            foreach ($query->select()->fetch()->getItems() as $current) {
                if (!$current instanceof FileAsset) {
                    continue;
                }
                $expectedAsset = $expectedById[$current->getAssetId()] ?? null;
                if (!$expectedAsset instanceof FileAsset
                    || !hash_equals($expectedAsset->getDiskCode(), $current->getDiskCode())
                    || !hash_equals($expectedAsset->getObjectKey(), $current->getObjectKey())
                    || $expectedAsset->isDeleted() !== $current->isDeleted()
                    || (int)$expectedAsset->getData(FileAsset::schema_fields_ASSET_REVISION)
                        !== (int)$current->getData(FileAsset::schema_fields_ASSET_REVISION)
                ) {
                    throw new \RuntimeException((string)__('文件资源已被其他请求修改，请刷新后重试。'));
                }
                $locked[$current->getAssetId()] = $current;
            }
        }
        if (count($locked) !== count($expectedById)) {
            throw new \RuntimeException((string)__('文件资源已被其他请求删除。'));
        }
        return $locked;
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->assets->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function requireObjectKey(string $objectKey): string
    {
        $objectKey = trim($objectKey, '/');
        if ($objectKey === '') {
            throw new \InvalidArgumentException((string)__('文件资源对象键不能为空。'));
        }
        StorageObjectReference::assertObjectKey($objectKey);
        return $objectKey;
    }

    private function find(string $diskCode, string $objectKey): ?FileAsset
    {
        $asset = $this->findStored($diskCode, $objectKey);
        return $asset instanceof FileAsset && !$asset->isDeleted() ? $asset : null;
    }

    private function findStored(string $diskCode, string $objectKey): ?FileAsset
    {
        $asset = clone $this->assets;
        $asset->clearData()->reset()
            ->where(FileAsset::schema_fields_OBJECT_IDENTITY_HASH, FileAsset::objectIdentityHash(
                $diskCode,
                trim($objectKey, '/'),
            ))
            ->where(FileAsset::schema_fields_DISK_CODE, $diskCode)
            ->where(FileAsset::schema_fields_OBJECT_KEY, trim($objectKey, '/'))
            ->find()->fetch();
        return $asset->getAssetId() !== '' ? $asset : null;
    }

    /** @return list<FileAsset> */
    private function underPrefix(string $diskCode, string $prefix): array
    {
        return $this->underPrefixFromRows($this->storedOnDisk($diskCode), trim($prefix, '/'));
    }

    /** @return list<FileAsset> */
    private function storedOnDisk(string $diskCode): array
    {
        $rows = (clone $this->assets)->clearData()->reset()
            ->where(FileAsset::schema_fields_DISK_CODE, $diskCode)
            ->limit(self::MAX_DIRECTORY_ASSETS + 1)
            ->select()
            ->fetch()
            ->getItems();
        if (count($rows) > self::MAX_DIRECTORY_ASSETS) {
            throw new \RuntimeException((string)__('磁盘中的文件资源超过单次目录操作扫描上限。'));
        }
        return array_values(array_filter($rows, static fn (mixed $asset): bool => $asset instanceof FileAsset));
    }

    /** @param list<FileAsset> $rows @return list<FileAsset> */
    private function underPrefixFromRows(array $rows, string $prefix, bool $includeDeleted = false): array
    {
        $prefix = trim($prefix, '/');
        $result = [];
        foreach ($rows as $asset) {
            if (!$includeDeleted && $asset->isDeleted()) {
                continue;
            }
            $key = $asset->getObjectKey();
            if ($key !== $prefix && !str_starts_with($key, $prefix . '/')) {
                continue;
            }
            if (count($result) >= self::MAX_DIRECTORY_ASSETS) {
                throw new \RuntimeException((string)__('目录中的文件资源超过单次操作上限。'));
            }
            $result[] = $asset;
        }
        return $result;
    }

    private function purgeSoftDeleted(FileAsset $asset): void
    {
        if (!$asset->isDeleted() || $asset->getAssetId() === '') {
            throw new \RuntimeException((string)__('目标文件资源状态已变化，请刷新后重试。'));
        }
        $assetId = $asset->getAssetId();
        (clone $this->locales)->clearData()->reset()
            ->where(FileAssetLocale::schema_fields_ASSET_ID, $assetId)
            ->delete()->fetch();
        (clone $this->assets)->clearData()->reset()
            ->where(FileAsset::schema_fields_ID, $assetId)
            ->where(
                FileAsset::schema_fields_DELETED_AT,
                (string)$asset->getData(FileAsset::schema_fields_DELETED_AT),
            )
            ->delete()->fetch();
        $remaining = (clone $this->assets)->clearData()->reset()
            ->where(FileAsset::schema_fields_ID, $assetId)
            ->find()->fetch();
        if ($remaining->getAssetId() !== '') {
            throw new \RuntimeException((string)__('目标文件资源状态已变化，请刷新后重试。'));
        }
    }

    private function directoryEntryExists(string $diskCode, string $path): bool
    {
        $path = trim($path, '/');
        $parent = trim(dirname($path), '/.');
        foreach ($this->directories->list($diskCode, $parent, false) as $entry) {
            if ((string)($entry['path'] ?? '') === $path) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rebuild the complete source tree before removing the target copy.
     *
     * A provider may have removed only part of the source directory before a
     * database transaction rolls back. Merely deleting the target in that case
     * would turn a recoverable duplicate into data loss.
     */
    private function restoreDirectoryFromTarget(
        \Weline\Storage\Api\StorageDiskInterface $disk,
        string $sourcePrefix,
        string $targetPrefix,
    ): void {
        $items = $disk->list($targetPrefix, true);
        if (count($items) > self::MAX_DIRECTORY_ASSETS) {
            throw new \RuntimeException((string)__('目录移动补偿恢复超过条目上限。'));
        }
        if (!$this->directoryEntryExists($disk->diskCode(), $sourcePrefix)
            && !$disk->makeDirectory($sourcePrefix)
        ) {
            throw new \RuntimeException((string)__('目录移动补偿恢复源目录失败。'));
        }

        foreach ($items as $index => $item) {
            $targetKey = $item->object->objectKey;
            if ($targetKey !== $targetPrefix && !str_starts_with($targetKey, $targetPrefix . '/')) {
                throw new \RuntimeException((string)__('目录移动补偿遇到越界对象。'));
            }
            $suffix = ltrim(substr($targetKey, strlen($targetPrefix)), '/');
            $sourceKey = $suffix === '' ? $sourcePrefix : $sourcePrefix . '/' . $suffix;
            if (($item->metadata['type'] ?? 'file') === 'directory') {
                if (!$this->directoryEntryExists($disk->diskCode(), $sourceKey)
                    && !$disk->makeDirectory($sourceKey)
                ) {
                    throw new \RuntimeException((string)__('目录移动补偿恢复源目录失败。'));
                }
            } elseif (!$disk->exists($sourceKey)) {
                $disk->copy($targetKey, $sourceKey);
            }
            if (($index + 1) % 256 === 0) {
                \Weline\Framework\Runtime\SchedulerSystem::yield();
            }
        }
        if (!$disk->deleteDirectory($targetPrefix)) {
            throw new \RuntimeException((string)__('目录移动补偿清理目标目录失败。'));
        }
    }
}
