<?php

declare(strict_types=1);

namespace Weline\SessionManager\Data;

final readonly class DeviceMetadata
{
    public function __construct(
        public string $deviceName,
        public string $browser,
        public string $operatingSystem,
        public string $ipAddress,
    ) {
    }
}
