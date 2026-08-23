<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\Data\StorageUrlOptions;
use Weline\Storage\Api\StorageDiskInterface;
use Weline\Storage\Api\StorageConfigSnapshotGuardInterface;
use Weline\Storage\Api\StorageManagerInterface;

final class FileAssetUploadService
{
    private const MAX_PRIVATE_POLICY_LIST_ITEMS = 256;
    public function __construct(
        private readonly StorageManagerInterface $storage,
        private readonly FileAsset $assets,
        private readonly FileAssetLocale $locales,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly StorageConfigSnapshotGuardInterface $snapshotGuard,
    ) {
    }

    /**
     * @param resource $source
     * @param array{display_name?:string,default_alt?:string,description?:string,default_caption?:string,translation_state?:string,translation_origin?:string} $localeMetadata
     * @param array<string,mixed> $metadata
     */
    public function upload(
        string $diskCode,
        string $objectKey,
        mixed $source,
        string $originalName,
        string $mimeType,
        string $localeCode,
        array $localeMetadata,
        string $visibility = FileAsset::VISIBILITY_PUBLIC,
        array $metadata = [],
        ?int $width = null,
        ?int $height = null,
    ): FileAsset {
        if (!is_resource($source)) {
            throw new \InvalidArgumentException((string)__('上传源必须是流。'));
        }
        $localeCode = FileAssetManager::normalizeLocale($localeCode);
        $disk = $this->storage->disk($diskCode);
        $visibility = strtolower(trim($visibility));
        $this->assertVisibilitySupported($disk, $objectKey, $visibility, $metadata);
        $existing = $this->findExisting($disk->diskCode(), $objectKey);
        if ($existing instanceof FileAsset && !$existing->isDeleted()) {
            throw new \RuntimeException((string)__('目标文件已存在，禁止覆盖。'));
        }
        [$stat, $sourceSha256] = $this->writeAndHash($disk, $objectKey, $source, $mimeType);

        $asset = null;
        try {
            $persist = function () use (
                &$asset,
                $disk,
                $stat,
                $originalName,
                $mimeType,
                $sourceSha256,
                $width,
                $height,
                $localeCode,
                $visibility,
                $metadata,
                $localeMetadata,
                $existing,
            ): void {
                $this->snapshotGuard->assertWritable($disk->snapshot());
                // Failed batch rollbacks retain a disabled audit row. Purge that
                // identity transactionally before recreating the same path so a
                // retry receives a fresh asset ID and no stale locale metadata.
                if ($existing instanceof FileAsset) {
                    $this->purgeSoftDeleted($this->lockSoftDeletedForReuse($existing));
                }
                $asset = clone $this->assets;
                $asset->clearData();
                $asset->setData(FileAsset::schema_fields_DISK_CODE, $disk->diskCode());
                $asset->setData(FileAsset::schema_fields_OBJECT_KEY, $stat->object->objectKey);
                $asset->setData(FileAsset::schema_fields_ORIGINAL_NAME, trim($originalName));
                $asset->setData(FileAsset::schema_fields_MIME_TYPE, trim($mimeType) ?: ($stat->mimeType ?? 'application/octet-stream'));
                $asset->setData(FileAsset::schema_fields_BYTES, $stat->bytes);
                $asset->setData(
                    FileAsset::schema_fields_SHA256,
                    $sourceSha256,
                );
                $asset->setData(FileAsset::schema_fields_WIDTH, $width !== null && $width > 0 ? $width : null);
                $asset->setData(FileAsset::schema_fields_HEIGHT, $height !== null && $height > 0 ? $height : null);
                $asset->setData(FileAsset::schema_fields_DEFAULT_LOCALE, $localeCode);
                $asset->setData(FileAsset::schema_fields_VISIBILITY, $visibility);
                $asset->setData(FileAsset::schema_fields_LIFECYCLE_STATE, FileAsset::STATE_DRAFT);
                $asset->setData(FileAsset::schema_fields_METADATA, json_encode(
                    $metadata,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ));
                $asset->save();

                $savedLocale = $this->saveLocale($asset, $localeCode, $localeMetadata);
                if ($savedLocale->isReviewed() && $this->hasRequiredMetadata($localeMetadata)) {
                    $asset->setData(FileAsset::schema_fields_LIFECYCLE_STATE, FileAsset::STATE_READY);
                    $asset->save();
                }
            };
            $connection = $this->assets->getConnection();
            $nestedTransaction = $this->transactions->isActive($connection);
            if ($nestedTransaction) {
                if (!$this->transactions->isWriteIntent($connection)) {
                    throw new \LogicException((string)__('文件资源上传必须从根边界使用写意图事务。'));
                }
                $this->transactions->afterRollback(
                    $connection,
                    'file_asset_object_rollback_' . hash('sha256', $disk->diskCode() . "\0" . $stat->object->objectKey),
                    static function () use ($disk, $stat): void {
                        $deleted = $disk->delete($stat->object->objectKey);
                        if (!$deleted && $disk->exists($stat->object->objectKey)) {
                            throw new \RuntimeException((string)__('存储对象删除失败。'));
                        }
                    },
                );
                $this->transactions->withSavepoint($connection, 'file_asset_upload', $persist);
            } else {
                $this->transactions->runWrite($connection, $persist);
            }
            if (!$asset instanceof FileAsset || $asset->getAssetId() === '') {
                throw new \RuntimeException((string)__('文件资源写入结果无效。'));
            }
            return $asset;
        } catch (\Throwable $throwable) {
            try {
                $deleted = $disk->delete($stat->object->objectKey);
                if (!$deleted && $disk->exists($stat->object->objectKey)) {
                    throw new \RuntimeException((string)__('存储对象删除失败。'));
                }
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    (string)__('文件资源写入失败，且存储对象无法自动回收，请立即人工清理。'),
                    0,
                    $cleanupFailure,
                );
            }
            throw $throwable;
        }
    }

    /** @param array<string,mixed> $metadata */
    public function saveLocale(FileAsset $asset, string $localeCode, array $metadata): FileAssetLocale
    {
        $localeCode = FileAssetManager::normalizeLocale($localeCode);
        $translationState = (string)($metadata['translation_state'] ?? FileAssetLocale::STATE_REVIEWED);
        $translationOrigin = (string)($metadata['translation_origin'] ?? FileAssetLocale::ORIGIN_MANUAL);
        if ($translationState === FileAssetLocale::STATE_REVIEWED && !$this->hasRequiredMetadata($metadata)) {
            throw new \InvalidArgumentException((string)__('审核通过的资源语言必须填写名称、默认 alt 和描述。'));
        }
        $model = clone $this->locales;
        $model->clearData()->reset()
            ->where(FileAssetLocale::schema_fields_ASSET_ID, $asset->getAssetId())
            ->where(FileAssetLocale::schema_fields_LOCALE_CODE, $localeCode)
            ->find()->fetch();
        if ($translationOrigin === FileAssetLocale::ORIGIN_MACHINE
            && $translationState !== FileAssetLocale::STATE_DRAFT
            && ((int)$model->getData(FileAssetLocale::schema_fields_ID) < 1
                || $model->getData(FileAssetLocale::schema_fields_TRANSLATION_ORIGIN)
                    !== FileAssetLocale::ORIGIN_MACHINE)
        ) {
            throw new \InvalidArgumentException((string)__('机器翻译的资源元数据必须先保存为待审核草稿。'));
        }
        $model->setData(FileAssetLocale::schema_fields_ASSET_ID, $asset->getAssetId());
        $model->setData(FileAssetLocale::schema_fields_LOCALE_CODE, $localeCode);
        $model->setData(FileAssetLocale::schema_fields_DISPLAY_NAME, trim((string)($metadata['display_name'] ?? '')));
        $model->setData(FileAssetLocale::schema_fields_DEFAULT_ALT, trim((string)($metadata['default_alt'] ?? '')));
        $model->setData(FileAssetLocale::schema_fields_DESCRIPTION, trim((string)($metadata['description'] ?? '')));
        $model->setData(FileAssetLocale::schema_fields_DEFAULT_CAPTION, isset($metadata['default_caption']) ? trim((string)$metadata['default_caption']) : null);
        $model->setData(FileAssetLocale::schema_fields_TRANSLATION_STATE, $translationState);
        $model->setData(FileAssetLocale::schema_fields_TRANSLATION_ORIGIN, $translationOrigin);
        $model->save();
        // Locale metadata participates in the FileAsset cache identity.
        $asset->save();
        return $model;
    }

    public function hasRequiredMetadata(array $metadata): bool
    {
        return trim((string)($metadata['display_name'] ?? '')) !== ''
            && trim((string)($metadata['default_alt'] ?? '')) !== ''
            && trim((string)($metadata['description'] ?? '')) !== '';
    }

    private function findExisting(string $diskCode, string $objectKey): ?FileAsset
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

    private function lockSoftDeletedForReuse(FileAsset $expected): FileAsset
    {
        $query = clone $this->assets;
        $query->clearData()->reset()
            ->where(FileAsset::schema_fields_ID, $expected->getAssetId())
            ->limit(1);
        if ($this->supportsForUpdate()) {
            $query->additional('FOR UPDATE');
        }
        $items = array_values($query->select()->fetch()->getItems());
        $current = $items[0] ?? null;
        if (!$current instanceof FileAsset
            || $current->getAssetId() === ''
            || !$current->isDeleted()
            || !hash_equals($expected->getDiskCode(), $current->getDiskCode())
            || !hash_equals($expected->getObjectKey(), $current->getObjectKey())
            || (int)$expected->getData(FileAsset::schema_fields_ASSET_REVISION)
                !== (int)$current->getData(FileAsset::schema_fields_ASSET_REVISION)
        ) {
            throw new \RuntimeException((string)__('目标文件资源状态已变化，请刷新后重试。'));
        }
        return $current;
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->assets->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    /**
     * Upload and hash the exact same byte sequence in one bounded pass.
     *
     * @param resource $source
     * @return array{0:\Weline\Storage\Api\Data\StorageObjectStat,1:string}
     */
    private function writeAndHash(
        StorageDiskInterface $disk,
        string $objectKey,
        mixed $source,
        string $mimeType,
    ): array
    {
        $handle = $disk->openWrite($objectKey, [
            'overwrite' => false,
            'content_type' => $mimeType,
        ]);
        $hash = hash_init('sha256');
        $emptyReads = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException((string)__('读取上传流失败。'));
                }
                if ($chunk === '') {
                    if (++$emptyReads >= 3) {
                        throw new \RuntimeException((string)__('读取上传流时连续无数据进展。'));
                    }
                    SchedulerSystem::yield();
                    continue;
                }
                $emptyReads = 0;
                hash_update($hash, $chunk);
                $handle->write($chunk);
                SchedulerSystem::yield();
            }
            $sha256 = hash_final($hash);
            return [$handle->complete(), $sha256];
        } catch (\Throwable $throwable) {
            if (!$handle->isClosed()) {
                try {
                    $handle->abort();
                } catch (\Throwable) {
                    // The request registry retains cleanup debt and will drain
                    // a persistent worker if the abort cannot complete.
                }
            }
            throw $throwable;
        }
    }

    /** @param array<string,mixed> $metadata */
    private function assertVisibilitySupported(
        StorageDiskInterface $disk,
        string $objectKey,
        string $visibility,
        array $metadata,
    ): void {
        if (!in_array($visibility, [FileAsset::VISIBILITY_PUBLIC, FileAsset::VISIBILITY_PRIVATE], true)) {
            throw new \InvalidArgumentException((string)__('文件资源可见性无效。'));
        }
        $diskVisibility = $disk->snapshot()->visibility();
        if (!in_array($diskVisibility, [FileAsset::VISIBILITY_PUBLIC, FileAsset::VISIBILITY_PRIVATE], true)) {
            throw new \RuntimeException((string)__('存储磁盘可见性配置无效。'));
        }
        // v1 drivers expose visibility at disk/bucket level. Pretending that a
        // public bucket contains a private FileAsset (or the reverse) would
        // either leak the object or make it permanently unresolvable.
        if ($visibility !== $diskVisibility) {
            throw new \InvalidArgumentException((string)__(
                '文件资源可见性必须与所选磁盘可见性一致。',
            ));
        }
        if ($visibility === FileAsset::VISIBILITY_PRIVATE) {
            $this->assertPrivatePolicy($metadata);
            $resolved = $disk->resolveUrl(
                $objectKey,
                new StorageUrlOptions(StorageUrlOptions::KIND_TEMPORARY, 300),
            );
            if ($resolved->kind !== StorageUrlOptions::KIND_TEMPORARY
                || $resolved->cacheable
                || $resolved->expiresAt === null
                || $resolved->expiresAt <= time()
                || $resolved->expiresAt > time() + 360
            ) {
                throw new \RuntimeException((string)__('私有存储 URL 适配器未提供不可缓存的临时 URL。'));
            }
            return;
        }
        $disk->resolveUrl($objectKey, new StorageUrlOptions(StorageUrlOptions::KIND_PUBLIC));
    }

    /** @param array<string,mixed> $metadata */
    private function assertPrivatePolicy(array $metadata): void
    {
        $policy = $metadata['access_policy'] ?? null;
        if (!is_array($policy)
            || (array_key_exists('policy_revision', $policy)
                && !$this->isPositivePolicyInteger($policy['policy_revision']))
            || (array_key_exists('owner_actor_id', $policy)
                && !$this->isPositivePolicyInteger($policy['owner_actor_id']))
        ) {
            throw new \InvalidArgumentException((string)__('私有文件必须配置有效访问策略。'));
        }
        $owner = (int)($policy['owner_actor_id'] ?? 0);
        foreach (['allowed_actor_ids', 'allowed_scope_keys', 'allowed_roles'] as $listKey) {
            if (isset($policy[$listKey])
                && (!is_array($policy[$listKey])
                    || !array_is_list($policy[$listKey])
                    || count($policy[$listKey]) > self::MAX_PRIVATE_POLICY_LIST_ITEMS)
            ) {
                throw new \InvalidArgumentException((string)__('私有文件访问策略列表无效或超过限制。'));
            }
        }
        $actors = $policy['allowed_actor_ids'] ?? [];
        foreach ($actors as $actorId) {
            if (!$this->isPositivePolicyInteger($actorId)) {
                throw new \InvalidArgumentException((string)__('私有文件访问策略列表无效或超过限制。'));
            }
        }
        $scopes = $policy['allowed_scope_keys'] ?? [];
        foreach ($scopes as $scope) {
            if (!$this->isPolicyString($scope, 512)) {
                throw new \InvalidArgumentException((string)__('私有文件访问策略列表无效或超过限制。'));
            }
        }
        $roles = $policy['allowed_roles'] ?? [];
        foreach ($roles as $role) {
            if (!$this->isPolicyString($role, 128)) {
                throw new \InvalidArgumentException((string)__('私有文件访问策略列表无效或超过限制。'));
            }
        }
        if ($owner < 1 && $actors === [] && $scopes === [] && $roles === []) {
            throw new \InvalidArgumentException((string)__('私有文件访问策略不能为空。'));
        }
    }

    private function isPositivePolicyInteger(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value)))
            && (int)$value > 0;
    }

    private function isPolicyString(mixed $value, int $maxBytes): bool
    {
        return is_string($value)
            && trim($value) !== ''
            && strlen($value) <= $maxBytes
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
