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
                'mapping' => 'extra_config.tenant_id',
            ],
        ];
    }

    public function getConfigHelp(): array
    {
        return [
            'help_url' => 'https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
            'help_title' => __('Azure DNS 配置获取指南'),
            'help_steps' => [
                __('1. 登录 Azure 门户：https://portal.azure.com'),
                __('2. 进入「Azure Active Directory」→「应用注册」→「新注册」'),
                __('3. 创建应用后，在「概述」页面获取：'),
                __('   - 应用程序(客户端) ID → 填入「客户端 ID」'),
                __('   - 目录(租户) ID → 填入「租户 ID」'),
                __('4. 在「证书和密码」→「新客户端密码」创建密钥'),
                __('   - 复制密钥值 → 填入「客户端密钥」（仅显示一次）'),
                __('5. 进入「订阅」，复制订阅 ID → 填入「订阅 ID」'),
                __('6. 在订阅的「访问控制(IAM)」中，为应用分配「DNS Zone 参与者」角色'),
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
