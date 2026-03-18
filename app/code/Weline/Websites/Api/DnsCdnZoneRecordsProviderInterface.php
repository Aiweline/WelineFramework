<?php
declare(strict_types=1);

/**
 * DNS/CDN 管理账户适配器能力
 *
 * 域名上绑定的 dns_account_id / cdn_account_id 对应适配器必须实现本接口，
 * 用于按根域（zone）拉取权威解析记录，供源站 IP 判定、解析同步等（与公网递归无关）。
 *
 * @see DnsManagementInterface::getDnsRecords 实现上可与列表接口共用，但业务入口统一走 listZoneDnsRecordsForAccount
 */

namespace Weline\Websites\Api;

interface DnsCdnZoneRecordsProviderInterface
{
    /**
     * 拉取某根域在供应商处的解析记录列表（权威数据）
     *
     * @param string $zoneRoot 根域，如 example.com
     * @param array<string, mixed> $credentials 账户 API 凭据
     * @return list<array{
     *     record_id: string,
     *     type: string,
     *     host: string,
     *     value: string,
     *     ttl: int,
     *     priority?: int,
     *     proxied?: bool
     * }>
     */
    public function listZoneDnsRecordsForAccount(string $zoneRoot, array $credentials): array;
}
