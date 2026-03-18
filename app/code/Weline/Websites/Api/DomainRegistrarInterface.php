<?php
declare(strict_types=1);

/**
 * 域名商 / DNS(CDN) 适配器唯一约定：一个接口涵盖元数据、购买、DNS、权威区记录、源站判定、NS、账户与 Zone。
 * 不适用的能力在适配器内返回空数据或 success:false；可用 {@see \Weline\Websites\Adapter\Concern\DomainRegistrarOptionalDefaultsTrait} 填默认实现。
 */

namespace Weline\Websites\Api;

interface DomainRegistrarInterface
{
    // --- Provider ---

    public function getRegistrarCode(): string;

    public function getRegistrarName(): string;

    public function getDescription(): string;

    public function getVersion(): string;

    /** @return array<array{name: string, label: string, type: string, required: bool, placeholder?: string, options?: array}> */
    public function getConfigFields(): array;

    /**
     * @return array{
     *   help_url: string,
     *   help_title: string,
     *   help_steps: array<string>,
     *   purchase_help_steps?: array<string>
     * } purchase_help_steps 非空时，账号表单会优先展示「域名购买所需权限」
     */
    public function getConfigHelp(): array;

    public function testConnection(array $credentials): bool;

    public function isDomainRegistrar(): bool;

    // --- Purchase ---

    /** @return array{available: bool, domain: string, price?: float, currency?: string, premium?: bool, message?: string} */
    public function checkAvailability(string $domain, array $credentials): array;

    /** @return array<array{available: bool, domain: string, price?: float, currency?: string, premium?: bool, message?: string}> */
    public function batchCheckAvailability(array $domains, array $credentials): array;

    /** @return array{success: bool, domain: string, order_id?: string, price?: float, message?: string} */
    public function purchaseDomain(string $domain, int $years, array $credentials, array $contactInfo = []): array;

    /** @return array<array{domain: string, status: string, expires_at?: string, auto_renew?: bool}> */
    public function getDomainList(array $credentials): array;

    /** @return array{domain: string, status: string, nameservers?: array, expires_at?: string, registrar?: string} */
    public function getDomainDetail(string $domain, array $credentials): array;

    // --- DNS CRUD ---

    public function supportsDnsManagement(): bool;

    /** @return array<array{record_id: string, type: string, host: string, value: string, ttl: int, priority?: int}> */
    public function getDnsRecords(string $domain, array $credentials): array;

    /** @return array{success: bool, record_id?: string, message?: string} */
    public function addDnsRecord(string $domain, array $record, array $credentials): array;

    /** @return array{success: bool, message?: string} */
    public function updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array;

    /** @return array{success: bool, message?: string} */
    public function deleteDnsRecord(string $domain, string $recordId, array $credentials): array;

    /** @return array{success: bool, added: int, failed: int, errors?: array} */
    public function batchAddDnsRecords(string $domain, array $records, array $credentials): array;

    /**
     * 按账户拉取根域权威解析记录
     *
     * @return list<array{record_id: string, type: string, host: string, value: string, ttl: int, priority?: int, proxied?: bool}>
     */
    public function listZoneDnsRecordsForAccount(string $zoneRoot, array $credentials): array;

    /**
     * 权威记录判定 FQDN 是否指向源站 IP（IPv4/IPv6）
     *
     * @return array{matches: bool, api_ok: bool, has_direct_records: bool, origin_ipv4: string, origin_ipv6: string, error: string}
     */
    public function checkFqdnOriginPointsToServer(
        string $fqdn,
        string $zoneRoot,
        array $credentials,
        string $serverIpv4,
        string $serverIpv6,
    ): array;

    // --- Nameserver ---

    /** @return array{success: bool, message?: string} */
    public function updateNameservers(string $domain, array $nameservers, array $credentials): array;

    /** @return array{success: bool, nameservers: array<string>, message?: string} */
    public function getProviderNameservers(array $credentials, string $domain = ''): array;

    // --- Account（注册商增值能力，DNS 供应商可返回空）---

    /** @return array{balance: string, currency: string} */
    public function getAccountBalance(array $credentials): array;

    /** @return array<array{Tld: string, Register: string, Renew: string, Transfer: string}> */
    public function getTldPrices(array $credentials): array;

    public function getContactTemplates(array $credentials): array;

    // --- Zone（Cloudflare 类；注册商可返回不支持）---

    /** @return array{success: bool, zone_id?: string, nameservers?: array, message?: string} */
    public function addZone(string $domain, array $credentials): array;

    /** @return array<array{domain: string, status: string, nameservers?: array, zone_id?: string}> */
    public function getHostedDomainList(array $credentials): array;

    /**
     * 一站式配置订单：按供应商规则统一 DNS/CDN 账户侧字段。
     * 返回空数组表示不处理；返回的键将写入订单（dns_vendor、dns_account_id、cdn_vendor、cdn_account_id）。
     * 若无法自动统一，返回 `_error` => 用户可读说明。
     *
     * @param array{dns_vendor: string, dns_account_id: int, cdn_vendor: string, cdn_account_id: int, domain?: string} $context
     * @return array<string, mixed>
     */
    public function normalizeProvisioningDnsCdnAccounts(array $context): array;

    // --- CDN / 边缘（DNS 同源代理等，由各适配器实现；默认见 DomainRegistrarCdnDefaultsTrait）---

    /**
     * 推送到 DNS 供应商前，按本供应商规则写入 CDN/边缘字段（如 Cloudflare proxied）。
     *
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function applyCdnSettingsToDnsRecords(string $domain, array $records): array;

    /**
     * 通过供应商 API 校验 CDN/边缘是否已配置（不依赖站点 HTTP）。
     *
     * @return array{supported: bool, ok: bool, message: string} supported=false 表示无此能力，上层应跳过校验
     */
    public function verifyCdnConfiguration(string $domain, array $credentials): array;
}
