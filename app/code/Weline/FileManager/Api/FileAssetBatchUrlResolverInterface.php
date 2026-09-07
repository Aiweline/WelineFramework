<?php

declare(strict_types=1);

namespace Weline\FileManager\Api;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageUrlOptions;

/** Optional bulk capability; existing FileAssetManagerInterface implementations remain valid. */
interface FileAssetBatchUrlResolverInterface
{
    /**
     * Resolve each input using its first usable exact-locale context, in order.
     * Unavailable inputs yield null. Input keys and independent access/URL checks
     * are preserved; raw metadata is shared only for this call.
     *
     * @param array<array-key, array{asset_id:string, contexts:list<FileAccessContext>, options?:StorageUrlOptions|null}> $requests
     * @return array<array-key, ResolvedStorageUrl|null>
     */
    public function resolveUrls(array $requests): array;
}
