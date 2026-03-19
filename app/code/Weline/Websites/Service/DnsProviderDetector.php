<?php
declare(strict_types=1);

/**
 * Weline Websites - DNS 服务商检测服务
 *
 * 根据域名的 Nameservers 检测 DNS 服务商归属
 */

namespace Weline\Websites\Service;

class DnsProviderDetector
{
    // NS 特征库：pattern => provider_code
    private const NS_PATTERNS = [
        // Cloudflare
        'cloudflare.com' => 'cloudflare',
        'ns.cloudflare.com' => 'cloudflare',

        // GName（官网默认 DNS 为 ns1.gname-dns.com / ns2.gname-dns.com，须都能识别）
        'gname.com' => 'gname',
        'gname-dns.com' => 'gname',
        'dns.gname.com' => 'gname',

        // 部分注册商默认 NS（未改 NS 前常见；与 Cloudflare 等外部 DNS 并存时需先改 NS）
        'share-dns.com' => 'share_dns',
        'share-dns.net' => 'share_dns',

        // Aliyun / 阿里云
        'alidns.com' => 'aliyun',
        'hichina.com' => 'aliyun',
        'aliyun.com' => 'aliyun',

        // DNSPod / 腾讯云
        'dnspod.net' => 'dnspod',
        'dnspod.com' => 'dnspod',
        'dns.pub' => 'dnspod',

        // AWS Route53
        'awsdns' => 'aws',
        'amazonaws.com' => 'aws',

        // Azure DNS
        'azure-dns.com' => 'azure',
        'azure-dns.net' => 'azure',
        'azure-dns.org' => 'azure',
        'azure-dns.info' => 'azure',

        // Google Cloud DNS
        'googledomains.com' => 'google',
        'google.com' => 'google',

        // Godaddy
        'domaincontrol.com' => 'godaddy',

        // Namecheap
        'registrar-servers.com' => 'namecheap',

        // Name.com
        'name.com' => 'namecom',

        // Dynadot
        'dynadot.com' => 'dynadot',

        // Porkbun
        'porkbun.com' => 'porkbun',

        // Gandi
        'gandi.net' => 'gandi',

        // Hover
        'hover.com' => 'hover',

        // DigitalOcean
        'digitalocean.com' => 'digitalocean',

        // Linode
        'linode.com' => 'linode',

        // Vultr
        'vultr.com' => 'vultr',

        // Fastly
        'fastly.net' => 'fastly',

        // Vercel
        'vercel-dns.com' => 'vercel',

        // Netlify
        'netlify.com' => 'netlify',

        // Bunny CDN
        'bunny.net' => 'bunny',
    ];

    // 服务商显示名称
    private const PROVIDER_NAMES = [
        'cloudflare' => 'Cloudflare',
        'gname' => 'GName',
        'share_dns' => '注册商 DNS（share-dns）',
        'aliyun' => '阿里云',
        'dnspod' => 'DNSPod/腾讯云',
        'aws' => 'AWS Route53',
        'azure' => 'Azure DNS',
        'google' => 'Google Cloud',
        'godaddy' => 'GoDaddy',
        'namecheap' => 'Namecheap',
        'namecom' => 'Name.com',
        'dynadot' => 'Dynadot',
        'porkbun' => 'Porkbun',
        'gandi' => 'Gandi',
        'hover' => 'Hover',
        'digitalocean' => 'DigitalOcean',
        'linode' => 'Linode',
        'vultr' => 'Vultr',
        'fastly' => 'Fastly',
        'vercel' => 'Vercel',
        'netlify' => 'Netlify',
        'bunny' => 'Bunny CDN',
    ];

    // 域名注册商与 DNS 服务商的映射
    private const REGISTRAR_TO_DNS = [
        'gname' => 'gname',
        'aliyun' => 'aliyun',
        'godaddy' => 'godaddy',
        'namecheap' => 'namecheap',
        'namecom' => 'namecom',
        'dynadot' => 'dynadot',
        'porkbun' => 'porkbun',
        'gandi' => 'gandi',
    ];

    /**
     * 检测 DNS 服务商
     *
     * @param array $nameservers Nameserver 列表
     * @return string 服务商代码，未知返回 'unknown'
     */
    public function detectProvider(array $nameservers): string
    {
        foreach ($nameservers as $ns) {
            $ns = \strtolower(\trim($ns));
            if ($ns === '') {
                continue;
            }

            foreach (self::NS_PATTERNS as $pattern => $provider) {
                if (\str_contains($ns, $pattern)) {
                    return $provider;
                }
            }
        }

        return 'unknown';
    }

