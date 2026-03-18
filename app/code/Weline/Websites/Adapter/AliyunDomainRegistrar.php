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

use Weline\Websites\Adapter\Concern\DnsCdnZoneRecordsProviderTrait;
use Weline\Websites\Api\DomainRegistrarInterface;

class AliyunDomainRegistrar implements DomainRegistrarInterface
{
    use DnsCdnZoneRecordsProviderTrait;
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

    // ============================================================
    // DNS 记录操作（v1.5.0 新增）
    // ============================================================

    public function supportsDnsManagement(): bool
    {
        // 阿里云支持 DNS 管理（需对接阿里云 DNS API）
        return true;
    }

    public function getDnsRecords(string $domain, array $credentials): array
    {
        // TODO: 调用阿里云 DescribeDomainRecords
        return [];
    }

    public function addDnsRecord(string $domain, array $record, array $credentials): array
    {
        // TODO: 调用阿里云 AddDomainRecord
        return [
            'success' => false,
            'message' => __('阿里云 DNS 适配器尚未完成 API 对接，请稍后再试。'),
        ];
    }

    public function updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array
    {
        // TODO: 调用阿里云 UpdateDomainRecord
        return [
            'success' => false,
            'message' => __('阿里云 DNS 适配器尚未完成 API 对接，请稍后再试。'),
        ];
    }

    public function deleteDnsRecord(string $domain, string $recordId, array $credentials): array
    {
        // TODO: 调用阿里云 DeleteDomainRecord
        return [
            'success' => false,
            'message' => __('阿里云 DNS 适配器尚未完成 API 对接，请稍后再试。'),
        ];
    }

    public function batchAddDnsRecords(string $domain, array $records, array $credentials): array
    {
        // TODO: 批量调用 AddDomainRecord
        return [
            'success' => false,
            'added' => 0,
            'failed' => count($records),
            'errors' => [__('阿里云 DNS 适配器尚未完成 API 对接，请稍后再试。')],
        ];
    }

    public function updateNameservers(string $domain, array $nameservers, array $credentials): array
    {
        // TODO: 调用阿里云 SaveSingleTaskForModifyingDnsHost
        return [
            'success' => false,
            'message' => __('阿里云 Nameserver 修改功能尚未完成 API 对接，请稍后再试。'),
        ];
    }

    /**
     * @inheritDoc
     *
     * 阿里云 DNS（云解析 DNS）默认 Nameserver
     */
    public function getProviderNameservers(array $credentials, string $domain = ''): array
    {
        return [
            'success' => true,
            'nameservers' => [
                'ns1.alidns.com',
                'ns2.alidns.com',
            ],
            'message' => __('阿里云 DNS 默认服务器'),
        ];
    }

    /**
     * @inheritDoc
     *
     * 阿里云是真正的域名注册商，可以购买和管理域名。
     */
    public function isDomainRegistrar(): bool
    {
        return true;
    }
}
