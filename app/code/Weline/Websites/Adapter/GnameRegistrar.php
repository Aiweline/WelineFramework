<?php
declare(strict_types=1);

/**
 * GName 域名商适配器
 *
 * 通过 GName API 实现域名可用性检查、购买、DNS 修改等。
 * GName API 文档：https://www.gname.com/domain/api
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Adapter;

use Weline\Framework\App\Env;
use Weline\Websites\Api\DomainRegistrarInterface;

class GnameRegistrar implements DomainRegistrarInterface
{
    private const DEFAULT_API_HOST = 'api.gname.com';
    private const REQUEST_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;

    public function getRegistrarCode(): string
    {
        return 'gname';
    }

    public function getRegistrarName(): string
    {
        return 'GName';
    }

    public function getDescription(): string
    {
        return __('GName 域名注册商，支持域名注册、续费、转入、DNS 管理和 DNSSEC 配置。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getConfigFields(): array
    {
        return [
            [
                'name' => 'appid',
                'label' => __('APP ID'),
                'type' => 'text',
                'required' => true,
                'placeholder' => __('GName 平台分配的 APP ID'),
                'mapping' => 'api_key',
            ],
            [
                'name' => 'appkey',
                'label' => __('APP Key'),
                'type' => 'password',
                'required' => true,
                'placeholder' => '',
                'mapping' => 'api_secret',
            ],
            [
                'name' => 'api_host',
                'label' => __('API 域名'),
                'type' => 'text',
                'required' => false,
                'placeholder' => 'api.gname.com',
                'default' => self::DEFAULT_API_HOST,
                'mapping' => 'extra_config.api_host',
            ],
            [
                'name' => 'default_template_id',
                'label' => __('默认联系人模板 ID'),
                'type' => 'text',
                'required' => false,
                'placeholder' => __('注册域名时使用的联系人模板（可选）'),
                'mapping' => 'extra_config.default_template_id',
            ],
        ];
    }

    public function getConfigHelp(): array
    {
        return [
            'help_url' => 'https://www.gname.com/domain/api',
            'help_title' => __('GName API 配置获取指南'),
            'help_steps' => [
                __('【前置条件】'),
                __('1. 注册 GName 账号：https://www.gname.com/register'),
                __('2. 升级为经销商账号：https://www.gname.com/agent'),
                __('3. 签署 API 服务协议（企业/平台必需）'),
                __('   - 联系商务邮箱：business@gname.com'),
                __('   - 或提交工单：https://www.gname.com/user#/wt_add'),
                __('   - 或拨打热线：+65-65189986'),
                __('【获取 API 凭证】'),
                __('4. 登录后进入「控制中心」→「API 管理」'),
                __('5. 协议签署完成后可查看 APP ID 和 APP Key'),
                __('6. 将 APP ID 填入上方「APP ID」字段'),
                __('7. 将 APP Key 填入上方「APP Key」字段'),
                __('8. API 域名保持默认（api.gname.com）即可'),
            ],
        ];
    }

    public function testConnection(array $credentials): bool
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('api/user/info', [], $credentials);

        if (($response['code'] ?? 0) !== 1) {
            throw new \RuntimeException(
                __('GName API 连接失败：%{1}', [$response['msg'] ?? __('未知错误')])
            );
        }

        return true;
    }

    public function checkAvailability(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $priceMap = $this->fetchTldPriceMap($credentials);
        $tld = $this->extractTld($domain);
        $tldPrice = $priceMap[$tld] ?? null;

        $response = $this->makeRequest('api/domain/reg', [
            'ym' => $domain,
        ], $credentials, true);

        $code = (int) ($response['code'] ?? 0);

        if ($code === 1) {
            $price = (float) ($response['data'] ?? $tldPrice['Register'] ?? 0);
            return [
                'available' => true,
                'domain' => $domain,
                'price' => $price,
                'currency' => 'USD',
                'premium' => false,
                'message' => $response['msg'] ?? __('域名可注册'),
            ];
        }

        if ($code === -3) {
            $premiumPrice = 0.0;
            if (\is_array($response['data'] ?? null)) {
                $premiumPrice = (float) ($response['data']['price'] ?? 0);
            }
            return [
                'available' => true,
                'domain' => $domain,
                'price' => $premiumPrice,
                'currency' => 'USD',
                'premium' => true,
                'message' => $response['msg'] ?? __('域名为溢价域名'),
            ];
        }

        return [
            'available' => false,
            'domain' => $domain,
            'price' => (float) ($tldPrice['Register'] ?? 0),
            'currency' => 'USD',
            'premium' => false,
            'message' => $response['msg'] ?? __('域名不可注册'),
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

        $params = ['ym' => $domain];

        $templateId = $contactInfo['template_id']
            ?? $credentials['default_template_id']
            ?? '';
        if ($templateId !== '') {
            $params['mbid'] = $templateId;
        }

        $dns = $contactInfo['dns'] ?? '';
        if ($dns !== '') {
            $params['dns'] = $dns;
        }

        $premiumAmount = $contactInfo['premium_amount'] ?? 0;
        if ((float) $premiumAmount > 0) {
            $params['qian'] = (string) $premiumAmount;
        }

        $response = $this->makeRequest('api/domain/reg', $params, $credentials);

        $code = (int) ($response['code'] ?? 0);

        if ($code === 1) {
            $price = \is_numeric($response['data'] ?? null) ? (float) $response['data'] : 0;
            return [
                'success' => true,
                'domain' => $domain,
                'price' => $price,
                'currency' => 'USD',
                'message' => $response['msg'] ?? __('域名注册提交成功'),
            ];
        }

        if ($code === -3) {
            $premiumPrice = 0.0;
            if (\is_array($response['data'] ?? null)) {
                $premiumPrice = (float) ($response['data']['price'] ?? 0);
            }
            return [
                'success' => false,
                'domain' => $domain,
                'price' => $premiumPrice,
                'premium' => true,
                'message' => $response['msg'] ?? __('溢价域名，需确认金额后再提交'),
            ];
        }

        return [
            'success' => false,
            'domain' => $domain,
            'message' => $response['msg'] ?? __('域名注册失败'),
        ];
    }

    public function getDomainList(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $page = 1;
        $allDomains = [];

        do {
            $response = $this->makeRequest('api/domain/list', [
                'page' => (string) $page,
            ], $credentials);

            if (($response['code'] ?? 0) !== 1 || !\is_array($response['data'] ?? null)) {
                break;
            }

            $list = $response['data']['list'] ?? $response['data'] ?? [];
            if (!\is_array($list) || $list === []) {
                break;
            }

            foreach ($list as $item) {
                $allDomains[] = [
                    'domain' => (string) ($item['ym'] ?? $item['domain'] ?? ''),
                    'status' => $this->normalizeStatus((string) ($item['zt'] ?? $item['status'] ?? '')),
                    'expires_at' => (string) ($item['dqsj'] ?? $item['expires_at'] ?? ''),
                    'auto_renew' => false,
                ];
            }

            $total = (int) ($response['data']['total'] ?? 0);
            $hasMore = $total > 0 && \count($allDomains) < $total;
            $page++;
        } while ($hasMore && $page <= 100);

        return $allDomains;
    }

    public function getDomainDetail(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('api/domain/info', [
            'ym' => $domain,
        ], $credentials);

        if (($response['code'] ?? 0) !== 1) {
            return [
                'domain' => $domain,
                'status' => 'unknown',
                'message' => $response['msg'] ?? __('获取域名详情失败'),
            ];
        }

        $data = $response['data'] ?? [];
        $nameservers = [];

        $dnsStr = (string) ($data['dns'] ?? '');
        if ($dnsStr !== '') {
            $nameservers = \array_map('trim', \explode(',', $dnsStr));
        }

        return [
            'domain' => $domain,
            'status' => $this->normalizeStatus((string) ($data['zt'] ?? $data['status'] ?? 'active')),
            'nameservers' => $nameservers,
            'expires_at' => (string) ($data['dqsj'] ?? $data['expires_at'] ?? ''),
            'registrar' => 'GName',
        ];
    }

    // ──────────────────────────────────────────
    // 扩展方法（不在接口中，但 Saas 流程需要）
    // ──────────────────────────────────────────

    /**
     * 修改域名 NS（切换到 Cloudflare 等第三方 NS）
     *
     * @param string $domain 域名
     * @param string $dnsServers 逗号分隔的 NS，如 "ns1.cf.com,ns2.cf.com"
     * @param array $credentials API 凭据
     * @return array{success: bool, message: string}
     */
    public function modifyDns(string $domain, string $dnsServers, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('api/domain/xgdns', [
            'ym' => $domain,
            'dns' => $dnsServers,
        ], $credentials);

        $code = (int) ($response['code'] ?? 0);

        if ($code === 1) {
            return [
                'success' => true,
                'message' => $response['msg'] ?? __('DNS 修改成功'),
            ];
        }

        return [
            'success' => false,
            'message' => $response['msg'] ?? __('DNS 修改失败'),
        ];
    }

    /**
     * 获取联系人模板列表
     */
    public function getTemplates(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('api/tpl/list', [], $credentials);

        if (($response['code'] ?? 0) !== 1) {
            return [];
        }

        return $response['data'] ?? [];
    }

    /**
     * 获取 TLD 价格列表
     */
    public function getTldPrices(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('api/domain/price', [], $credentials);

        if (($response['code'] ?? 0) !== 1) {
            return [];
        }

        return $response['data'] ?? [];
    }

    /**
     * 获取账户余额信息
     */
    public function getBalance(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('api/user/balance', [], $credentials);

        if (($response['code'] ?? 0) !== 1) {
            return ['balance' => '0', 'currency' => 'USD'];
        }

        return $response['data'] ?? ['balance' => '0', 'currency' => 'USD'];
    }

    // ──────────────────────────────────────────
    // 内部方法
    // ──────────────────────────────────────────

    /**
     * 标准化凭据格式
     *
     * DomainRegistrarAccount::getCredentials() 返回 api_key/api_secret/region/extra，
     * 需映射到 GName 的 appid/appkey/api_host/default_template_id。
     */
    private function normalizeCredentials(array $credentials): array
    {
        if (!empty($credentials['appid'])) {
            return $credentials;
        }

        $extra = $credentials['extra'] ?? [];

        return [
            'appid' => $credentials['api_key'] ?? '',
            'appkey' => $credentials['api_secret'] ?? '',
            'api_host' => $extra['api_host'] ?? $credentials['region'] ?? '',
            'default_template_id' => $extra['default_template_id'] ?? '',
        ];
    }

    /**
     * 验证凭据完整性
     */
    private function validateCredentials(array &$credentials): void
    {
        $credentials = $this->normalizeCredentials($credentials);

        if (empty($credentials['appid'])) {
            throw new \RuntimeException(__('GName APP ID 不能为空'));
        }
        if (empty($credentials['appkey'])) {
            throw new \RuntimeException(__('GName APP Key 不能为空'));
        }
    }

    /**
     * 生成签名
     *
     * GName 签名算法（官方文档）：
     * 1. 所有参数按 key 的 ASCII 升序排列（字典序）
     * 2. 参数值去除两边空格，进行 URLEncode 编码
     * 3. 拼接为 key1=urlencode(value1)&key2=urlencode(value2)
     * 4. 末尾直接拼接 APPKEY（无 & 分隔）
     * 5. 对拼接后的字符串做 MD5 并转大写
     *
     * @see https://www.gname.com/domain/api/rule/anquan
     */
    private function generateSignature(array $params, string $appKey): string
    {
        \ksort($params);

        $parts = [];
        foreach ($params as $key => $val) {
            $trimmedVal = \trim((string) $val);
            $parts[] = $key . '=' . \urlencode($trimmedVal);
        }

        $signStr = \implode('&', $parts) . $appKey;

        return \strtoupper(\md5($signStr));
    }

    /**
     * 发送 API 请求
     *
     * @param string $endpoint API 端点（如 api/domain/reg）
     * @param array $data 请求参数
     * @param array $credentials 凭据
     * @param bool $dryRun 仅检查可用性时为 true（不实际扣费）
     */
    private function makeRequest(string $endpoint, array $data, array $credentials, bool $dryRun = false): array
    {
        $appId = (string) $credentials['appid'];
        $appKey = (string) $credentials['appkey'];
        $apiHost = \trim((string) ($credentials['api_host'] ?? ''));
        if ($apiHost === '') {
            $apiHost = self::DEFAULT_API_HOST;
        }

        $data['appid'] = $appId;
        $data['gntime'] = (string) \time();

        $gntoken = $this->generateSignature($data, $appKey);
        $data['gntoken'] = $gntoken;

        $url = 'https://' . $apiHost . '/' . \ltrim($endpoint, '/');

        // DEBUG: 记录请求信息
        Env::log_warning('gname_api', "请求: url={$url}, appid={$appId}, gntime={$data['gntime']}");

        $ch = \curl_init();

        \curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $this->configSsl($ch);

        $responseBody = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = \curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = \curl_error($ch);
        $errno = \curl_errno($ch);

        \curl_close($ch);

        // DEBUG: 记录响应信息
        Env::log_warning('gname_api', "响应: http_code={$httpCode}, effective_url={$effectiveUrl}, body_len=" . \strlen((string)$responseBody));

        if ($errno !== 0 || $responseBody === false) {
            Env::log_error('gname_api', "请求失败: endpoint={$endpoint}, http_code={$httpCode}, error={$error}, errno={$errno}");
            throw new \RuntimeException(
                __('GName API 请求失败：%{1}', [$error ?: __('网络错误')])
            );
        }

        $result = \json_decode((string) $responseBody, true);
        if (!\is_array($result)) {
            $bodySnippet = \mb_substr((string) $responseBody, 0, 500);
            Env::log_error('gname_api', "响应解析失败: endpoint={$endpoint}, http_code={$httpCode}, body={$bodySnippet}");
            throw new \RuntimeException(__('GName API 响应解析失败 (HTTP %{1}): %{2}', [$httpCode, $bodySnippet ?: __('空响应')]));
        }

        return $result;
    }

    /**
     * 配置 SSL 证书路径（兼容多平台）
     *
     * @param \CurlHandle $ch
     */
    private function configSsl(\CurlHandle $ch): void
    {
        $isWindows = \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
        $isProduction = \getenv('APP_ENV') === 'production';

        if (!$isProduction) {
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            return;
        }

        $caPaths = [
            \ini_get('curl.cainfo'),
            \ini_get('openssl.cafile'),
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/usr/local/etc/openssl/cert.pem',
            '/etc/ssl/cert.pem',
        ];

        if ($isWindows) {
            $winDir = \getenv('WINDIR') ?: 'C:\\Windows';
            $caPaths[] = $winDir . '\\System32\\curl-ca-bundle.crt';
        }

        foreach ($caPaths as $path) {
            if ($path && \file_exists($path)) {
                \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                \curl_setopt($ch, CURLOPT_CAINFO, $path);
                return;
            }
        }

        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    /**
     * 从域名中提取 TLD
     */
    private function extractTld(string $domain): string
    {
        $parts = \explode('.', $domain);
        if (\count($parts) < 2) {
            return $domain;
        }
        \array_shift($parts);
        return \implode('.', $parts);
    }

    /**
     * 获取 TLD 价格映射表（Tld => 价格数组）
     *
     * @return array<string, array{Tld: string, Register: string, Renew: string, Transfer: string}>
     */
    private function fetchTldPriceMap(array $credentials): array
    {
        static $cache = [];

        $cacheKey = $credentials['appid'] ?? '';
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        try {
            $prices = $this->getTldPrices($credentials);
            $map = [];
            foreach ($prices as $item) {
                if (isset($item['Tld'])) {
                    $map[\strtolower($item['Tld'])] = $item;
                }
            }
            $cache[$cacheKey] = $map;
            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 将 GName 域名状态标准化
     */
    private function normalizeStatus(string $gnameStatus): string
    {
        $statusMap = [
            '1' => 'active',
            '0' => 'pending',
            '-1' => 'expired',
            'active' => 'active',
            'pending' => 'pending',
            'expired' => 'expired',
            'clienthold' => 'suspended',
            'serverhold' => 'suspended',
        ];

        $lower = \strtolower($gnameStatus);
        return $statusMap[$lower] ?? $gnameStatus;
    }
}
