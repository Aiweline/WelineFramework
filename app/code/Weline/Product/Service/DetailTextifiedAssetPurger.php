<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\FileManager\Model\FileAssetReference;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\Data\StorageDiskCode;

/**
 * After baked detail graphics are replaced with HTML, physically remove the
 * orphaned image and its FileAsset / locale / reference rows.
 */
final class DetailTextifiedAssetPurger
{
    /** @var null|callable(string):(?array<string,mixed>) */
    private $assetFinder;

    /** @var null|callable(string):void */
    private $referenceClearer;

    /** @var null|callable(string):void */
    private $metadataPurger;

    /**
     * @param null|callable(string):(?array<string,mixed>) $assetFinder
     * @param null|callable(string):void $referenceClearer
     * @param null|callable(string):void $metadataPurger
     */
    public function __construct(
        private readonly FileAssetLibraryInterface $library,
        ?callable $assetFinder = null,
        ?callable $referenceClearer = null,
        ?callable $metadataPurger = null,
    ) {
        $this->assetFinder = $assetFinder;
        $this->referenceClearer = $referenceClearer;
        $this->metadataPurger = $metadataPurger;
    }

    /**
     * @param list<string> $assetIds
     * @param callable(string):bool $isStillUsedInContent return true when any description/media still needs the asset
     * @return list<array{asset_id:string,status:string,object_key?:string,disk_code?:string,path?:string,file_gone?:bool,error?:string}>
     */
    public function purgeReplaced(
        array $assetIds,
        callable $isStillUsedInContent,
        string $mediaRoot,
        ?FileAccessContext $access = null,
    ): array {
        $access ??= new FileAccessContext(
            ScopeIdentity::global(),
            'zh_Hans_CN',
            null,
            ['catalog_maintenance'],
            'metadata_edit',
        );
        $mediaRoot = rtrim($mediaRoot, '/');
        $results = [];
        $seen = [];
        foreach ($assetIds as $rawId) {
            $assetId = strtolower(trim((string)$rawId));
            if ($assetId === '' || isset($seen[$assetId])) {
                continue;
            }
            $seen[$assetId] = true;
            if (!preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
                $assetId,
            )) {
                $results[] = ['asset_id' => $assetId, 'status' => 'invalid_id'];
                continue;
            }
            if ($isStillUsedInContent($assetId)) {
                $results[] = ['asset_id' => $assetId, 'status' => 'skipped_still_referenced'];
                continue;
            }

            $asset = ($this->assetFinder ?? [$this, 'findAssetRow'])($assetId);
            $diskCode = is_array($asset)
                ? (string)($asset[FileAsset::schema_fields_DISK_CODE] ?? StorageDiskCode::BUILTIN_LOCAL_MEDIA)
                : StorageDiskCode::BUILTIN_LOCAL_MEDIA;
            $objectKey = is_array($asset)
                ? (string)($asset[FileAsset::schema_fields_OBJECT_KEY] ?? '')
                : '';
            $absolute = $objectKey !== ''
                ? $mediaRoot . '/' . ltrim($objectKey, '/')
                : '';

            try {
                ($this->referenceClearer ?? [$this, 'clearReferenceRows'])($assetId);
                if ($objectKey !== '') {
                    $this->library->deleteObject($diskCode, $objectKey, $access);
                }
                ($this->metadataPurger ?? [$this, 'purgeMetadataRows'])($assetId);
                if ($absolute !== '' && is_file($absolute)) {
                    @unlink($absolute);
                }
                $results[] = [
                    'asset_id' => $assetId,
                    'status' => 'deleted',
                    'object_key' => $objectKey,
                    'disk_code' => $diskCode,
                    'path' => $absolute,
                    'file_gone' => $absolute === '' || !is_file($absolute),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'asset_id' => $assetId,
                    'status' => 'error',
                    'object_key' => $objectKey,
                    'disk_code' => $diskCode,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function purgeMetadataRows(string $assetId): void
    {
        $assetId = strtolower(trim($assetId));
        if ($assetId === '') {
            return;
        }
        $this->clearReferenceRows($assetId);
        $locales = ObjectManager::getInstance(FileAssetLocale::class);
        (clone $locales)->clearData()->reset()
            ->where(FileAssetLocale::schema_fields_ASSET_ID, $assetId)
            ->delete()->fetch();
        $assets = ObjectManager::getInstance(FileAsset::class);
        (clone $assets)->clearData()->reset()
            ->where(FileAsset::schema_fields_ID, $assetId)
            ->delete()->fetch();
    }

    public function clearReferenceRows(string $assetId): void
    {
        $assetId = strtolower(trim($assetId));
        if ($assetId === '') {
            return;
        }
        $references = ObjectManager::getInstance(FileAssetReference::class);
        (clone $references)->clearData()->reset()
            ->where(FileAssetReference::schema_fields_ASSET_ID, $assetId)
            ->delete()->fetch();
    }

    /** @return array<string,mixed>|null */
    public function findAssetRow(string $assetId): ?array
    {
        $assets = ObjectManager::getInstance(FileAsset::class);
        $rows = (clone $assets)->clearData()->reset()
            ->where(FileAsset::schema_fields_ID, $assetId)
            ->limit(1)
            ->select()
            ->fetchArray();
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $row = $rows[0] ?? null;

        return is_array($row) ? $row : null;
    }
}
