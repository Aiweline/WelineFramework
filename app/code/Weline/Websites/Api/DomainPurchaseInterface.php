<?php
declare(strict_types=1);

/**
 * 域名购买能力接口
 *
 * 域名注册商（isDomainRegistrar() = true）应实现此接口。
 * DNS-only 服务商可实现空壳方法（返回不支持提示）。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Api;

interface DomainPurchaseInterface
{
    /**
     * 检查域名可用性
     *
     * @param string $domain 要检查的域名
     * @param array $credentials API 凭据
     * @return array{available: bool, domain: string, price?: float, currency?: string, premium?: bool, message?: string}
     */
    public function checkAvailability(string $domain, array $credentials): array;

    /**
     * 批量检查域名可用性
     *
     * @param array<string> $domains 域名列表
     * @param array $credentials API 凭据
     * @return array<array{available: bool, domain: string, price?: float, currency?: string, premium?: bool, message?: string}>
     */
    public function batchCheckAvailability(array $domains, array $credentials): array;

    /**
     * 购买域名
     *
     * @param string $domain 要购买的域名
     * @param int $years 购买年限
     * @param array $credentials API 凭据
     * @param array $contactInfo 联系人信息
     * @return array{success: bool, domain: string, order_id?: string, price?: float, message?: string}
     */
    public function purchaseDomain(string $domain, int $years, array $credentials, array $contactInfo = []): array;

    /**
     * 获取已有域名列表
     *
     * @param array $credentials API 凭据
     * @return array<array{domain: string, status: string, expires_at?: string, auto_renew?: bool}>
     */
    public function getDomainList(array $credentials): array;

    /**
     * 获取域名详情
     *
     * @param string $domain 域名
     * @param array $credentials API 凭据
     * @return array{domain: string, status: string, nameservers?: array, expires_at?: string, registrar?: string}
     */
    public function getDomainDetail(string $domain, array $credentials): array;
}
