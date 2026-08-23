<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAccessPolicyInterface;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Storage\Api\Data\StorageUrlOptions;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\StorageManagerInterface;

final class FileAssetLibrary implements FileAssetLibraryInterface
{
    public function __construct(
        private readonly FileAsset $assets,
        private readonly FileAssetLocale $locales,
        private readonly StorageManagerInterface $storage,
        private readonly FileAssetManagerInterface $assetManager,
        private readonly FileAccessPolicyInterface $accessPolicy,
        private readonly FileAssetUploadService $uploads,
        private readonly FileAssetMutationService $mutations,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    public function describe(
        string $diskCode,
        string $objectKey,
        string $localeCode,
        FileAccessContext $access,
    ): array {
        $canonical = $this->storage->canonicalizeDiskCode($diskCode);
        $objectKey = trim($objectKey, '/');
        $localeCode = $this->normalizeLocale($localeCode);
        $asset = $this->findStoredByObject($canonical, $objectKey);
        if ($asset instanceof FileAsset && $asset->isDeleted()) {
            if ($asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE) {
                $this->accessPolicy->assertCanManage($asset, $access);
            }
            $asset = null;
        }
        if (!$asset instanceof FileAsset) {
            return [
                'asset_id' => null,
                'disk_code' => $canonical,
                'object_key' => $objectKey,
                'locale_code' => $localeCode,
                'asset_ready' => false,
                'asset_selectable' => false,
            ];
        }
        $this->accessPolicy->assertCanRead($asset, $access);

        $locale = $this->findLocale($asset->getAssetId(), $localeCode);
        $hasLocale = $locale instanceof FileAssetLocale;
        $reviewed = $hasLocale && $locale->isReviewed();
        $displayName = $hasLocale
            ? trim((string)$locale->getData(FileAssetLocale::schema_fields_DISPLAY_NAME))
            : '';
        $defaultAlt = $hasLocale
            ? trim((string)$locale->getData(FileAssetLocale::schema_fields_DEFAULT_ALT))
            : '';
        $description = $hasLocale
            ? trim((string)$locale->getData(FileAssetLocale::schema_fields_DESCRIPTION))
            : '';
        $ready = $asset->isReady();
        $selectable = $ready && $reviewed
            && $displayName !== '' && $defaultAlt !== '' && $description !== '';

        $previewUrl = null;
        try {
            $previewUrl = $this->assetManager->resolveUrl($asset->getAssetId(), $access)->url;
        } catch (\Throwable) {
            $selectable = false;
        }

        return [
            'asset_id' => $asset->getAssetId(),
            'disk_code' => $canonical,
            'object_key' => $asset->getObjectKey(),
            'original_name' => (string)$asset->getData(FileAsset::schema_fields_ORIGINAL_NAME),
            'mime' => $asset->getMimeType(),
            'size' => max(0, (int)$asset->getData(FileAsset::schema_fields_BYTES)),
            'width' => (int)$asset->getData(FileAsset::schema_fields_WIDTH) ?: null,
            'height' => (int)$asset->getData(FileAsset::schema_fields_HEIGHT) ?: null,
            'default_locale' => $asset->getDefaultLocale(),
            'visibility' => $asset->getVisibility(),
            'lifecycle_state' => (string)$asset->getData(FileAsset::schema_fields_LIFECYCLE_STATE),
            'asset_revision' => max(1, (int)$asset->getData(FileAsset::schema_fields_ASSET_REVISION)),
            'sha256' => (string)$asset->getData(FileAsset::schema_fields_SHA256),
            'created_at' => (string)$asset->getData(FileAsset::schema_fields_CREATED_AT),
            'updated_at' => (string)$asset->getData(FileAsset::schema_fields_UPDATED_AT),
            'asset_ready' => $ready,
            'asset_selectable' => $selectable,
            'locale_code' => $localeCode,
            'display_name' => $displayName,
            'default_alt' => $defaultAlt,
            'description' => $description,
            'default_caption' => $hasLocale
                ? (string)$locale->getData(FileAssetLocale::schema_fields_DEFAULT_CAPTION)
                : '',
            'translation_state' => $hasLocale
                ? (string)$locale->getData(FileAssetLocale::schema_fields_TRANSLATION_STATE)
                : '',
            'translation_origin' => $hasLocale
                ? (string)$locale->getData(FileAssetLocale::schema_fields_TRANSLATION_ORIGIN)
                : '',
            'preview_url' => $previewUrl,
        ];
    }

    public function resolveResourceUrl(
        string $diskCode,
        string $objectKey,
        FileAccessContext $access,
        ?StorageUrlOptions $options = null,
    ): string {
        $canonical = $this->storage->canonicalizeDiskCode($diskCode);
        $asset = $this->findStoredByObject($canonical, trim($objectKey, '/'));
        if ($asset instanceof FileAsset) {
            if ($asset->isDeleted()) {
                if ($asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE) {
                    $this->accessPolicy->assertCanManage($asset, $access);
                }
                throw new \RuntimeException((string)__('文件资源不可用。'));
            }
            return $this->assetManager->resolveUrl($asset->getAssetId(), $access, $options)->url;
        }

        // Read-only compatibility for local objects created before FileAsset.
        // Such objects remain unselectable until they are registered.
        if ($canonical === StorageDiskCode::BUILTIN_LOCAL_MEDIA) {
            $disk = $this->storage->disk($canonical);
            if (!$disk->exists($objectKey)) {
                throw new \RuntimeException((string)__('存储对象不存在。'));
            }
            return $disk->resolveUrl($objectKey, $options)->url;
        }
        throw new \RuntimeException((string)__('存储对象尚未建立 FileAsset，不能访问。'));
    }

    public function upload(
        string $diskCode,
        string $objectKey,
        mixed $source,
        string $originalName,
        string $mimeType,
        string $localeCode,
        FileAccessContext $access,
        array $localeMetadata,
        string $visibility = self::VISIBILITY_PUBLIC,
        array $metadata = [],
        ?int $width = null,
        ?int $height = null,
    ): array {
        $canonical = $this->storage->canonicalizeDiskCode($diskCode);
        $objectKey = trim($objectKey, '/');
        $this->assertStalePrivateIdentityAccess($canonical, $objectKey, $access);
        $this->assertUploadAccess($visibility, $metadata, $access);
        $asset = $this->uploads->upload(
            $canonical,
            $objectKey,
            $source,
            $originalName,
            $mimeType,
            $localeCode,
            $localeMetadata,
            $visibility,
            $metadata,
            $width,
            $height,
        );

        try {
            return $this->describe($asset->getDiskCode(), $asset->getObjectKey(), $localeCode, $access);
        } catch (\Throwable $throwable) {
            // The batch caller cannot record this object for rollback until the
            // descriptor returns. Reclaim it here if post-persist description
            // unexpectedly fails.
            try {
                $this->mutations->deleteObject($asset->getDiskCode(), $asset->getObjectKey(), $access);
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    (string)__('上传失败，且已写入文件无法自动回收，请立即人工清理。'),
                    0,
                    $cleanupFailure,
                );
            }
            throw $throwable;
        }
    }

