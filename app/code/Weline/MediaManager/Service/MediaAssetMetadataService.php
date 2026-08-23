<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\FileManager\Api\Data\FileAccessContext;

final class MediaAssetMetadataService
{
    public function __construct(private readonly FileAssetLibraryInterface $assets)
    {
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function save(
        string $assetId,
        string $diskCode,
        string $objectKey,
        string $localeCode,
        FileAccessContext $access,
        int $expectedRevision,
        array $metadata,
    ): array {
        return $this->assets->saveMetadata(
            $assetId,
            $diskCode,
            $objectKey,
            $localeCode,
            $access,
            $expectedRevision,
            MediaAssetUploadService::normalizeMetadata($metadata, basename($objectKey)),
        );
    }
}
