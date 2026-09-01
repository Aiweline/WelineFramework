<?php

declare(strict_types=1);

namespace Weline\SessionManager\Api;

interface DeviceInstallKeyProviderInterface
{
    /**
     * Ensure a durable per-browser-profile install key for the auth area.
     *
     * @return array{raw:string,digest:string}
     */
    public function ensure(string $area): array;
}
