<?php
declare(strict_types=1);

/**
 * GoDaddy 域名商适配器
 *
 * 通过 GoDaddy API 实现域名可用性检查、购买、DNS 管理等。
 * GoDaddy API 文档：https://developer.godaddy.com/doc
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Adapter;

use Weline\Framework\App\Env;
use Weline\Websites\Api\AccountInfoInterface;
use Weline\Websites\Api\DomainRegistrarInterface;

use Weline\Websites\Adapter\Concern\DnsCdnZoneRecordsProviderTrait;

class GoDaddyRegistrar implements DomainRegistrarInterface, AccountInfoInterface
{
    use DnsCdnZoneRecordsProviderTrait;
    /** API 地址 */
    private const API_BASE_URL = 'https://api.godaddy.com';
    private const API_BASE_URL_OTE = 'https://api.ote-godaddy.com'; // 测试环境
    private const REQUEST_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;

    /** GoDaddy 默认 Nameservers */
    private const DEFAULT_NAMESERVERS = [
        'ns1.domaincontrol.com',
        'ns2.domaincontrol.com',
    ];

    public function getRegistrarCode(): string
    {
        return 'godaddy';
    }

    public function getRegistrarName(): string
    {
        return 'GoDaddy';
    }

    public function getDescription(): string
    {
        return __('GoDaddy 域名注册商，全球最大的域名注册商之一，支持域名注册、续费、转入、DNS 管理和域名交易。');
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
                'label' => __('API Key'),
                'type' => 'text',
                'required' => true,
                'placeholder' => __('GoDaddy 平台分配的 API Key'),
                'mapping' => 'api_key',
            ],
            [
                'name' => 'api_secret',
                'label' => __('API Secret'),
                'type' => 'password',
                'required' => true,
                'placeholder' => '',
                'mapping' => 'api_secret',
            ],
            [
                'name' => 'use_test_env',
                'label' => __('使用测试环境'),
                'type' => 'select',
                'required' => false,
                'default' => '0',
                'options' => [
                    ['value' => '0', 'label' => __('否（生产环境）')],
                    ['value' => '1', 'label' => __('是（测试环境 OTE）')],
                ],
                'mapping' => 'extra_config.use_test_env',
            ],
            [
                'name' => 'shopper_id',
                'label' => __('Shopper ID'),
                'type' => 'text',
                'required' => false,
                'placeholder' => __('可选：GoDaddy 账户 ID（用于子账户操作）'),
                'mapping' => 'extra_config.shopper_id',
            ],
        ];
    }

    public function getConfigHelp(): array
    {
        return [
            'help_url' => 'https://developer.godaddy.com/keys',
            'help_title' => __('GoDaddy API 配置获取指南'),
            'help_steps' => [
                __('【获取 API Key 和 Secret】'),
                __('1. 登录 GoDaddy 账号：https://www.godaddy.com/'),
                __('2. 访问 API Keys 页面：https://developer.godaddy.com/keys'),
                __('3. 点击「Create New API Key」按钮'),
                __('4. 选择环境：'),
                __('   • Production（生产环境）：用于真实操作，需要账户完成实名认证'),
                __('   • OTE（测试环境）：用于开发测试，可免费申请'),
                __('5. 复制生成的 Key 和 Secret'),
                __('6. 将 Key 填入上方「API Key」字段'),
                __('7. 将 Secret 填入上方「API Secret」字段'),
                __('【注意事项】'),
                __('• API Secret 只显示一次，请务必保存'),
                __('• Production API Key 需要账户完成实名认证'),
                __('• 测试环境（OTE）需要先在 https://developer.godaddy.com/ 注册测试账户'),
                __('• API 调用频率限制：Production 60次/分钟，OTE 5次/分钟'),
            ],
        ];
    }

    public function testConnection(array $credentials): bool
    {
        $this->validateCredentials($credentials);

        // 获取账户信息验证连接
        $response = $this->makeRequest('/v1/user/info', 'GET', [], $credentials);

        if (isset($response['customerId'])) {
            return true;
        }

        $errorMsg = $response['message'] ?? ($response['code'] ?? __('未知错误'));
        throw new \RuntimeException(__('GoDaddy API 连接失败：%{1}', [$errorMsg]));
    }

    public function checkAvailability(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return [
                'available' => false,
                'domain' => '',
                'price' => 0.0,
                'currency' => 'USD',
                'premium' => false,
                'message' => __('域名不能为空'),
            ];
        }

        $response = $this->makeRequest('/v1/domains/available?domain=' . \urlencode($domain), 'GET', [], $credentials);

        $available = ($response['available'] ?? false) === true;
        $price = 0.0;
        $currency = 'USD';
        $premium = false;
        $message = $available ? __('域名可注册') : __('域名已被注册');

        // 获取价格信息
        if ($available && isset($response['price'])) {
            $price = (float) ($response['price'] / 1000000); // GoDaddy 返回的是微单位
            $currency = $response['currency'] ?? 'USD';
        }

        // 检查是否溢价域名
        if (isset($response['definitive']) && $response['definitive'] === false) {
            $premium = true;
            $message = __('溢价域名，需查询具体价格');
        }

        return [
            'available' => $available,
            'domain' => $domain,
            'price' => $price,
            'currency' => $currency,
            'premium' => $premium,
            'message' => $message,
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
        $this->validateCredentials($credentials);

        // GoDaddy 购买域名需要联系人信息
        $body = [
            'domain' => $domain,
            'renewAuto' => true,
            'period' => $years,
        ];

        // 添加联系人信息（GoDaddy 要求）
        if (!empty($contactInfo['contact'])) {
            $body['contact'] = $contactInfo['contact'];
        } else {
            // 尝试使用默认联系人模板
            $body['contactRegistrant'] = $contactInfo['contactRegistrant'] ?? [];
            $body['contactAdmin'] = $contactInfo['contactAdmin'] ?? [];
            $body['contactTech'] = $contactInfo['contactTech'] ?? [];
            $body['contactBilling'] = $contactInfo['contactBilling'] ?? [];
        }

        // 如果有指定的隐私保护选项
        if (isset($contactInfo['privacy'])) {
            $body['privacy'] = (bool) $contactInfo['privacy'];
        }

        $response = $this->makeRequest('/v1/domains/purchase', 'POST', $body, $credentials);

        if (isset($response['orderId'])) {
            return [
                'success' => true,
                'domain' => $domain,
                'order_id' => $response['orderId'],
                'price' => (float) ($response['price'] ?? 0) / 1000000,
                'currency' => $response['currency'] ?? 'USD',
                'message' => __('域名购买成功，订单ID：%{1}', [$response['orderId']]),
            ];
        }

        // 检查是否需要确认购买（溢价域名等）
        if (isset($response['status']) && $response['status'] === 'PENDING_PURCHASE') {
            return [
                'success' => false,
                'domain' => $domain,
                'pending' => true,
                'message' => __('域名购买需要确认，请检查订单状态'),
            ];
        }

        $errorMsg = $response['message'] ?? ($response['code'] ?? __('未知错误'));
        return [
            'success' => false,
            'domain' => $domain,
            'message' => __('域名购买失败：%{1}', [$errorMsg]),
        ];
    }

    public function getDomainList(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $domains = [];
        $limit = 100;
        $marker = null;

        do {
            $params = ['limit' => $limit];
            if ($marker !== null) {
                $params['marker'] = $marker;
            }

            $response = $this->makeRequest('/v1/domains?' . \http_build_query($params), 'GET', [], $credentials);

            if (!\is_array($response)) {
                break;
            }

            foreach ($response as $domainInfo) {
                $domains[] = [
                    'domain' => (string) ($domainInfo['domain'] ?? ''),
                    'status' => $this->normalizeStatus((string) ($domainInfo['status'] ?? 'active')),
                    'expires_at' => (string) ($domainInfo['expires'] ?? ''),
                    'auto_renew' => (bool) ($domainInfo['renewAuto'] ?? false),
                    'nameservers' => $domainInfo['nameServers'] ?? [],
                    'privacy' => (bool) ($domainInfo['privacy'] ?? false),
                    'locked' => (bool) ($domainInfo['locked'] ?? false),
                ];
            }

            // GoDaddy 使用 marker 分页，如果返回数量少于 limit 表示已到最后
            if (\count($response) < $limit) {
                break;
            }

            // 获取最后一个域名作为下一页 marker
            $lastDomain = \end($response);
            $marker = $lastDomain['domain'] ?? null;

        } while ($marker !== null);

        return $domains;
    }

    public function getDomainDetail(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('/v1/domains/' . \urlencode($domain), 'GET', [], $credentials);

        if (isset($response['code']) && $response['code'] === 'NOT_FOUND') {
            return [
                'domain' => $domain,
                'status' => 'not_found',
                'message' => __('域名未在 GoDaddy 账户中找到'),
            ];
        }

        return [
            'domain' => $domain,
            'status' => $this->normalizeStatus((string) ($response['status'] ?? 'active')),
            'nameservers' => $response['nameServers'] ?? [],
            'expires_at' => (string) ($response['expires'] ?? ''),
            'auto_renew' => (bool) ($response['renewAuto'] ?? false),
            'privacy' => (bool) ($response['privacy'] ?? false),
            'locked' => (bool) ($response['locked'] ?? false),
            'registrar' => 'GoDaddy',
        ];
    }

    // ──────────────────────────────────────────
    // AccountInfoInterface 实现
    // ──────────────────────────────────────────

    public function getAccountBalance(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('/v1/shoppers/balance', 'GET', [], $credentials);

        return [
            'balance' => (string) ($response['balance'] ?? '0'),
            'currency' => (string) ($response['currency'] ?? 'USD'),
        ];
    }

    public function getTldPrices(array $credentials): array
    {
        $this->validateCredentials($credentials);

        // GoDaddy 没有直接获取所有 TLD 价格的 API，需要按 TLD 查询
        // 这里返回常见 TLD 列表，实际价格需要调用 checkAvailability 或单独查询
        return [
            ['Tld' => 'com', 'Register' => '12.00', 'Renew' => '18.00', 'Transfer' => '10.00'],
            ['Tld' => 'net', 'Register' => '15.00', 'Renew' => '18.00', 'Transfer' => '10.00'],
            ['Tld' => 'org', 'Register' => '15.00', 'Renew' => '18.00', 'Transfer' => '10.00'],
            ['Tld' => 'info', 'Register' => '5.00', 'Renew' => '18.00', 'Transfer' => '10.00'],
            ['Tld' => 'biz', 'Register' => '10.00', 'Renew' => '18.00', 'Transfer' => '10.00'],
        ];
    }

    public function getContactTemplates(array $credentials): array
    {
        $this->validateCredentials($credentials);

        // GoDaddy 获取联系人模板
        $response = $this->makeRequest('/v1/shoppers/contacts', 'GET', [], $credentials);

        return \is_array($response) ? $response : [];
    }

    // ──────────────────────────────────────────
    // DNS 记录管理
    // ──────────────────────────────────────────

    public function supportsDnsManagement(): bool
    {
        return true;
    }

    public function getDnsRecords(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $records = [];
        $recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'SOA'];
        $encodedDomain = \urlencode($domain);

        foreach ($recordTypes as $type) {
            $response = $this->makeRequest("/v1/domains/{$encodedDomain}/records/{$type}", 'GET', [], $credentials);

            if (\is_array($response)) {
                foreach ($response as $record) {
                    $records[] = [
                        'record_id' => \md5(($record['name'] ?? '') . ($record['type'] ?? '') . ($record['data'] ?? '')),
                        'type' => \strtoupper((string) ($record['type'] ?? 'A')),
                        'host' => (string) ($record['name'] ?? '@'),
                        'value' => (string) ($record['data'] ?? ''),
                        'ttl' => (int) ($record['ttl'] ?? 600),
                        'priority' => (int) ($record['priority'] ?? 0),
                    ];
                }
            }
        }

        return $records;
    }

    public function addDnsRecord(string $domain, array $record, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $type = \strtoupper((string) ($record['type'] ?? 'A'));
        $name = (string) ($record['host'] ?? '@');
        $data = (string) ($record['value'] ?? '');
        $ttl = (int) ($record['ttl'] ?? 600);
        $priority = (int) ($record['priority'] ?? 0);

        // GoDaddy DNS 记录格式
        $body = [
            [
                'type' => $type,
                'name' => $name === '@' ? '@' : $name,
                'data' => $data,
                'ttl' => $ttl,
            ],
        ];

        if (\in_array($type, ['MX', 'SRV'], true) && $priority > 0) {
            $body[0]['priority'] = $priority;
        }

        // GoDaddy 使用 PATCH 方法添加记录
        $response = $this->makeRequest('/v1/domains/' . \urlencode($domain) . '/records', 'PATCH', $body, $credentials);

        // GoDaddy 成功响应通常为空或返回 success=true
        if ($this->isSuccessResponse($response)) {
            return [
                'success' => true,
                'record_id' => \md5($name . $type . $data),
                'message' => __('DNS 记录添加成功'),
            ];
        }

        $errorMsg = $response['message'] ?? ($response['code'] ?? __('未知错误'));
        return [
            'success' => false,
            'message' => __('DNS 记录添加失败：%{1}', [$errorMsg]),
        ];
    }

    public function updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array
    {
        $this->validateCredentials($credentials);

        // GoDaddy 不支持按 ID 更新，需要删除旧记录再添加新记录
        // 这里直接使用 PUT 替换整个类型的记录

        $type = \strtoupper((string) ($record['type'] ?? 'A'));
        $name = (string) ($record['host'] ?? '@');
        $data = (string) ($record['value'] ?? '');
        $ttl = (int) ($record['ttl'] ?? 600);
        $priority = (int) ($record['priority'] ?? 0);

        $body = [
            [
                'type' => $type,
                'name' => $name === '@' ? '@' : $name,
                'data' => $data,
                'ttl' => $ttl,
            ],
        ];

        if (\in_array($type, ['MX', 'SRV'], true) && $priority > 0) {
            $body[0]['priority'] = $priority;
        }

        // PUT 方法会替换指定类型的所有记录
        $encodedDomain = \urlencode($domain);
        $encodedName = \urlencode($name);
        $response = $this->makeRequest("/v1/domains/{$encodedDomain}/records/{$type}/{$encodedName}", 'PUT', $body, $credentials);

        if ($this->isSuccessResponse($response)) {
            return [
                'success' => true,
                'message' => __('DNS 记录更新成功'),
            ];
        }

        $errorMsg = $response['message'] ?? ($response['code'] ?? __('未知错误'));
        return [
            'success' => false,
            'message' => __('DNS 记录更新失败：%{1}', [$errorMsg]),
        ];
    }

    public function deleteDnsRecord(string $domain, string $recordId, array $credentials): array
    {
        $this->validateCredentials($credentials);

        // GoDaddy 删除记录需要知道 type 和 name
        // recordId 是我们生成的 MD5，需要先获取所有记录来匹配
        $allRecords = $this->getDnsRecords($domain, $credentials);

        $targetRecord = null;
        foreach ($allRecords as $record) {
            if ($record['record_id'] === $recordId) {
                $targetRecord = $record;
                break;
            }
        }

        if ($targetRecord === null) {
            return [
                'success' => false,
                'message' => __('找不到指定的 DNS 记录'),
            ];
        }

        $type = $targetRecord['type'];
        $name = $targetRecord['host'];

        // DELETE 方法删除指定类型的记录
        $encodedDomain = \urlencode($domain);
        $encodedName = \urlencode($name);
        $response = $this->makeRequest("/v1/domains/{$encodedDomain}/records/{$type}/{$encodedName}", 'DELETE', [], $credentials);

        if ($this->isSuccessResponse($response)) {
            return [
                'success' => true,
                'message' => __('DNS 记录删除成功'),
            ];
        }

        $errorMsg = $response['message'] ?? ($response['code'] ?? __('未知错误'));
        return [
            'success' => false,
            'message' => __('DNS 记录删除失败：%{1}', [$errorMsg]),
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

    // ──────────────────────────────────────────
    // Nameserver 管理
    // ──────────────────────────────────────────

    public function updateNameservers(string $domain, array $nameservers, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $body = [
            'nameServers' => \array_values($nameservers),
        ];

        $response = $this->makeRequest('/v1/domains/' . \urlencode($domain), 'PATCH', $body, $credentials);

        if ($this->isSuccessResponse($response)) {
            return [
                'success' => true,
                'message' => __('Nameservers 修改成功'),
            ];
        }

        $errorMsg = $response['message'] ?? ($response['code'] ?? __('未知错误'));
        return [
            'success' => false,
            'message' => __('Nameservers 修改失败：%{1}', [$errorMsg]),
        ];
    }

    public function getProviderNameservers(array $credentials, string $domain = ''): array
    {
        return [
            'success' => true,
            'nameservers' => self::DEFAULT_NAMESERVERS,
            'message' => __('GoDaddy 默认 DNS 服务器'),
        ];
    }

    /**
     * @inheritDoc
     */
    public function isDomainRegistrar(): bool
    {
        return true;
    }

    // ──────────────────────────────────────────
    // 内部方法
    // ──────────────────────────────────────────

    /**
     * 验证凭据完整性
     */
    private function validateCredentials(array &$credentials): void
    {
        $credentials = $this->normalizeCredentials($credentials);

        if (empty($credentials['api_key'])) {
            throw new \RuntimeException(__('GoDaddy API Key 不能为空'));
        }
        if (empty($credentials['api_secret'])) {
            throw new \RuntimeException(__('GoDaddy API Secret 不能为空'));
        }
    }

    /**
     * 标准化凭据格式
     */
    private function normalizeCredentials(array $credentials): array
    {
        $extra = $credentials['extra_config'] ?? $credentials['extra'] ?? [];

        return [
            'api_key' => $credentials['api_key'] ?? '',
            'api_secret' => $credentials['api_secret'] ?? '',
            'use_test_env' => $extra['use_test_env'] ?? '0',
            'shopper_id' => $extra['shopper_id'] ?? '',
        ];
    }

    /**
     * 发送 API 请求
     *
     * @param string $endpoint API 端点
     * @param string $method HTTP 方法
     * @param array $data 请求数据
     * @param array $credentials 凭据
     * @return array
     */
    private function makeRequest(string $endpoint, string $method, array $data, array $credentials): array
    {
        $apiKey = (string) $credentials['api_key'];
        $apiSecret = (string) $credentials['api_secret'];
        $useTestEnv = ($credentials['use_test_env'] ?? '0') === '1';

        $baseUrl = $useTestEnv ? self::API_BASE_URL_OTE : self::API_BASE_URL;
        $url = $baseUrl . $endpoint;

        $headers = [
            'Authorization: sso-key ' . $apiKey . ':' . $apiSecret,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        // 如果有 Shopper ID，添加到请求头
        if (!empty($credentials['shopper_id'])) {
            $headers[] = 'X-Shopper-Id: ' . $credentials['shopper_id'];
        }

        $ch = \curl_init();

        $deployMode = Env::system('deploy') ?? 'prod';
        $isDev = \in_array($deployMode, ['dev', 'development', 'local'], true);

        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => !$isDev,
            CURLOPT_SSL_VERIFYHOST => $isDev ? 0 : 2,
        ];

        if ($method === 'POST') {
            $curlOpts[CURLOPT_POST] = true;
            $curlOpts[CURLOPT_POSTFIELDS] = \json_encode($data);
        } elseif ($method === 'PUT') {
            $curlOpts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $curlOpts[CURLOPT_POSTFIELDS] = \json_encode($data);
        } elseif ($method === 'PATCH') {
            $curlOpts[CURLOPT_CUSTOMREQUEST] = 'PATCH';
            $curlOpts[CURLOPT_POSTFIELDS] = \json_encode($data);
        } elseif ($method === 'DELETE') {
            $curlOpts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        \curl_setopt_array($ch, $curlOpts);

        $responseBody = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = \curl_error($ch);
        \curl_close($ch);

        // 记录请求日志
        w_log_info(\json_encode([
            'url' => $url,
            'method' => $method,
            'http_code' => $httpCode,
            'error' => $curlError,
        ], JSON_UNESCAPED_UNICODE), [], 'godaddy_api');

        if ($curlError !== '') {
            w_log_error("GoDaddy cURL 错误: {$curlError}, URL: {$url}", [], 'godaddy_api');
            return [
                'code' => 'CURL_ERROR',
                'message' => __('网络请求失败：%{1}', [$curlError]),
            ];
        }

        // 空响应处理（GoDaddy 某些操作成功时返回空）
        if ($responseBody === '' || $responseBody === false) {
            if ($httpCode >= 200 && $httpCode < 300) {
                return ['success' => true];
            }
            return [
                'code' => 'EMPTY_RESPONSE',
                'message' => __('服务器返回空响应'),
            ];
        }

        $response = \json_decode($responseBody, true);

        if (!\is_array($response)) {
            w_log_error("GoDaddy JSON 解析失败, HTTP Code: {$httpCode}, Body: " . \substr($responseBody, 0, 500), [], 'godaddy_api');
            return [
                'code' => 'JSON_ERROR',
                'message' => __('API 响应格式错误'),
            ];
        }

        return $response;
    }

    /**
     * 判断响应是否成功
     *
     * GoDaddy 成功响应可能是：
     * - 空响应（某些操作）
     * - ['success' => true]
     * - 不包含错误代码的数组
     */
    private function isSuccessResponse(array $response): bool
    {
        // 明确的成功标志
        if (isset($response['success']) && $response['success'] === true) {
            return true;
        }

        // 没有错误代码，且不是明确的错误响应
        if (!isset($response['code'])) {
            return true;
        }

        // 检查是否是错误类型的代码
        $errorCodes = ['INVALID_BODY', 'CURL_ERROR', 'JSON_ERROR', 'EMPTY_RESPONSE', 'NOT_FOUND', 'UNAUTHORIZED', 'FORBIDDEN'];
        return !\in_array($response['code'], $errorCodes, true);
    }

    /**
     * 标准化 GoDaddy 域名状态
     */
    private function normalizeStatus(string $godaddyStatus): string
    {
        $statusMap = [
            'active' => 'active',
            'pending' => 'pending',
            'pendingverification' => 'pending',
            'pendingtransfer' => 'pending',
            'transferaway' => 'pending',
            'expired' => 'expired',
            'redemption' => 'expired',
            'redemptionperiod' => 'expired',
            'deleted' => 'deleted',
            'inactive' => 'suspended',
            'clienthold' => 'suspended',
            'serverhold' => 'suspended',
        ];

        return $statusMap[\strtolower($godaddyStatus)] ?? $godaddyStatus ?: 'unknown';
    }
}
