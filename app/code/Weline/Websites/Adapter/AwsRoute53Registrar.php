<?php
declare(strict_types=1);

/**
 * AWS Route53 域名商适配器（骨架实现）
 *
 * 通过 AWS Route53 Domains API 实现域名可用性检查、购买和管理。
 * 生产使用前需安装 AWS SDK：composer require aws/aws-sdk-php
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Adapter;

use Weline\Websites\Api\DomainRegistrarInterface;

class AwsRoute53Registrar implements DomainRegistrarInterface
{
    public function getRegistrarCode(): string
    {
        return 'aws_route53';
    }

    public function getRegistrarName(): string
    {
        return 'AWS Route53';
    }

    public function getDescription(): string
    {
        return __('Amazon Web Services Route 53 域名注册服务，支持域名注册、DNS 管理和健康检查。');
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
                'label' => __('Access Key ID'),
                'type' => 'text',
                'required' => true,
                'placeholder' => 'AKIAIOSFODNN7EXAMPLE',
            ],
            [
                'name' => 'api_secret',
                'label' => __('Secret Access Key'),
                'type' => 'password',
                'required' => true,
                'placeholder' => '',
            ],
            [
                'name' => 'region',
                'label' => __('区域'),
                'type' => 'select',
                'required' => true,
                'default' => 'us-east-1',
                'options' => [
                    ['value' => 'us-east-1', 'label' => 'US East (N. Virginia)'],
                    ['value' => 'us-west-2', 'label' => 'US West (Oregon)'],
                    ['value' => 'eu-west-1', 'label' => 'EU (Ireland)'],
                    ['value' => 'ap-northeast-1', 'label' => 'Asia Pacific (Tokyo)'],
                    ['value' => 'ap-southeast-1', 'label' => 'Asia Pacific (Singapore)'],
                ],
            ],
        ];
    }

    public function getConfigHelp(): array
    {
        return [
            'help_url' => 'https://console.aws.amazon.com/iam/home#/security_credentials',
            'help_title' => __('AWS Route53 配置获取指南'),
            'help_steps' => [
                __('1. 登录 AWS 控制台：https://console.aws.amazon.com'),
                __('2. 进入 IAM 服务 → 用户 → 选择或创建用户'),
                __('3. 在「安全凭证」选项卡中，点击「创建访问密钥」'),
                __('4. 选择「应用程序外部使用」，创建密钥'),
                __('5. 将「访问密钥 ID」填入上方「Access Key ID」字段'),
                __('6. 将「秘密访问密钥」填入上方「Secret Access Key」字段'),
                __('7. 确保该用户具有 Route53Domains 和 Route53 的完整权限'),
                __('8. 区域选择 us-east-1（Route53 域名注册仅支持此区域）'),
            ],
        ];
    }

    public function testConnection(array $credentials): bool
    {
        // TODO: 实现 AWS API 连接测试
        // 需要 aws/aws-sdk-php 依赖
        if (empty($credentials['api_key']) || empty($credentials['api_secret'])) {
            throw new \RuntimeException(__('API Key 和 Secret 不能为空'));
        }

        // 骨架：返回 true 表示连接测试通过
        return true;
    }

    public function checkAvailability(string $domain, array $credentials): array
    {
        // TODO: 调用 AWS Route53Domains::checkDomainAvailability()
        return [
            'available' => false,
            'domain' => $domain,
            'price' => 0,
            'currency' => 'USD',
            'premium' => false,
            'message' => __('AWS Route53 适配器尚未完成 API 对接，请稍后再试。'),
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
        // TODO: 调用 AWS Route53Domains::registerDomain()
        return [
            'success' => false,
            'domain' => $domain,
            'message' => __('AWS Route53 适配器尚未完成 API 对接，请稍后再试。'),
        ];
    }

    public function getDomainList(array $credentials): array
    {
        // TODO: 调用 AWS Route53Domains::listDomains()
        return [];
    }

    public function getDomainDetail(string $domain, array $credentials): array
    {
        // TODO: 调用 AWS Route53Domains::getDomainDetail()
        return [
            'domain' => $domain,
            'status' => 'unknown',
        ];
    }
}
