<?php

declare(strict_types=1);

namespace Weline\FileManager\Api;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Api\Data\ResolvedFileImage;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageUrlOptions;

interface FileAssetManagerInterface
{
    public function get(string $assetId): FileAsset;

    public function locale(string $assetId, string $localeCode): FileAssetLocale;

    public function resolveUrl(
        string $assetId,
        FileAccessContext $context,
        ?StorageUrlOptions $options = null,
    ): ResolvedStorageUrl;

    public function validateImageUsage(ImageUsage $usage, FileAccessContext $context): void;

    /** Validate a durable draft reference without requiring publish-ready alt or translated metadata. */
    public function validateImageReference(ImageUsage $usage, FileAccessContext $context): void;

    public function resolveImage(
        ImageUsage $usage,
        FileAccessContext $context,
        string $class = '',
    ): ResolvedFileImage;

    public function renderImage(ImageUsage $usage, FileAccessContext $context, string $class = ''): string;
}
