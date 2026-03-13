<?php
declare(strict_types=1);

/**
 * Zone 管理能力接口（可选）
 *
 * Cloudflare 等需要先创建 Zone 才能管理 DNS 的供应商实现此接口。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Api;

interface ZoneManagementInterface
{
    /**
     * 添加域名到供应商（创建 Zone）
     *
     * @param string $domain 域名
     * @param array $credentials API 凭据
     * @return array{success: bool, zone_id?: string, nameservers?: array, message?: string}
     */
    public function addZone(string $domain, array $credentials): array;

    /**
     * 获取托管在供应商的所有域名列表（含非自有域名）
     *
     * 区别于 getDomainList()（只返回自有域名），本方法返回所有托管域名。
     *
     * @param array $credentials API 凭据
     * @return array<array{domain: string, status: string, nameservers?: array, zone_id?: string}>
     */
    public function getHostedDomainList(array $credentials): array;
}
