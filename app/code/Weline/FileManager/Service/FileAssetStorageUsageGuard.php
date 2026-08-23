<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Model\FileAsset;
use Weline\Storage\Api\StorageDiskUsageGuardInterface;

final class FileAssetStorageUsageGuard implements StorageDiskUsageGuardInterface
{
    public function __construct(private readonly FileAsset $assets)
    {
    }

    public function assertCanDelete(string $diskCode): void
    {
        $asset = clone $this->assets;
        $asset->clearData()->reset()
            ->where(FileAsset::schema_fields_DISK_CODE, trim($diskCode))
            ->find()
            ->fetch();
        if ($asset->getAssetId() !== '') {
            throw new \LogicException((string)__('存储磁盘仍被文件资源引用，不能删除。'));
        }
    }
}
