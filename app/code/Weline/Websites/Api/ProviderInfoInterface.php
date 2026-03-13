<?php
declare(strict_types=1);

/**
 * 供应商元数据接口（必须实现）
 *
 * 所有域名商/DNS 服务商适配器必须实现此接口，提供基础标识、配置和连接能力。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Api;

interface ProviderInfoInterface
{
    /**
     * @return string 适配器唯一标识，如 'gname', 'cloudflare', 'aliyun_domain'
     */
    public function getRegistrarCode(): string;

    /**
     * @return string 适配器显示名称，如 'GName', 'Cloudflare'
     */
    public function getRegistrarName(): string;

    /**
     * @return string 适配器功能描述
     */
    public function getDescription(): string;

    /**
     * @return string 适配器版本号
     */
    public function getVersion(): string;

    /**
     * 前端配置表单字段定义
     *
     * @return array<array{name: string, label: string, type: string, required: bool, placeholder?: string, options?: array}>
     */
    public function getConfigFields(): array;

    /**
     * 配置获取帮助信息
     *
     * @return array{help_url: string, help_title: string, help_steps: array<string>}
     */
    public function getConfigHelp(): array;

    /**
     * 测试 API 连接
     *
     * @param array $credentials API 凭据
     * @return bool
     * @throws \RuntimeException 连接失败时抛出
     */
    public function testConnection(array $credentials): bool;

    /**
     * 是否为域名注册商（可购买域名）
     *
     * true = 域名注册商（GName、Aliyun 等），getDomainList 返回真正拥有的域名
     * false = 仅 DNS/CDN 服务商（Cloudflare 等），getDomainList 返回托管的域名
     *
     * @return bool
     */
    public function isDomainRegistrar(): bool;
}
