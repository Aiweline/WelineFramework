<?php
declare(strict_types=1);

/**
 * Nameserver 切换能力接口
 *
 * 支持修改域名 NS 和获取供应商默认 NS 的适配器实现此接口。
 * 典型场景：从 GName 切换 NS 到 Cloudflare。
 *
 * - 注册商（GName）：updateNameservers() 调 API 修改 NS 指向
 * - DNS 服务商（Cloudflare）：getProviderNameservers() 返回自己分配的 NS
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Api;

interface NameserverSwitchInterface
{
    /**
     * 修改域名的 Nameservers
     *
     * 在注册商处修改域名的 NS 指向（如将 GName 域名的 NS 改为 Cloudflare 的 NS）。
     *
     * @param string $domain 域名
     * @param array<string> $nameservers Nameserver 列表，如 ['ns1.cloudflare.com', 'ns2.cloudflare.com']
     * @param array $credentials API 凭据
     * @return array{success: bool, message?: string}
     */
    public function updateNameservers(string $domain, array $nameservers, array $credentials): array;

    /**
     * 获取供应商分配的 Nameservers
     *
     * Cloudflare 等供应商需要先将域名加入 Zone，返回分配的 NS。
     * GName 等注册商返回固定的默认 NS。
     *
     * @param array $credentials API 凭据
     * @param string $domain 可选，Cloudflare 类供应商需要域名参数（自动创建 Zone）
     * @return array{success: bool, nameservers: array<string>, message?: string}
     */
    public function getProviderNameservers(array $credentials, string $domain = ''): array;
}
