<?php

declare(strict_types=1);

namespace Weline\FileManager\Setup\Db\Migration;

use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\StorageManagerInterface;

final class ImportLegacyLocalMedia20260822V110 extends AbstractMigration
{
    private const HASH_CHUNK_BYTES = 1024 * 1024;

    public function getDescription(): string
    {
        return '为旧 pub/media 文件建立 FileAsset 草稿与待审核语言元数据。';
    }

    public function getVersion(): string { return '1.1.0'; }
    public function getDate(): string { return '2026-08-22'; }

    /** @return list<string> */
    public function getAffectedTables(): array
    {
        return [FileAsset::schema_table, FileAssetLocale::schema_table];
    }

    public function requiresBackup(): bool { return false; }
    public function getBackupStrategy(): array { return ['strategy' => 'none', 'tables' => [], 'columns' => []]; }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $assetPrototype = ObjectManager::getInstance(FileAsset::class);
        $localePrototype = ObjectManager::getInstance(FileAssetLocale::class);
        if (!$connection->tableExist($assetPrototype->getTable())
            || !$connection->tableExist($localePrototype->getTable())
        ) {
            return true;
        }

        $root = realpath(rtrim(PUB, '/\\') . DIRECTORY_SEPARATOR . 'media');
        if ($root === false || !is_dir($root)) {
            return true;
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $disk = ObjectManager::getInstance(StorageManagerInterface::class)
            ->disk(StorageDiskCode::BUILTIN_LOCAL_MEDIA);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        $processed = 0;
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $path = $file->getRealPath();
            if ($path === false || !str_starts_with($path, $root)) {
                continue;
            }
            $objectKey = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
            if ($objectKey === '' || str_starts_with($objectKey, '.trash/') || str_starts_with($objectKey, '.tmb/')) {
                continue;
            }
            $existing = (clone $assetPrototype)->clearData()->reset()
                ->where(FileAsset::schema_fields_OBJECT_IDENTITY_HASH, FileAsset::objectIdentityHash(
                    $disk->diskCode(),
                    $objectKey,
                ))
                ->where(FileAsset::schema_fields_DISK_CODE, $disk->diskCode())
                ->where(FileAsset::schema_fields_OBJECT_KEY, $objectKey)
                ->find()->fetch();
            if ($existing->getAssetId() !== '') {
                $this->repairImportedLocaleIfNeeded($existing, $localePrototype, $file->getFilename());
                if (++$processed % 25 === 0) {
                    SchedulerSystem::yield();
                }
                continue;
            }

            $stat = $disk->stat($objectKey);
            $sha256 = $this->sha256($path, $objectKey);
            $mime = $stat->mimeType ?? 'application/octet-stream';
            $dimensions = str_starts_with(strtolower($mime), 'image/') && function_exists('getimagesize')
                ? @getimagesize($path)
                : false;
            $asset = clone $assetPrototype;
            $asset->clearData();
            $asset->setData(FileAsset::schema_fields_DISK_CODE, $disk->diskCode());
            $asset->setData(FileAsset::schema_fields_OBJECT_KEY, $objectKey);
            $asset->setData(FileAsset::schema_fields_ORIGINAL_NAME, $file->getFilename());
            $asset->setData(FileAsset::schema_fields_MIME_TYPE, $mime);
            $asset->setData(FileAsset::schema_fields_BYTES, $stat->bytes);
            $asset->setData(FileAsset::schema_fields_SHA256, $sha256);
            $asset->setData(FileAsset::schema_fields_WIDTH, is_array($dimensions) ? (int)($dimensions[0] ?? 0) ?: null : null);
            $asset->setData(FileAsset::schema_fields_HEIGHT, is_array($dimensions) ? (int)($dimensions[1] ?? 0) ?: null : null);
            $asset->setData(FileAsset::schema_fields_DEFAULT_LOCALE, 'zh_Hans_CN');
            $asset->setData(FileAsset::schema_fields_VISIBILITY, FileAsset::VISIBILITY_PUBLIC);
            $asset->setData(FileAsset::schema_fields_LIFECYCLE_STATE, FileAsset::STATE_DRAFT);
            $asset->setData(FileAsset::schema_fields_METADATA, json_encode([
                'migration' => 'legacy_local_media',
                'legacy_unverified' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $asset->save();

            $this->saveImportedLocale($localePrototype, $asset->getAssetId(), $file->getFilename());
            if (++$processed % 25 === 0) {
                SchedulerSystem::yield();
            }
        }
        return true;
    }

    private function repairImportedLocaleIfNeeded(
        FileAsset $asset,
        FileAssetLocale $localePrototype,
        string $fileName,
    ): void {
        $rawMetadata = trim((string)$asset->getData(FileAsset::schema_fields_METADATA));
        try {
            $metadata = $rawMetadata === '' ? [] : json_decode($rawMetadata, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        if (!is_array($metadata) || ($metadata['migration'] ?? null) !== 'legacy_local_media') {
            return;
        }
        $existingLocale = (clone $localePrototype)->clearData()->reset()
            ->where(FileAssetLocale::schema_fields_ASSET_ID, $asset->getAssetId())
            ->where(FileAssetLocale::schema_fields_LOCALE_CODE, 'zh_Hans_CN')
            ->find()->fetch();
        if ((int)$existingLocale->getData(FileAssetLocale::schema_fields_ID) > 0) {
            return;
        }
        $this->saveImportedLocale($localePrototype, $asset->getAssetId(), $fileName);
    }

    private function saveImportedLocale(
        FileAssetLocale $localePrototype,
        string $assetId,
        string $fileName,
    ): void {
        $label = trim((string)pathinfo($fileName, PATHINFO_FILENAME));
        $label = $label !== '' ? $label : $fileName;
        $locale = clone $localePrototype;
        $locale->clearData();
        $locale->setData(FileAssetLocale::schema_fields_ASSET_ID, $assetId);
        $locale->setData(FileAssetLocale::schema_fields_LOCALE_CODE, 'zh_Hans_CN');
        $locale->setData(FileAssetLocale::schema_fields_DISPLAY_NAME, $label);
        $locale->setData(FileAssetLocale::schema_fields_DEFAULT_ALT, $label);
        $locale->setData(FileAssetLocale::schema_fields_DESCRIPTION, '从旧媒体目录导入，需人工审核。');
        $locale->setData(FileAssetLocale::schema_fields_DEFAULT_CAPTION, null);
        $locale->setData(FileAssetLocale::schema_fields_TRANSLATION_STATE, FileAssetLocale::STATE_DRAFT);
        $locale->setData(FileAssetLocale::schema_fields_TRANSLATION_ORIGIN, FileAssetLocale::ORIGIN_IMPORT);
        $locale->save();
    }

    private function sha256(string $path, string $objectKey): string
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException((string)__('无法读取旧媒体文件：%{1}', [$objectKey]));
        }
        $hash = hash_init('sha256');
        try {
            $emptyReads = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, self::HASH_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new \RuntimeException((string)__('无法计算旧媒体文件校验值：%{1}', [$objectKey]));
                }
                if ($chunk === '') {
                    if (++$emptyReads >= 3) {
                        throw new \RuntimeException((string)__('旧媒体文件校验连续无数据进展：%{1}', [$objectKey]));
                    }
                    SchedulerSystem::yield();
                    continue;
                }
                $emptyReads = 0;
                hash_update($hash, $chunk);
                SchedulerSystem::yield();
            }
        } finally {
            fclose($stream);
        }
        return hash_final($hash);
    }

    public function uninstall(): bool { return true; }
}
