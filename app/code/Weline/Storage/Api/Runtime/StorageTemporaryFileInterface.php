<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Runtime;

use Weline\Framework\Runtime\RequestResourceInterface;

interface StorageTemporaryFileInterface extends RequestResourceInterface
{
    public function path(): string;

    /** Transfer deletion ownership to a request-final response object. */
    public function detach(): string;
}
