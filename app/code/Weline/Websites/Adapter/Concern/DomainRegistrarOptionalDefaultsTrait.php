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

trait DomainRegistrarZoneDefaultsTrait
{
    public function addZone(string $domain, array $credentials): array
    {
        return [
            'success' => false,
            'message' => (string) __('当前供应商不支持通过 API 创建 Zone'),
        ];
    }

    public function getHostedDomainList(array $credentials): array
    {
        return [];
    }
}

/** 骨架适配器可同时 use 账户+Zone 默认 */
trait DomainRegistrarOptionalDefaultsTrait
{
    use DomainRegistrarAccountDefaultsTrait;
    use DomainRegistrarZoneDefaultsTrait;
}
