<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Storage\Api\Data\StorageUrlOptions;

final class MediaAssetCatalogService
{
    public function __construct(private readonly FileAssetLibraryInterface $assets)
    {
    }

    /** @return array<string,mixed> */
    public function describe(
        string $diskCode,
        string $objectKey,
        string $localeCode,
        FileAccessContext $access,
    ): array {
        return $this->assets->describe($diskCode, $objectKey, $localeCode, $access);
    }

    public function resolveResourceUrl(
        string $diskCode,
        string $objectKey,
        FileAccessContext $access,
        ?StorageUrlOptions $options = null,
    ): string {
        return $this->assets->resolveResourceUrl($diskCode, $objectKey, $access, $options);
    }
}
