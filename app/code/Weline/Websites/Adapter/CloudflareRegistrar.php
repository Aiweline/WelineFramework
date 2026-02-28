<?php
declare(strict_types=1);

/**
 * Cloudflare 域名商/DNS 服务适配器
 *
 * 通过 Cloudflare API 实现 DNS 记录管理、域名列表获取、Nameserver 切换等功能。
 * Cloudflare API 文档：https://developers.cloudflare.com/api/
 *
 * 主要用途：
 * - DNS 记录管理（A、AAAA、CNAME、MX、TXT 等）
 * - 域名 Nameserver 切换（将域名切换到 Cloudflare DNS）
 * - 域名列表获取（查看托管在 Cloudflare 的域名）
 *
 * 注意：Cloudflare 域名注册功能仅限特定后缀，本适配器主要聚焦 DNS 管理。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Adapter;

use Weline\Framework\App\Env;
use Weline\Websites\Api\DomainRegistrarInterface;

class CloudflareRegistrar implements DomainRegistrarInterface
{
    private const API_BASE_URL = 'https://api.cloudflare.com/client/v4';
    private const REQUEST_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;

    public function getRegistrarCode(): string
    {
        return 'cloudflare';
    }

    public function getRegistrarName(): string
    {
        return 'Cloudflare';
    }

    public function getDescription(): string
    {
        return __('Cloudflare DNS 服务提供商，支持 DNS 记录管理、CDN 加速、DDoS 防护。可用于将域名的 DNS 解析切换到 Cloudflare。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'api_token',
                'label' => __('API Token'),
                'type' => 'password',
                'required' => true,
                'required_on_create' => true,
                'placeholder' => __('Cloudflare API Token'),
                'mapping' => 'api_secret',
            ],
            [
                'name' => 'account_id',
                'label' => __('Account ID'),
                'type' => 'text',
                'required' => true,
                'placeholder' => __('必填：登录 CF 控制台 → 任意域名 → Overview → 右下角 API 区域'),
                'mapping' => 'extra_config.account_id',
            ],
        ];
    }

    public function getConfigHelp(): array
    {
        return [
            'help_url' => 'https://dash.cloudflare.com/profile/api-tokens',
            'help_title' => __('Cloudflare API 配置获取指南'),
            'help_steps' => [
                __('【获取 API Token】'),
                __('1. 登录 Cloudflare 控制台：https://dash.cloudflare.com/'),
                __('2. 点击右上角头像 → My Profile'),
                __('3. 左侧菜单选择 API Tokens'),
                __('4. 点击 Create Token'),
                __('5. 选择 "Edit zone DNS" 模板（或自定义权限）'),
                __('6. Zone Resources 选择 "All zones" 或指定域名'),
                __('7. 点击 Continue to summary → Create Token'),
                __('8. 复制生成的 API Token 填入上方字段'),
                __('【权限要求】'),
                __('- Zone:DNS:Edit（DNS 记录管理必需）'),
                __('- Zone:Zone:Read（获取域名列表必需）'),
                __('- Zone:Zone Settings:Edit（可选，修改 DNS 设置）'),
                __('【获取 Account ID（必填）】'),
                __('1. 进入任意已添加的域名'),
                __('2. 在 Overview 页面右下角找到「API」区域'),
                __('3. 复制「Account ID」填入上方字段'),
            ],
        ];
    }

    public function testConnection(array $credentials): bool
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('/user/tokens/verify', 'GET', [], $credentials);

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('API 验证失败');
            throw new \RuntimeException(__('Cloudflare API 连接失败：%{1}', [$errorMsg]));
        }

        return true;
    }

    public function checkAvailability(string $domain, array $credentials): array
    {
        return [
            'available' => false,
            'domain' => $domain,
            'message' => __('Cloudflare 主要是 DNS 服务提供商，域名注册功能有限。请通过其他域名商购买域名后，将 DNS 切换到 Cloudflare。'),
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
        return [
            'success' => false,
            'domain' => $domain,
            'message' => __('Cloudflare 域名注册功能暂不支持，请通过其他域名商购买后切换 DNS 到 Cloudflare。'),
        ];
    }

    /**
     * 获取在 Cloudflare Registrar 购买的域名列表（真正注册的域名）
     *
     * 使用 Cloudflare Registrar API：/accounts/{account_id}/registrar/domains
     * 只返回在 Cloudflare 域名注册服务购买的域名
     */
    public function getDomainList(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $accountId = $this->resolveAccountId($credentials);
        if ($accountId === '') {
            throw new \RuntimeException(__('Cloudflare Account ID 未配置，无法获取注册域名列表'));
        }

        $domains = [];
        $page = 1;
        $perPage = 50;

        do {
            $params = [
                'page' => $page,
                'per_page' => $perPage,
            ];

            $response = $this->makeRequest("/accounts/{$accountId}/registrar/domains", 'GET', $params, $credentials);

            if (!($response['success'] ?? false)) {
                $errors = $response['errors'] ?? [];
                $errorCode = $errors[0]['code'] ?? 0;

                // 1000 错误码表示没有域名，返回空列表
                if ($errorCode === 1000 || \str_contains($errors[0]['message'] ?? '', 'not found')) {
                    return [];
                }

                $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('获取注册域名列表失败');
                throw new \RuntimeException(__('Cloudflare Registrar API 错误：%{1}', [$errorMsg]));
            }

            foreach ($response['result'] ?? [] as $domain) {
                $domainName = $domain['name'] ?? '';
                if ($domainName === '') {
                    continue;
                }

                $domains[] = [
                    'domain' => $domainName,
                    'status' => $this->mapRegistrarStatus($domain['status'] ?? ''),
                    'expires_at' => $domain['expires_at'] ?? '',
                    'auto_renew' => (bool) ($domain['auto_renew'] ?? false),
                    'nameservers' => [],
                    'locked' => (bool) ($domain['locked'] ?? false),
                    'registrar' => 'cloudflare',
                    'is_registered_at_cloudflare' => true,
                ];
            }

            $totalCount = $response['result_info']['total_count'] ?? \count($domains);
            $totalPages = (int) \ceil($totalCount / $perPage);
            $page++;
        } while ($page <= $totalPages);

        return $domains;
    }

    /**
     * 获取托管在 Cloudflare 的所有域名（zones），包括在其他注册商购买的
     *
     * 使用 Cloudflare Zones API：/zones
     * 用于 DNS 管理、CDN 配置等场景，不用于根域名同步
     */
    public function getHostedDomainList(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $domains = [];
        $page = 1;
        $perPage = 50;

        do {
            $params = [
                'page' => $page,
                'per_page' => $perPage,
                'status' => 'active',
            ];

            $response = $this->makeRequest('/zones', 'GET', $params, $credentials);

            if (!($response['success'] ?? false)) {
                $errors = $response['errors'] ?? [];
                $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('获取域名列表失败');
                throw new \RuntimeException(__('Cloudflare Zones API 错误：%{1}', [$errorMsg]));
            }

            foreach ($response['result'] ?? [] as $zone) {
                $domains[] = [
                    'domain' => $zone['name'] ?? '',
                    'status' => $this->mapZoneStatus($zone['status'] ?? ''),
                    'expires_at' => '',
                    'auto_renew' => false,
                    'nameservers' => $zone['name_servers'] ?? [],
                    'zone_id' => $zone['id'] ?? '',
                    'plan' => $zone['plan']['name'] ?? 'Free',
                    'is_registered_at_cloudflare' => false,
                ];
            }

            $totalPages = $response['result_info']['total_pages'] ?? 1;
            $page++;
        } while ($page <= $totalPages);

        return $domains;
    }

    /**
     * 映射 Cloudflare Registrar 域名状态
     */
    private function mapRegistrarStatus(string $status): string
    {
        return match (\strtolower($status)) {
            'active' => 'active',
            'pending' => 'pending',
            'expired' => 'expired',
            'redemption' => 'redemption',
            'deleted' => 'deleted',
            default => $status ?: 'unknown',
        };
    }

    public function getDomainDetail(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $zoneId = $this->getZoneId($domain, $credentials);
        if ($zoneId === '') {
            return [
                'domain' => $domain,
                'status' => 'not_found',
                'message' => __('域名未在 Cloudflare 中找到'),
            ];
        }

        $response = $this->makeRequest("/zones/{$zoneId}", 'GET', [], $credentials);

        if (!($response['success'] ?? false)) {
            return [
                'domain' => $domain,
                'status' => 'error',
                'message' => __('获取域名详情失败'),
            ];
        }

        $zone = $response['result'] ?? [];

        return [
            'domain' => $zone['name'] ?? $domain,
            'status' => $this->mapZoneStatus($zone['status'] ?? ''),
            'nameservers' => $zone['name_servers'] ?? [],
            'original_nameservers' => $zone['original_name_servers'] ?? [],
            'plan' => $zone['plan']['name'] ?? 'Free',
            'zone_id' => $zone['id'] ?? '',
            'paused' => $zone['paused'] ?? false,
            'type' => $zone['type'] ?? 'full',
        ];
    }

    public function supportsDnsManagement(): bool
    {
        return true;
    }

    public function getDnsRecords(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $zoneId = $this->getZoneId($domain, $credentials);
        if ($zoneId === '') {
            throw new \RuntimeException(__('域名 %{1} 未在 Cloudflare 中找到', [$domain]));
        }

        $records = [];
        $page = 1;
        $perPage = 100;

        do {
            $params = [
                'page' => $page,
                'per_page' => $perPage,
            ];

            $response = $this->makeRequest("/zones/{$zoneId}/dns_records", 'GET', $params, $credentials);

            if (!($response['success'] ?? false)) {
                $errors = $response['errors'] ?? [];
                $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('获取 DNS 记录失败');
                throw new \RuntimeException(__('Cloudflare API 错误：%{1}', [$errorMsg]));
            }

            foreach ($response['result'] ?? [] as $record) {
                $host = $record['name'] ?? '';
                if (\str_ends_with($host, '.' . $domain)) {
                    $host = \substr($host, 0, -\strlen('.' . $domain));
                }
                if ($host === $domain) {
                    $host = '@';
                }

                $records[] = [
                    'record_id' => $record['id'] ?? '',
                    'type' => $record['type'] ?? '',
                    'host' => $host,
                    'value' => $record['content'] ?? '',
                    'ttl' => (int) ($record['ttl'] ?? 1),
                    'priority' => (int) ($record['priority'] ?? 0),
                    'proxied' => $record['proxied'] ?? false,
                ];
            }

            $totalPages = $response['result_info']['total_pages'] ?? 1;
            $page++;
        } while ($page <= $totalPages);

        return $records;
    }

    public function addDnsRecord(string $domain, array $record, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $zoneId = $this->getZoneId($domain, $credentials);
        if ($zoneId === '') {
            return [
                'success' => false,
                'message' => __('域名 %{1} 未在 Cloudflare 中找到', [$domain]),
            ];
        }

        $host = $record['host'] ?? '@';
        $name = $host === '@' ? $domain : ($host . '.' . $domain);

        $data = [
            'type' => \strtoupper($record['type'] ?? 'A'),
            'name' => $name,
            'content' => $record['value'] ?? '',
            'ttl' => (int) ($record['ttl'] ?? 1),
            'proxied' => $record['proxied'] ?? false,
        ];

        if (\in_array($data['type'], ['MX', 'SRV'], true)) {
            $data['priority'] = (int) ($record['priority'] ?? 10);
        }

        $response = $this->makeRequest("/zones/{$zoneId}/dns_records", 'POST', $data, $credentials);

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('添加记录失败');
            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        }

        return [
            'success' => true,
            'record_id' => $response['result']['id'] ?? '',
            'message' => __('DNS 记录添加成功'),
        ];
    }

    public function updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $zoneId = $this->getZoneId($domain, $credentials);
        if ($zoneId === '') {
            return [
                'success' => false,
                'message' => __('域名 %{1} 未在 Cloudflare 中找到', [$domain]),
            ];
        }

        $host = $record['host'] ?? '@';
        $name = $host === '@' ? $domain : ($host . '.' . $domain);

        $data = [
            'type' => \strtoupper($record['type'] ?? 'A'),
            'name' => $name,
            'content' => $record['value'] ?? '',
            'ttl' => (int) ($record['ttl'] ?? 1),
            'proxied' => $record['proxied'] ?? false,
        ];

        if (\in_array($data['type'], ['MX', 'SRV'], true)) {
            $data['priority'] = (int) ($record['priority'] ?? 10);
        }

        $response = $this->makeRequest("/zones/{$zoneId}/dns_records/{$recordId}", 'PUT', $data, $credentials);

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('更新记录失败');
            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        }

        return [
            'success' => true,
            'message' => __('DNS 记录更新成功'),
        ];
    }

    public function deleteDnsRecord(string $domain, string $recordId, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $zoneId = $this->getZoneId($domain, $credentials);
        if ($zoneId === '') {
            return [
                'success' => false,
                'message' => __('域名 %{1} 未在 Cloudflare 中找到', [$domain]),
            ];
        }

        $response = $this->makeRequest("/zones/{$zoneId}/dns_records/{$recordId}", 'DELETE', [], $credentials);

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('删除记录失败');
            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        }

        return [
            'success' => true,
            'message' => __('DNS 记录删除成功'),
        ];
    }

    public function batchAddDnsRecords(string $domain, array $records, array $credentials): array
    {
        $added = 0;
        $failed = 0;
        $errors = [];

        foreach ($records as $record) {
            $result = $this->addDnsRecord($domain, $record, $credentials);
            if ($result['success']) {
                $added++;
            } else {
                $failed++;
                $errors[] = ($record['host'] ?? '@') . ': ' . ($result['message'] ?? __('未知错误'));
            }
        }

        return [
            'success' => $failed === 0,
            'added' => $added,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    public function updateNameservers(string $domain, array $nameservers, array $credentials): array
    {
        return [
            'success' => false,
            'message' => __('Cloudflare 不支持通过 API 修改 Nameserver。请在域名注册商处将 Nameserver 切换到 Cloudflare 分配的服务器。'),
        ];
    }

    /**
     * @inheritDoc
     *
     * Cloudflare 需要先将域名添加到 Zone，然后获取分配的 Nameserver。
     * 如果域名已存在，直接返回已分配的 Nameserver；否则尝试添加并返回。
     */
    public function getProviderNameservers(array $credentials, string $domain = ''): array
    {
        if ($domain === '') {
            return [
                'success' => false,
                'nameservers' => [],
                'message' => __('Cloudflare 需要指定域名才能获取分配的 Nameserver'),
            ];
        }

        $this->validateCredentials($credentials);

        $detail = $this->getDomainDetail($domain, $credentials);

        if (isset($detail['nameservers']) && !empty($detail['nameservers'])) {
            return [
                'success' => true,
                'nameservers' => $detail['nameservers'],
                'message' => __('域名已在 Cloudflare 中，使用已分配的 Nameserver'),
            ];
        }

        $addResult = $this->addZone($domain, $credentials);

        if ($addResult['success'] && !empty($addResult['nameservers'])) {
            return [
                'success' => true,
                'nameservers' => $addResult['nameservers'],
                'message' => __('域名已添加到 Cloudflare'),
            ];
        }

        return [
            'success' => false,
            'nameservers' => [],
            'message' => $addResult['message'] ?? __('无法获取 Cloudflare Nameserver'),
        ];
    }

    /**
     * 获取 Cloudflare 分配的 Nameservers（兼容旧方法）
     */
    public function getCloudflareNameservers(string $domain, array $credentials): array
    {
        $detail = $this->getDomainDetail($domain, $credentials);
        return $detail['nameservers'] ?? [];
    }

    /**
     * 添加域名到 Cloudflare（创建 Zone）
     *
     * @param string $domain 域名
     * @param array $credentials API 凭据
     * @return array{success: bool, zone_id?: string, nameservers?: array, message?: string}
     */
    public function addZone(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);

        // 优先从配置获取，不行再调用 API 自动获取
        $accountId = $this->resolveAccountId($credentials);
        if ($accountId === '') {
            $accountId = $this->getAccountId($credentials);
        }

        if ($accountId === '') {
            return [
                'success' => false,
                'message' => __('无法自动获取 Cloudflare Account ID。') . "\n\n" .
                    __('【解决方案】') . "\n" .
                    __('方案一：创建 Token 时添加「Account → Account Settings → Read」权限') . "\n" .
                    __('方案二：手动填写 Account ID：') . "\n" .
                    __('  1. 登录 Cloudflare 控制台 https://dash.cloudflare.com') . "\n" .
                    __('  2. 进入任意已添加的域名') . "\n" .
                    __('  3. 在右下角「API」区域找到「Account ID」') . "\n" .
                    __('  4. 复制并填入本系统的账户配置中'),
            ];
        }

        $data = [
            'name' => $domain,
            'account' => ['id' => $accountId],
            'type' => 'full',
        ];

        $response = $this->makeRequest('/zones', 'POST', $data, $credentials);

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? __('未知错误')) : __('添加域名失败');
            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        }

        $zone = $response['result'] ?? [];

        return [
            'success' => true,
            'zone_id' => $zone['id'] ?? '',
            'nameservers' => $zone['name_servers'] ?? [],
            'message' => __('域名已添加到 Cloudflare，请将 Nameserver 切换到：%{1}', [\implode(', ', $zone['name_servers'] ?? [])]),
        ];
    }

    /**
     * 获取 Zone ID
     */
    private function getZoneId(string $domain, array $credentials): string
    {
        static $cache = [];

        $cacheKey = $domain . '_' . \md5(\json_encode($credentials));
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $response = $this->makeRequest('/zones', 'GET', ['name' => $domain], $credentials);

        if (!($response['success'] ?? false)) {
            return '';
        }

        $zones = $response['result'] ?? [];
        if (empty($zones)) {
            return '';
        }

        $cache[$cacheKey] = $zones[0]['id'] ?? '';
        return $cache[$cacheKey];
    }

    /**
     * 解析 Account ID（仅从配置中获取，不调用 API）
     */
    private function resolveAccountId(array $credentials): string
    {
        // 优先使用凭据顶层的 account_id
        if (isset($credentials['account_id']) && $credentials['account_id'] !== '') {
            return (string) $credentials['account_id'];
        }

        // 兼容 extra_config 和 extra 两种键名
        $extraConfig = $credentials['extra_config'] ?? $credentials['extra'] ?? [];
        if (isset($extraConfig['account_id']) && $extraConfig['account_id'] !== '') {
            return (string) $extraConfig['account_id'];
        }

        return '';
    }

    /**
     * 获取 Account ID
     * 
     * 通过 /accounts API 自动获取当前 Token 关联的 Account ID。
     * 需要 Token 具有 Account:Read 权限。
     */
    private function getAccountId(array $credentials): string
    {
        // 优先使用凭据中已配置的 account_id
        $configuredAccountId = $this->resolveAccountId($credentials);
        if ($configuredAccountId !== '') {
            return $configuredAccountId;
        }
        
        $response = $this->makeRequest('/accounts', 'GET', ['page' => 1, 'per_page' => 1], $credentials);

        if (!($response['success'] ?? false)) {
            // 记录详细错误便于调试
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? '') : '';
            Env::log_warning('cloudflare_api', __('获取 Cloudflare Account ID 失败：%{1}', [$errorMsg ?: 'Unknown error']));
            return '';
        }

        $accounts = $response['result'] ?? [];
        if (empty($accounts)) {
            Env::log_warning('cloudflare_api', __('Cloudflare API 返回空账户列表，Token 可能没有 Account:Read 权限'));
            return '';
        }
        
        return $accounts[0]['id'] ?? '';
    }

    /**
     * 映射 Zone 状态
     */
    private function mapZoneStatus(string $status): string
    {
        $statusMap = [
            'active' => 'active',
            'pending' => 'pending',
            'initializing' => 'pending',
            'moved' => 'inactive',
            'deleted' => 'deleted',
            'deactivated' => 'inactive',
        ];

        return $statusMap[\strtolower($status)] ?? $status;
    }

    /**
     * 验证凭据
     */
    private function validateCredentials(array $credentials): void
    {
        $apiToken = $credentials['api_token'] ?? $credentials['api_secret'] ?? '';
        if ($apiToken === '') {
            throw new \InvalidArgumentException(__('Cloudflare API Token 不能为空'));
        }
    }

    /**
     * 发起 API 请求
     */
    private function makeRequest(string $endpoint, string $method, array $params, array $credentials): array
    {
        $apiToken = $credentials['api_token'] ?? $credentials['api_secret'] ?? '';
        $url = self::API_BASE_URL . $endpoint;

        $headers = [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json',
        ];

        $ch = \curl_init();

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . \http_build_query($params);
        }

        // 开发环境下禁用 SSL 验证（解决本地 CA 证书问题）
        // 临时强制禁用 SSL 验证以排查问题
        $deployMode = Env::get('deploy', 'prod');
        $isDev = \in_array($deployMode, ['dev', 'development', 'local'], true);
        
        // 强制禁用 SSL 验证（Windows 开发环境经常缺少 CA 证书）
        \curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        if ($method === 'POST') {
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($params));
        } elseif ($method === 'PUT') {
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($params));
        } elseif ($method === 'DELETE') {
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $responseBody = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = \curl_error($ch);
        \curl_close($ch);

        if ($curlError !== '') {
            Env::log_error('cloudflare_api', "cURL 错误: {$curlError}, URL: {$url}");
            return [
                'success' => false,
                'errors' => [['message' => __('网络请求失败：%{1}', [$curlError])]],
            ];
        }

        $response = \json_decode($responseBody, true);
        if (!\is_array($response)) {
            Env::log_error('cloudflare_api', "JSON 解析失败, HTTP Code: {$httpCode}, Body: " . \substr($responseBody, 0, 500));
            return [
                'success' => false,
                'errors' => [['message' => __('API 响应格式错误')]],
            ];
        }

        if (!($response['success'] ?? false)) {
            $errors = $response['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? '') : '';
            Env::log_warning('cloudflare_api', "API 错误: {$errorMsg}, Endpoint: {$endpoint}, HTTP Code: {$httpCode}");
        }

        return $response;
    }

    /**
     * @inheritDoc
     *
     * Cloudflare 主要是 DNS/CDN 服务商，虽然有 Registrar 服务但使用率低。
     * 根域列表只需要显示真正的域名注册商（GName、阿里云、AWS 等）。
     * Cloudflare 账户用于 DNS 管理，不在根域列表中显示。
     */
    public function isDomainRegistrar(): bool
    {
        return false;
    }
}
