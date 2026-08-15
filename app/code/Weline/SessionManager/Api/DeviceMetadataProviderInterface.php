<?php

declare(strict_types=1);

namespace Weline\SessionManager\Api;

use Weline\SessionManager\Data\DeviceMetadata;

interface DeviceMetadataProviderInterface
{
    public function current(): DeviceMetadata;
}
