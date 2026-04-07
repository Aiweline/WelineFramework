<?php
declare(strict_types=1);

namespace Weline\Websites\Adapter\Concern;

trait DomainRegistrarAccountDefaultsTrait
{
    public function getAccountBalance(array $credentials): array
    {
        return ['balance' => '0', 'currency' => 'USD'];
    }

    public function getTldPrices(array $credentials): array
    {
        return [];
    }

    public function getContactTemplates(array $credentials): array
    {
        return [];
    }
}

/** Framework adapters can reuse account + zone + CDN defaults together. */
trait DomainRegistrarOptionalDefaultsTrait
{
    use DomainRegistrarAccountDefaultsTrait;
    use DomainRegistrarCdnDefaultsTrait;
    use DomainRegistrarZoneDefaultsTrait;
    use RegistrarProvisioningNormalizeNoopTrait;
}