    /**
     * 检查是否为原注册商的 DNS
     *
     * @param string $dnsProvider 检测到的 DNS 服务商代码
     * @param string $registrarCode 域名注册商代码
     * @return bool
     */
    public function isOriginalProvider(string $dnsProvider, string $registrarCode): bool
    {
        if ($dnsProvider === 'unknown') {
            return false;
        }

        $registrarCode = \strtolower($registrarCode);
        $dnsProvider = \strtolower($dnsProvider);

        // 如果 DNS 服务商和注册商代码相同
        if ($dnsProvider === $registrarCode) {
            return true;
        }

        // 检查注册商的默认 DNS 服务商
        $defaultDns = self::REGISTRAR_TO_DNS[$registrarCode] ?? null;
        if ($defaultDns !== null && $defaultDns === $dnsProvider) {
            return true;
        }

        return false;
    }

    /**
     * 获取服务商显示名称
     */
    public function getProviderDisplayName(string $code): string
    {
        $code = \strtolower($code);
        return self::PROVIDER_NAMES[$code] ?? \ucfirst($code);
    }

    /**
     * 获取服务商颜色标记
     *
     * @param string $dnsProvider DNS 服务商代码
     * @param string $registrarCode 域名注册商代码
     * @return string CSS 颜色类名或颜色代码
     */
    public function getProviderColor(string $dnsProvider, string $registrarCode): string
    {
        if ($dnsProvider === 'unknown') {
            return 'secondary';
        }

        if ($this->isOriginalProvider($dnsProvider, $registrarCode)) {
            return 'success';
        }

        // Cloudflare 单独用橙色
        if ($dnsProvider === 'cloudflare') {
            return 'warning';
        }

        // 其他第三方用红色
        return 'danger';
    }

    /**
     * 获取完整的 DNS 检测结果
     *
     * @param array $nameservers Nameserver 列表
     * @param string $registrarCode 域名注册商代码
     * @return array{provider: string, name: string, is_original: bool, color: string, original_registrar: string}
     */
    public function detect(array $nameservers, string $registrarCode): array
    {
        $provider = $this->detectProvider($nameservers);
        $isOriginal = $this->isOriginalProvider($provider, $registrarCode);

        return [
            'provider' => $provider,
            'name' => $this->getProviderDisplayName($provider),
            'is_original' => $isOriginal,
            'color' => $this->getProviderColor($provider, $registrarCode),
            'original_registrar' => $isOriginal ? '' : $this->getProviderDisplayName($registrarCode),
        ];
    }

    /**
     * 检测域名是否使用了 CDN 服务商的 DNS
     */
    public function isCdnProvider(string $provider): bool
    {
        $cdnProviders = ['cloudflare', 'fastly', 'bunny', 'vercel', 'netlify'];
        return \in_array(\strtolower($provider), $cdnProviders, true);
    }

    /**
     * 获取所有已知服务商列表
     */
    public function getAllProviders(): array
    {
        return self::PROVIDER_NAMES;
    }

    /**
     * 获取服务商信息
     *
     * @param string $providerCode 服务商代码
     * @return array{code: string, name: string, is_cdn: bool}
     */
    public function getProviderInfo(string $providerCode): array
    {
        $code = \strtolower(\trim($providerCode));
        return [
            'code' => $code,
            'name' => self::PROVIDER_NAMES[$code] ?? \ucfirst($code ?: '-'),
            'is_cdn' => $this->isCdnProvider($code),
        ];
    }

