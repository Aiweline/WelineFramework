<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Runtime;

use Weline\Framework\Runtime\RequestResourceInterface;

interface StorageRequestStreamInterface extends RequestResourceInterface
{
    public const KIND_LOCAL_FILE = 'storage.local_file';
    public const KIND_PROXY_FILE = 'storage.proxy_file';

    /** @return resource */
    public function stream(): mixed;
}
