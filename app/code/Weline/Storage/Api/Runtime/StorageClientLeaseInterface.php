<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Runtime;

use Weline\Framework\Runtime\RequestResourceInterface;

interface StorageClientLeaseInterface extends RequestResourceInterface
{
    public function client(): object;
}
