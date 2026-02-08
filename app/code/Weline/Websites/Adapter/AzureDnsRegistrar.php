<?php
declare(strict_types=1);

/**
 * Azure DNS 域名商适配器（骨架实现）
 *
 * 通过 Microsoft Azure App Service Domains API 实现域名可用性检查、购买和管理。
 * 生产使用前需安装 Azure SDK
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Adapter;

use Weline\Websites\Api\DomainRegistrarInterface;

class AzureDnsRegistrar implements DomainRegistrarInterface
{
    public function getRegistrarCode(): string
    {
        return 'azure_dns';
    }

    public function getRegistrarName(): string
    {
        return 'Microsoft Azure DNS';
    }

    public function getDescription(): string
    {
        return __('Microsoft Azure 域名注册与 DNS 管理服务，支持域名注册和 Azure DNS Zone 管理。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'api_key',
                'label' => __('客户端 ID (Client ID)'),
                'type' => 'text',
                'required' => true,
                'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            ],
            [
                'name' => 'api_secret',
                'label' => __('客户端密钥 (Client Secret)'),
                'type' => 'password',
                'required' => true,
                'placeholder' => '',
            ],
            [
                'name' => 'region',
                'label' => __('订阅 ID (Subscription ID)'),
                'type' => 'text',
                'required' => true,
                'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            ],
            [
                'name' => 'extra_tenant_id',
                'label' => __('租户 ID (Tenant ID)'),
                'type' => 'text',
                'required' => true,
                'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            ],
        ];
    }

    public function testConnection(array $credentials): bool
    {
        // TODO: 实现 Azure API 连接测试
        if (empty($credentials['api_key']) || empty($credentials['api_secret'])) {
            throw new \RuntimeException(__('Client ID 和 Client Secret 不能为空'));
        }

        return true;
    }

    public function checkAvailability(string $domain, array $credentials): array
    {
        // TODO: 调用 Azure App Service Domains 接口
        return [
            'available' => false,
            'domain' => $domain,
            'price' => 0,
            'currency' => 'USD',
            'premium' => false,
            'message' => __('Azure DNS 适配器尚未完成 API 对接，请稍后再试。'),
        ];
    }

    public function batchCheckAvailability(array $domains, array $credentials): array
    {
        $results = [];
        foreach ($domains as $domain) {
            $results[] = $this->checkAvailability($domain, $credentials);
        }
        return $results;
    }

    public function purchaseDomain(string $domain, int $years, array $credentials, array $contactInfo = []): array
    {
        // TODO: 调用 Azure App Service Domains 注册接口
        return [
            'success' => false,
            'domain' => $domain,
            'message' => __('Azure DNS 适配器尚未完成 API 对接，请稍后再试。'),
        ];
    }

    public function getDomainList(array $credentials): array
    {
        // TODO: 调用 Azure 域名列表接口
        return [];
    }

    public function getDomainDetail(string $domain, array $credentials): array
    {
        // TODO: 调用 Azure 域名详情接口
        return [
            'domain' => $domain,
            'status' => 'unknown',
        ];
    }
}
