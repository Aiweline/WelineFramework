<?php
declare(strict_types=1);

namespace Weline\Websites\Adapter\Concern;

trait DomainRegistrarZoneDefaultsTrait
{
    public function addZone(string $domain, array $credentials): array
    {
        return [
            'success' => false,
            'message' => (string) __('Current registrar does not support creating zones via API.'),
        ];
    }

    public function getHostedDomainList(array $credentials): array
    {
        return [];
    }
}
