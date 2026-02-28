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

        // GName
        'gname.com' => 'gname',
        'dns.gname.com' => 'gname',

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
}
