<?php
declare(strict_types=1);

/**
 * 将 listZoneDnsRecordsForAccount 委托给 getDnsRecords。
 * 不支持 DNS API 的适配器不得作为域名的 DNS/CDN 管理账户绑定。
 */

namespace Weline\Websites\Adapter\Concern;

trait DnsCdnZoneRecordsProviderTrait
{
    public function listZoneDnsRecordsForAccount(string $zoneRoot, array $credentials): array
    {
        if (!$this->supportsDnsManagement()) {
            throw new \RuntimeException(
                __('该供应商适配器未开放 DNS 记录 API，不能作为域名的 DNS/CDN 管理账户使用')
            );
        }

        return $this->getDnsRecords($zoneRoot, $credentials);
    }
}
