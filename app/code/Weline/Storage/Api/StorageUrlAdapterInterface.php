<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Data\StorageUrlOptions;

interface StorageUrlAdapterInterface
{
    public function publicUrl(StorageObjectReference $object, StorageUrlOptions $options): ResolvedStorageUrl;

    public function temporaryUrl(StorageObjectReference $object, StorageUrlOptions $options): ResolvedStorageUrl;

    public function imageVariantUrl(StorageObjectReference $object, StorageUrlOptions $options): ResolvedStorageUrl;
}
