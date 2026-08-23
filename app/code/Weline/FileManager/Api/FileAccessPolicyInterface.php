<?php

declare(strict_types=1);

namespace Weline\FileManager\Api;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Model\FileAsset;

interface FileAccessPolicyInterface
{
    public function assertCanRead(FileAsset $asset, FileAccessContext $context): void;

    public function assertCanManage(FileAsset $asset, FileAccessContext $context): void;
}
