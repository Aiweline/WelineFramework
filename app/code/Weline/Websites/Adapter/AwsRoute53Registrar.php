<?php
declare(strict_types=1);

/**
 * AWS Route53 Domains 适配器：可用性检查与注册（SigV4 JSON API）。
 */

namespace Weline\Websites\Adapter;

use Weline\Websites\Adapter\Concern\DefaultDnsZoneOriginMatchTrait;
use Weline\Websites\Adapter\Concern\DnsCdnZoneRecordsProviderTrait;
use Weline\Websites\Adapter\Concern\DomainRegistrarOptionalDefaultsTrait;
use Weline\Websites\Adapter\Concern\RegistrarBatchCheckAvailabilityTrait;
use Weline\Websites\Api\DomainRegistrarInterface;
use Weline\Websites\Service\AwsRoute53DomainsRpc;

class AwsRoute53Registrar implements DomainRegistrarInterface
{
    use DnsCdnZoneRecordsProviderTrait;
    use DefaultDnsZoneOriginMatchTrait;
    use DomainRegistrarOptionalDefaultsTrait;
    use RegistrarBatchCheckAvailabilityTrait;

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
        return '1.1.0';
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
                ],
            ],
        ];
    }

    public function getConfigHelp(): array
    {
        return [
            'help_url' => 'https://console.aws.amazon.com/iam/home#/security_credentials',
            'help_title' => __('AWS Route53 配置获取指南'),
            'purchase_help_steps' => [
                __('【IAM】建议直接附加托管策略「AmazonRoute53DomainsFullAccess」；或自定义策略至少包含：route53domains:CheckDomainAvailability、RegisterDomain、GetOperationDetail、ListDomains、ListOperations 等。'),
                __('【区域】Route 53 Domains 注册类 API 固定使用 us-east-1（与控制台「域名注册」一致），本系统已按此调用。'),
                __('【付款】AWS 账户须绑定有效付款方式，注册会从账户扣款。'),
                __('【联系人】批量购买须填写完整注册人信息（英文地址常用）。'),
            ],
            'help_steps' => [
                __('1. IAM → 用户 → 创建访问密钥（Access key）'),
                __('2. 权限：AmazonRoute53DomainsFullAccess（见上方购买权限说明）'),
                __('3. Access Key ID / Secret 填入上方'),
            ],
        ];
    }

    public function testConnection(array $credentials): bool
    {
        $this->validateCredentials($credentials);
        $region = $this->resolveRegion($credentials);
        try {
            AwsRoute53DomainsRpc::call(
                'ListDomains',
                ['MaxItems' => 1],
                (string) $credentials['api_key'],
                (string) $credentials['api_secret'],
                $region,
            );
            return true;
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage());
        }
    }

    public function checkAvailability(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);
        $region = $this->resolveRegion($credentials);
        try {
            $out = AwsRoute53DomainsRpc::call(
                'CheckDomainAvailability',
                ['DomainName' => strtolower(trim($domain))],
                (string) $credentials['api_key'],
                (string) $credentials['api_secret'],
                $region,
            );
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'domain' => $domain,
                'message' => $e->getMessage(),
            ];
        }
        $avail = strtoupper((string) ($out['Availability'] ?? ''));
        $available = $avail === 'AVAILABLE' || $avail === 'AVAILABLE_RESERVED' || $avail === 'AVAILABLE_PREORDER';
        $price = isset($out['Price']) ? (float) $out['Price'] : 0.0;

        return [
            'available' => $available,
            'domain' => $domain,
            'price' => $price,
            'currency' => (string) ($out['Currency'] ?? 'USD'),
            'premium' => str_contains($avail, 'PREMIUM') || str_contains($avail, 'RESERVED'),
            'message' => $available ? '' : (string) ($out['Availability'] ?? __('不可用')),
        ];
    }

    public function purchaseDomain(string $domain, int $years, array $credentials, array $contactInfo = []): array
    {
        $this->validateCredentials($credentials);
        $region = $this->resolveRegion($credentials);
        $domain = strtolower(trim($domain));
        $years = max(1, min(10, $years));

        $contact = $this->buildAwsContact($contactInfo);
        if ($contact === null) {
            return [
                'success' => false,
                'domain' => $domain,
                'message' => __('请先填写完整注册人信息（姓名、邮箱、电话、地址等），与后台购买弹窗中 AWS/GoDaddy 联系人表单一致。'),
            ];
        }

        $privacy = !isset($contactInfo['privacy']) || (bool) $contactInfo['privacy'];
        $body = [
            'DomainName' => $domain,
            'DurationInYears' => $years,
            'AutoRenew' => true,
            'AdminContact' => $contact,
            'RegistrantContact' => $contact,
            'TechContact' => $contact,
            'PrivacyProtectAdminContact' => $privacy,
            'PrivacyProtectRegistrantContact' => $privacy,
            'PrivacyProtectTechContact' => $privacy,
        ];

        try {
            $out = AwsRoute53DomainsRpc::call(
                'RegisterDomain',
                $body,
                (string) $credentials['api_key'],
                (string) $credentials['api_secret'],
                $region,
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'domain' => $domain,
                'message' => $e->getMessage(),
            ];
        }
        $opId = (string) ($out['OperationId'] ?? $out['operationId'] ?? '');
        if ($opId === '') {
            return [
                'success' => false,
                'domain' => $domain,
                'message' => __('注册请求未返回 OperationId，请稍后于 AWS 控制台核对。'),
            ];
        }

        return [
            'success' => true,
            'domain' => $domain,
            'order_id' => $opId,
            'registration_async' => true,
            'message' => __(
                '注册已提交（操作 ID：%{1}）。AWS 侧可能仍需数分钟生效；若暂时无法解析或入池，请稍后由 Cron 同步或查看 AWS 控制台。',
                [$opId]
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildAwsContact(array $contactInfo): ?array
    {
        if (!empty($contactInfo['aws_admin_contact']) && is_array($contactInfo['aws_admin_contact'])) {
            return $this->normalizeAwsContactDetail($contactInfo['aws_admin_contact']);
        }
        $flat = $contactInfo['purchase_contact_flat'] ?? $contactInfo;
        if (!is_array($flat)) {
            return null;
        }
        $first = trim((string) ($flat['first_name'] ?? ''));
        $last = trim((string) ($flat['last_name'] ?? ''));
        $email = trim((string) ($flat['email'] ?? ''));
        $phone = trim((string) ($flat['phone'] ?? ''));
        $addr = trim((string) ($flat['address1'] ?? ''));
        $city = trim((string) ($flat['city'] ?? ''));
        $state = trim((string) ($flat['state'] ?? ''));
        $zip = trim((string) ($flat['postal_code'] ?? ''));
        $country = strtoupper(substr((string) ($flat['country'] ?? 'US'), 0, 2));
        if ($first === '' || $last === '' || $email === '' || $phone === '' || $addr === '' || $city === '' || $zip === '' || $country === '') {
            return null;
        }
        if ($country === 'US' || $country === 'CA') {
            if ($state === '') {
                return null;
            }
        } else {
            $state = $state !== '' ? $state : 'N/A';
        }
        $phoneAws = $this->formatE164ForAws($phone, $country);

        return [
            'FirstName' => $first,
            'LastName' => $last,
            'ContactType' => 'PERSON',
            'OrganizationName' => trim((string) ($flat['organization'] ?? '')) ?: 'N/A',
            'AddressLine1' => $addr,
            'City' => $city,
            'State' => $state,
            'CountryCode' => $country,
            'ZipCode' => $zip,
            'PhoneNumber' => $phoneAws,
            'Email' => $email,
        ];
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>|null
     */
    private function normalizeAwsContactDetail(array $c): ?array
    {
        $req = ['FirstName', 'LastName', 'Email', 'PhoneNumber', 'AddressLine1', 'City', 'CountryCode', 'ZipCode'];
        foreach ($req as $k) {
            if (trim((string) ($c[$k] ?? '')) === '') {
                return null;
            }
        }
        $cc = strtoupper(substr((string) $c['CountryCode'], 0, 2));
        $state = trim((string) ($c['State'] ?? ''));
        if (($cc === 'US' || $cc === 'CA') && $state === '') {
            return null;
        }
        $out = $c;
        $out['CountryCode'] = $cc;
        $out['ContactType'] = $out['ContactType'] ?? 'PERSON';

        return $out;
    }

    private function formatE164ForAws(string $phone, string $countryCode): string
    {
        $p = str_replace([' ', '-'], '', trim($phone));
        if (str_starts_with($p, '+') && str_contains($p, '.')) {
            return $p;
        }
        $digits = preg_replace('/\D/', '', $p) ?? '';
        $cc = strtoupper($countryCode);

        return match ($cc) {
            'US', 'CA' => '+1.' . $digits,
            'CN' => '+86.' . $digits,
            'GB' => '+44.' . $digits,
            'DE' => '+49.' . $digits,
            'JP' => '+81.' . $digits,
            default => '+1.' . $digits,
        };
    }

    private function validateCredentials(array $credentials): void
    {
        if (empty($credentials['api_key']) || empty($credentials['api_secret'])) {
            throw new \RuntimeException(__('API Key 和 Secret 不能为空'));
        }
    }

    private function resolveRegion(array $credentials): string
    {
        $r = trim((string) ($credentials['region'] ?? 'us-east-1'));

        return $r !== '' ? $r : 'us-east-1';
    }

    public function getDomainList(array $credentials): array
    {
        $this->validateCredentials($credentials);
        $region = $this->resolveRegion($credentials);
        $list = [];
        $marker = null;
        try {
            do {
                $body = ['MaxItems' => 20];
                if ($marker !== null) {
                    $body['Marker'] = $marker;
                }
                $out = AwsRoute53DomainsRpc::call(
                    'ListDomains',
                    $body,
                    (string) $credentials['api_key'],
                    (string) $credentials['api_secret'],
                    $region,
                );
                foreach ($out['Domains'] ?? [] as $row) {
                    if (is_array($row)) {
                        $list[] = [
                            'domain' => (string) ($row['DomainName'] ?? ''),
                            'status' => 'active',
                            'expires_at' => (string) ($row['Expiry'] ?? ''),
                            'auto_renew' => (bool) ($row['AutoRenew'] ?? false),
                        ];
                    }
                }
                $marker = isset($out['NextPageMarker']) ? (string) $out['NextPageMarker'] : null;
            } while ($marker !== null && $marker !== '');
        } catch (\Throwable) {
            return [];
        }

        return $list;
    }

    public function getDomainDetail(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);
        $region = $this->resolveRegion($credentials);
        try {
            $out = AwsRoute53DomainsRpc::call(
                'GetDomainDetail',
                ['DomainName' => strtolower(trim($domain))],
                (string) $credentials['api_key'],
                (string) $credentials['api_secret'],
                $region,
            );
        } catch (\Throwable $e) {
            return [
                'domain' => $domain,
                'status' => 'unknown',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'domain' => $domain,
            'status' => strtolower((string) ($out['DomainName'] ?? '') !== '' ? 'active' : 'unknown'),
            'nameservers' => $out['Nameservers'] ?? [],
            'expires_at' => (string) ($out['ExpirationDate'] ?? ''),
            'registrar' => 'Amazon Registrar',
        ];
    }

    public function supportsDnsManagement(): bool
    {
        return true;
    }

    public function getDnsRecords(string $domain, array $credentials): array
    {
        return [];
    }

    public function addDnsRecord(string $domain, array $record, array $credentials): array
    {
        return [
            'success' => false,
            'message' => __('请在 Route53 控制台管理托管区解析记录'),
        ];
    }

    public function updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array
    {
        return [
            'success' => false,
            'message' => __('请在 Route53 控制台管理托管区解析记录'),
        ];
    }

    public function deleteDnsRecord(string $domain, string $recordId, array $credentials): array
    {
        return [
            'success' => false,
            'message' => __('请在 Route53 控制台管理托管区解析记录'),
        ];
    }

    public function batchAddDnsRecords(string $domain, array $records, array $credentials): array
    {
        return [
            'success' => false,
            'added' => 0,
            'failed' => count($records),
            'errors' => [__('请在 Route53 控制台管理托管区解析记录')],
        ];
    }

    public function updateNameservers(string $domain, array $nameservers, array $credentials): array
    {
        return [
            'success' => false,
            'message' => __('请在 Route53 Domains 控制台修改 NS'),
        ];
    }

    public function getProviderNameservers(array $credentials, string $domain = ''): array
    {
        return [
            'success' => false,
            'nameservers' => [],
            'message' => __('创建 Hosted Zone 后在 Route53 查看分配的 NS'),
        ];
    }

    public function isDomainRegistrar(): bool
    {
        return true;
    }
}