    public function saveMetadata(
        string $assetId,
        string $diskCode,
        string $objectKey,
        string $localeCode,
        FileAccessContext $access,
        int $expectedRevision,
        array $metadata,
    ): array {
        if ($expectedRevision < 1) {
            throw new \InvalidArgumentException((string)__('文件资源修订版本无效。'));
        }
        $asset = $this->assetManager->get($assetId);
        $this->accessPolicy->assertCanManage($asset, $access);
        if ($asset->isDeleted()) {
            throw new \RuntimeException((string)__('文件资源不可用。'));
        }
        $canonical = $this->storage->canonicalizeDiskCode($diskCode);
        $objectKey = trim($objectKey, '/');
        if (!hash_equals($asset->getDiskCode(), $canonical)
            || !hash_equals($asset->getObjectKey(), $objectKey)
        ) {
            throw new \InvalidArgumentException((string)__('资源身份与当前存储对象不匹配。'));
        }
        $localeCode = $this->normalizeLocale($localeCode);
        $metadata['translation_state'] = (string)($metadata['translation_state'] ?? FileAssetLocale::STATE_REVIEWED);
        $metadata['translation_origin'] = (string)($metadata['translation_origin'] ?? FileAssetLocale::ORIGIN_MANUAL);
        $save = function () use ($asset, $localeCode, $metadata, $access, $expectedRevision): void {
            $current = $this->lockAssetForMetadataMutation($asset, $expectedRevision);
            $this->accessPolicy->assertCanManage($current, $access);
            $locale = $this->uploads->saveLocale($current, $localeCode, $metadata);
            if ($localeCode === $current->getDefaultLocale()) {
                $ready = $locale->isReviewed() && $this->uploads->hasRequiredMetadata($metadata);
                $current->setData(
                    FileAsset::schema_fields_LIFECYCLE_STATE,
                    $ready ? FileAsset::STATE_READY : FileAsset::STATE_DRAFT,
                );
                $current->save();
            }
        };
        $connection = $asset->getConnection();
        if ($this->transactions->isActive($connection)) {
            if (!$this->transactions->isWriteIntent($connection)) {
                throw new \LogicException((string)__('文件资源元数据保存必须位于写意图事务内。'));
            }
            $this->transactions->withSavepoint($connection, 'file_asset_metadata_save', $save);
        } else {
            $this->transactions->runWrite($connection, $save);
        }

        return $this->describe($canonical, $objectKey, $localeCode, $access);
    }

