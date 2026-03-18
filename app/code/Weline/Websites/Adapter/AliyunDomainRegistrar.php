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

use Weline\Websites\Adapter\Concern\DefaultDnsZoneOriginMatchTrait;
use Weline\Websites\Adapter\Concern\DnsCdnZoneRecordsProviderTrait;
use Weline\Websites\Adapter\Concern\DomainRegistrarOptionalDefaultsTrait;
use Weline\Websites\Adapter\Concern\RegistrarBatchCheckAvailabilityTrait;
use Weline\Websites\Api\DomainRegistrarInterface;
use Weline\Websites\Service\AliyunDomainOpenApi;

class AliyunDomainRegistrar implements DomainRegistrarInterface
{
    use DnsCdnZoneRecordsProviderTrait;
    use DefaultDnsZoneOriginMatchTrait;
    use DomainRegistrarOptionalDefaultsTrait;
    use RegistrarBatchCheckAvailabilityTrait;

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
        return '1.1.0';
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
            'purchase_help_steps' => [
                __('【RAM 权限】子用户须挂载「AliyunDomainFullAccess」或等价自定义策略（需包含域名查询、注册任务创建等域名相关 API）。仅 DNS 权限无法完成购买。'),
                __('【支付】本系统提交注册后，通常需在阿里云费用中心/订单页完成支付，域名才正式生效。'),
                __('【实名】按阿里云要求完成域名实名模板与认证，否则注册可能被拒。'),
            ],
            'help_steps' => [
                __('1. RAM 创建用户 → 创建 AccessKey'),
                __('2. 为用户授权 AliyunDomainFullAccess'),
                __('3. ID/Secret 填入上方（Secret 仅显示一次）'),
                __('4. 区域默认华东1即可'),
            ],
        ];
    }

    public function testConnection(array $credentials): bool
    {
        if (empty($credentials['api_key']) || empty($credentials['api_secret'])) {
            throw new \RuntimeException(__('AccessKey ID 和 AccessKey Secret 不能为空'));
        }
        try {
            AliyunDomainOpenApi::request(
                'CheckDomain',
                ['DomainName' => 'example.com'],
                (string) $credentials['api_key'],
                (string) $credentials['api_secret'],
            );
            return true;
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage());
        }
    }

    public function checkAvailability(string $domain, array $credentials): array
    {
        if (empty($credentials['api_key']) || empty($credentials['api_secret'])) {
            return [
                'available' => false,
                'domain' => $domain,
                'message' => __('未配置 AccessKey'),
            ];
        }
        try {
            $out = AliyunDomainOpenApi::request(
                'CheckDomain',
                ['DomainName' => strtolower(trim($domain))],
                (string) $credentials['api_key'],
                (string) $credentials['api_secret'],
            );
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'domain' => $domain,
                'message' => $e->getMessage(),
            ];
        }
        $availRaw = $out['Avail'] ?? $out['avail'] ?? 0;
        $avail = (int) $availRaw === 1 || $availRaw === true || $availRaw === 'true';
        $price = isset($out['Fee']) ? (float) $out['Fee'] : (isset($out['fee']) ? (float) $out['fee'] : 0.0);

        return [
            'available' => $avail,
            'domain' => $domain,
            'price' => $price,
            'currency' => (string) ($out['FeeCurrency'] ?? 'CNY'),
            'premium' => (bool) ($out['Premium'] ?? false),
            'message' => $avail ? '' : (string) ($out['Reason'] ?? __('域名不可用')),
        ];
    }

    public function purchaseDomain(string $domain, int $years, array $credentials, array $contactInfo = []): array
    {
        if (empty($credentials['api_key']) || empty($credentials['api_secret'])) {
            return [
                'success' => false,
                'domain' => $domain,
                'message' => __('未配置 AccessKey'),
            ];
        }
        $domain = strtolower(trim($domain));
        $years = max(1, min(10, $years));
        $contactJson = $contactInfo['aliyun_contact_json'] ?? null;
        if ($contactJson === null || $contactJson === '') {
            $flat = $contactInfo['purchase_contact_flat'] ?? [];
            $contactJson = \is_array($flat) ? $this->buildAliyunContactJson($flat) : null;
        }
        if ($contactJson === null || $contactJson === '') {
            return [
                'success' => false,
                'domain' => $domain,
                'message' => __('请填写完整注册联系人信息（与购买弹窗一致），或在请求中传入 aliyun_contact_json（阿里云联系人 JSON）。'),
            ];
        }
        if (\is_array($contactJson)) {
            $contactJson = json_encode($contactJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        try {
            $out = AliyunDomainOpenApi::request(
                'SaveSingleTaskForCreatingOrderActivate',
                [
                    'DomainName' => $domain,
                    'Years' => $years,
                    'Lang' => 'en',
                    'UserClientIp' => $this->resolveUserClientIp($contactInfo),
                    'Contact' => (string) $contactJson,
                ],
                (string) $credentials['api_key'],
                (string) $credentials['api_secret'],
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'domain' => $domain,
                'message' => $e->getMessage(),
            ];
        }
        $taskNo = (string) ($out['TaskNo'] ?? $out['taskNo'] ?? '');

        return [
            'success' => false,
            'domain' => $domain,
            'message' => $taskNo !== ''
                ? __('阿里云已创建注册任务，请在阿里云控制台完成支付后域名才会生效。任务号：%{1}', [$taskNo])
                : __('阿里云返回异常，请查看控制台或联系客服。'),
            'aliyun_task_no' => $taskNo,
            'pending_payment' => true,
        ];
    }

    /**
     * @param array<string, mixed> $flat
     */
    private function buildAliyunContactJson(array $flat): ?string
    {
        $first = \trim((string) ($flat['first_name'] ?? ''));
        $last = \trim((string) ($flat['last_name'] ?? ''));
        $email = \trim((string) ($flat['email'] ?? ''));
        $phone = \preg_replace('/\D/', '', (string) ($flat['phone'] ?? '')) ?? '';
        $a1 = \trim((string) ($flat['address1'] ?? ''));
        $city = \trim((string) ($flat['city'] ?? ''));
        $state = \trim((string) ($flat['state'] ?? ''));
        $zip = \trim((string) ($flat['postal_code'] ?? ''));
        $country = \strtoupper(\substr((string) ($flat['country'] ?? 'CN'), 0, 2));
        if ($first === '' || $last === '' || $email === '' || $phone === '' || $a1 === '' || $city === '' || $zip === '') {
            return null;
        }
        $name = $first . ' ' . $last;
        $telArea = match ($country) {
            'US', 'CA' => '1',
            'CN' => '86',
            default => '86',
        };
        $arr = [
            'City' => $city,
            'Country' => $country,
            'Address' => $a1,
            'Email' => $email,
            'Organization' => \trim((string) ($flat['organization'] ?? '-')) ?: '-',
            'Name' => $name,
            'Province' => $state !== '' ? $state : $city,
            'ZipCode' => $zip,
            'TelArea' => $telArea,
            'Telephone' => $phone,
        ];

        return json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $contactInfo */
    private function resolveUserClientIp(array $contactInfo): string
    {
        $ip = \trim((string) ($contactInfo['user_client_ip'] ?? ''));
        if ($ip !== '' && \filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return '127.0.0.1';
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