    /**
     * 公网 DNS 委派状态说明（列表/弹窗提示用）
     *
     * 避免用户将「仍为注册商默认 NS / share-dns」误解为「域名未注册」。
     */
    public function getDnsDelegationUserHint(
        string $detectedProvider,
        string $registrarCode,
        bool $isRegistering,
        bool $dnsFollowsRegistrar = false
    ): string {
        if ($isRegistering) {
            return __(
                '域名尚在开通或注册流程中。公网仍显示注册商默认解析很常见，不代表注册失败；流程结束后会按配置处理 DNS，无需仅凭当前 NS 判断注册是否成功。'
            );
        }
        $p = \strtolower(\trim($detectedProvider));
        if ($p === 'share_dns') {
            return __(
                '当前公网 NS 为注册商默认 DNS（如 share-dns），通常表示域名已成功注册，只是解析仍由注册商托管。若已提交改 NS 至 Cloudflare 等，全球生效常需数十分钟至数小时，请在注册商后台核对 NS 是否已变更。'
            );
        }
        if ($p === 'cloudflare') {
            return __('当前公网 Nameserver 已指向 Cloudflare，解析由 Cloudflare 接管。');
        }
        if ($dnsFollowsRegistrar && $p !== '') {
            return __(
                'DNS 与注册商一致（默认托管）。公网多为注册商提供的解析，一般表示域名已注册；若要使用 Cloudflare 等，需在注册商处修改 NS 并等待全球生效。'
            );
        }
        if ($p === 'unknown' || $p === '') {
            return __(
                '暂无法从当前数据判断公网 DNS 归属。未拉取的域名请以注册商后台或 dig NS 为准；拉取后仍会随同步更新。'
            );
        }
        if ($this->isOriginalProvider($p, $registrarCode)) {
            return __(
                '当前检测为注册商侧或与其关联的 DNS。若与目标服务商不一致，请检查是否已在注册商处修改 NS 并等待传播。'
            );
        }

        return __(
            '当前解析由第三方 DNS 提供（非注册商默认）。若添加记录失败，请到该 DNS 服务商控制台操作。'
        );
    }

    /**
     * 列表展示用：本地配置的目标 DNS 与公网 NS 不一致时的文案
     *
     * - 根域列表：Cloudflare（注册商 DNS（share-dns）广播中…）
     * - 子域（池子）行：公网仍为旧 NS 时只显示公网侧名称，避免与根域长文案重复
     *
     * @param string $configuredDnsCode Domain.dns_provider（空则视为跟随注册商）
     * @param string $registrarCode     注册商代码
     * @param array<string> $liveNameservers 公网 NS 列表（如 dns_get_record）
     * @param bool $subdomainRow       true=池子子域行，不匹配时仅显示公网检测名
     */
    public function resolveDnsListDisplayName(
        string $configuredDnsCode,
        string $registrarCode,
        array $liveNameservers,
        bool $subdomainRow
    ): string {
        $configuredDnsCode = \strtolower(\trim($configuredDnsCode));
        $registrarCode = \strtolower(\trim($registrarCode));
        if ($configuredDnsCode === '') {
            $configuredDnsCode = $registrarCode;
        }
        $cfgName = $this->getProviderDisplayName($configuredDnsCode);

        if ($liveNameservers === []) {
            return $cfgName;
        }
        $liveCode = $this->detectProvider($liveNameservers);
        $liveName = $this->getProviderDisplayName($liveCode);

        if ($liveCode === $configuredDnsCode) {
            return $cfgName;
        }

        $wantsExternalDns = $this->isCdnProvider($configuredDnsCode)
            || (
                $configuredDnsCode !== ''
                && $configuredDnsCode !== $registrarCode
                && !$this->isOriginalProvider($configuredDnsCode, $registrarCode)
            );

        if (!$wantsExternalDns) {
            return $cfgName;
        }

        if ($liveCode === 'unknown') {
            return $cfgName;
        }

        if ($subdomainRow) {
            return $liveName;
        }

        return $cfgName . '（' . $liveName . __('广播中…') . '）';
    }

    /**
     * 是否应在列表加载时对根域查公网 NS（与本地配置比对）
     */
    public function shouldProbeLiveNsForConfiguredDns(string $configuredDnsCode, string $registrarCode): bool
    {
        $configuredDnsCode = \strtolower(\trim($configuredDnsCode));
        $registrarCode = \strtolower(\trim($registrarCode));
        if ($configuredDnsCode === '') {
            return false;
        }

        return $this->isCdnProvider($configuredDnsCode)
            || (
                $configuredDnsCode !== $registrarCode
                && !$this->isOriginalProvider($configuredDnsCode, $registrarCode)
            );
    }

    /**
     * 公网 NS 识别出的服务商是否与「配置的 DNS 管理账户」所属服务商一致（用于运维告警，避免误判）。
     *
     * 例：GName 账户下域名仍使用注册商默认 share-dns 时，探测为 share_dns，与账户 registrar gname 视为一致。
     */
    public function liveProviderMatchesDnsAccountRegistrar(string $detectedProvider, string $dnsAccountRegistrarCode): bool
    {
        $detected = \strtolower(\trim($detectedProvider));
        $accountCode = \strtolower(\trim($dnsAccountRegistrarCode));
        if ($detected === '' || $accountCode === '') {
            return true;
        }
        if ($detected === 'unknown') {
            return true;
        }
        if ($detected === $accountCode) {
            return true;
        }
        if ($accountCode === 'gname' && $detected === 'share_dns') {
            return true;
        }

        return false;
    }
}
