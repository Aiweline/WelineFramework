<?php
declare(strict_types=1);

namespace Weline\Websites\Adapter\Concern;

/**
 * 注册商「自有域名列表」与托管列表形态一致时的映射（覆盖 Zone 默认空列表）。
 *
 * use 时需与 DomainRegistrarZoneDefaultsTrait 组合并解析冲突：
 * RegistrarMapsDomainListToHostedTrait::getHostedDomainList insteadof DomainRegistrarZoneDefaultsTrait
 */
trait RegistrarMapsDomainListToHostedTrait
{
    public function getHostedDomainList(array $credentials): array
    {
        $rows = $this->getDomainList($credentials);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'domain' => (string) ($r['domain'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
                'nameservers' => [],
                'zone_id' => '',
            ];
        }

        return $out;
    }
}
