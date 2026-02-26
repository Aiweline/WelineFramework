<?php
declare(strict_types=1);

/**
 * 域名商适配器接口
 *
 * 所有域名商适配器必须实现此接口，提供统一的域名操作 API。
 * 第三方模块可通过 extends 机制扩展新的域名商适配器。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Api;

interface DomainRegistrarInterface
{
    /**
     * 获取适配器唯一标识
     *
     * @return string 如 'aws_route53', 'aliyun_domain', 'azure_dns'
     */
    public function getRegistrarCode(): string;

    /**
     * 获取适配器显示名称
     *
     * @return string 如 'AWS Route53', '阿里云域名', 'Azure DNS'
     */
    public function getRegistrarName(): string;

    /**
     * 获取适配器描述
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * 获取适配器版本
     *
     * @return string
     */
    public function getVersion(): string;

    /**
     * 获取适配器所需的配置字段定义
     *
     * 返回前端表单需要的字段列表，用于动态生成配置表单。
     *
     * @return array<array{name: string, label: string, type: string, required: bool, placeholder?: string, options?: array}>
     */
    public function getConfigFields(): array;

    /**
     * 获取配置帮助信息
     *
     * 返回配置获取的说明，包括获取地址和步骤说明。
     *
     * @return array{help_url: string, help_title: string, help_steps: array<string>}
     */
    public function getConfigHelp(): array;

    /**
     * 测试 API 连接
     *
     * @param array $credentials API 凭据配置
     * @return bool 连接是否成功
     * @throws \RuntimeException 连接失败时抛出异常
     */
    public function testConnection(array $credentials): bool;

    /**
     * 检查域名可用性
     *
     * @param string $domain 要检查的域名
     * @param array $credentials API 凭据配置
     * @return array{available: bool, domain: string, price?: float, currency?: string, premium?: bool, message?: string}
     */
    public function checkAvailability(string $domain, array $credentials): array;

    /**
     * 批量检查域名可用性
     *
     * @param array<string> $domains 域名列表
     * @param array $credentials API 凭据配置
     * @return array<array{available: bool, domain: string, price?: float, currency?: string, premium?: bool, message?: string}>
     */
    public function batchCheckAvailability(array $domains, array $credentials): array;

    /**
     * 购买域名
     *
     * @param string $domain 要购买的域名
     * @param int $years 购买年限
     * @param array $credentials API 凭据配置
     * @param array $contactInfo 联系人信息（域名注册所需）
     * @return array{success: bool, domain: string, order_id?: string, price?: float, message?: string}
     */
    public function purchaseDomain(string $domain, int $years, array $credentials, array $contactInfo = []): array;

    /**
     * 获取已有域名列表
     *
     * @param array $credentials API 凭据配置
     * @return array<array{domain: string, status: string, expires_at?: string, auto_renew?: bool}>
     */
    public function getDomainList(array $credentials): array;

    /**
     * 获取域名详情
     *
     * @param string $domain 域名
     * @param array $credentials API 凭据配置
     * @return array{domain: string, status: string, nameservers?: array, expires_at?: string, registrar?: string}
     */
    public function getDomainDetail(string $domain, array $credentials): array;
}
