<?php
declare(strict_types=1);

/**
 * 阿里云域名适配器（骨架实现）
 *
 * 通过阿里云域名注册 API 实现域名可用性检查、购买和管理。
 * 生产使用前需安装阿里云 SDK：composer require alibabacloud/sdk
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Adapter;

use Weline\Websites\Api\DomainRegistrarInterface;

class AliyunDomainRegistrar implements DomainRegistrarInterface
{
    public function getRegistrarCode(): string
    {
        return 'aliyun_domain';
    }

    public function getRegistrarName(): string
    {
        return __('阿里云域名');
    }

    public function getDescription(): string
    {
        return __('阿里云域名注册服务，支持域名注册、续费、DNS 解析和域名交易。');
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
                'label' => 'AccessKey ID',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'LTAI5tXXXX',
            ],
            [
                'name' => 'api_secret',
                'label' => 'AccessKey Secret',
                'type' => 'password',
                'required' => true,
                'placeholder' => '',
            ],
            [
                'name' => 'region',
                'label' => __('区域'),
                'type' => 'select',
                'required' => false,
                'default' => 'cn-hangzhou',
                'options' => [
                    ['value' => 'cn-hangzhou', 'label' => __('华东1（杭州）')],
                    ['value' => 'cn-shanghai', 'label' => __('华东2（上海）')],
                    ['value' => 'cn-beijing', 'label' => __('华北2（北京）')],
                    ['value' => 'cn-shenzhen', 'label' => __('华南1（深圳）')],
                    ['value' => 'ap-southeast-1', 'label' => __('亚太东南（新加坡）')],
                ],
            ],
        ];
    }

    public function getConfigHelp(): array
    {
        return [
            'help_url' => 'https://ram.console.aliyun.com/manage/ak',
            'help_title' => __('阿里云域名配置获取指南'),
            'help_steps' => [
                __('1. 登录阿里云控制台：https://www.aliyun.com'),
                __('2. 右上角头像 → 「AccessKey 管理」'),
                __('3. 建议创建子用户 AccessKey（更安全），或使用主账号 AccessKey'),
                __('4. 点击「创建 AccessKey」，完成手机验证'),
                __('5. 将「AccessKey ID」填入上方对应字段'),
                __('6. 将「AccessKey Secret」填入上方对应字段（仅创建时显示一次，请妥善保存）'),
                __('7. 如使用子用户，需授予「AliyunDomainFullAccess」权限'),
                __('8. 区域可保持默认（华东1-杭州）'),
            ],
        ];
    }

    public function testConnection(array $credentials): bool
    {
        // TODO: 实现阿里云 API 连接测试
        if (empty($credentials['api_key']) || empty($credentials['api_secret'])) {
            throw new \RuntimeException(__('AccessKey ID 和 AccessKey Secret 不能为空'));
        }

        return true;
    }

    public function checkAvailability(string $domain, array $credentials): array
    {
        // TODO: 调用阿里云 CheckDomainRequest
        return [
            'available' => false,
            'domain' => $domain,
            'price' => 0,
            'currency' => 'CNY',
            'premium' => false,
            'message' => __('阿里云域名适配器尚未完成 API 对接，请稍后再试。'),
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
        // TODO: 调用阿里云 SaveSingleTaskForCreatingOrderActivateRequest
        return [
            'success' => false,
            'domain' => $domain,
            'message' => __('阿里云域名适配器尚未完成 API 对接，请稍后再试。'),
        ];
    }

    public function getDomainList(array $credentials): array
    {
        // TODO: 调用阿里云 QueryDomainListRequest
        return [];
    }

    public function getDomainDetail(string $domain, array $credentials): array
    {
        // TODO: 调用阿里云 QueryDomainByInstanceIdRequest
        return [
            'domain' => $domain,
            'status' => 'unknown',
        ];
    }
}