    public function moveObject(
        string $diskCode,
        string $from,
        string $to,
        FileAccessContext $access,
    ): void
    {
        $this->mutations->moveObject($diskCode, $from, $to, $access);
    }

    public function moveDirectory(
        string $diskCode,
        string $from,
        string $to,
        FileAccessContext $access,
    ): void
    {
        $this->mutations->moveDirectory($diskCode, $from, $to, $access);
    }

    public function deleteObject(
        string $diskCode,
        string $objectKey,
        FileAccessContext $access,
    ): void
    {
        $this->mutations->deleteObject($diskCode, $objectKey, $access);
    }

    public function deleteDirectory(
        string $diskCode,
        string $prefix,
        FileAccessContext $access,
    ): void
    {
        $this->mutations->deleteDirectory($diskCode, $prefix, $access);
    }

    public function normalizeLocale(string $localeCode): string
    {
        return FileAssetManager::normalizeLocale($localeCode);
    }

    /** @param array<string,mixed> $metadata */
    private function assertUploadAccess(string $visibility, array $metadata, FileAccessContext $access): void
    {
        $candidate = clone $this->assets;
        $candidate->clearData();
        $candidate->setData(FileAsset::schema_fields_VISIBILITY, $visibility);
        $candidate->setData(FileAsset::schema_fields_LIFECYCLE_STATE, FileAsset::STATE_READY);
        $candidate->setData(FileAsset::schema_fields_METADATA, json_encode(
            $metadata,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->accessPolicy->assertCanManage($candidate, $access);
    }

    private function assertStalePrivateIdentityAccess(
        string $diskCode,
        string $objectKey,
        FileAccessContext $access,
    ): void {
        $existing = $this->findStoredByObject($diskCode, $objectKey);
        if (!$existing instanceof FileAsset
            || !$existing->isDeleted()
            || $existing->getVisibility() !== FileAsset::VISIBILITY_PRIVATE
        ) {
            return;
        }
        $this->accessPolicy->assertCanManage($existing, $access);
    }

    private function lockAssetForMetadataMutation(FileAsset $expected, int $expectedRevision): FileAsset
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
        if (!$current instanceof FileAsset) {
            throw new \RuntimeException((string)__('文件资源已被其他请求删除。'));
        }
        if ($current->isDeleted()
            || !hash_equals($expected->getDiskCode(), $current->getDiskCode())
            || !hash_equals($expected->getObjectKey(), $current->getObjectKey())
            || $expectedRevision !== (int)$current->getData(FileAsset::schema_fields_ASSET_REVISION)
            || (int)$expected->getData(FileAsset::schema_fields_ASSET_REVISION)
                !== (int)$current->getData(FileAsset::schema_fields_ASSET_REVISION)
        ) {
            throw new \RuntimeException((string)__('文件资源已被其他请求修改，请刷新后重试。'));
        }
        return $current;
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->assets->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function findStoredByObject(string $diskCode, string $objectKey): ?FileAsset
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

    private function findLocale(string $assetId, string $localeCode): ?FileAssetLocale
    {
        $locale = clone $this->locales;
        $locale->clearData()->reset()
            ->where(FileAssetLocale::schema_fields_ASSET_ID, $assetId)
            ->where(FileAssetLocale::schema_fields_LOCALE_CODE, $localeCode)
            ->find()->fetch();

        return (int)$locale->getData(FileAssetLocale::schema_fields_ID) > 0 ? $locale : null;
    }
}
