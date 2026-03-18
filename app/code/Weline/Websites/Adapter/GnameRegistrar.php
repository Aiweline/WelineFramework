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
use Weline\Websites\Adapter\Concern\DnsCdnZoneRecordsProviderTrait;
use Weline\Websites\Adapter\Concern\DomainRegistrarZoneDefaultsTrait;
use Weline\Websites\Adapter\Concern\RegistrarBatchCheckAvailabilityTrait;
use Weline\Websites\Adapter\Concern\RegistrarMapsDomainListToHostedTrait;
use Weline\Websites\Api\DomainRegistrarInterface;

class GnameRegistrar implements DomainRegistrarInterface
{
    use DomainRegistrarZoneDefaultsTrait;
    use RegistrarMapsDomainListToHostedTrait {
        RegistrarMapsDomainListToHostedTrait::getHostedDomainList insteadof DomainRegistrarZoneDefaultsTrait;
    }
    use DnsCdnZoneRecordsProviderTrait;
    use RegistrarBatchCheckAvailabilityTrait;
    /** 官方接口域名 */
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
            'purchase_help_steps' => [
                __('【资质】须为 GName 经销商并完成 API 服务协议签署，否则无法调用注册接口（api/domain/reg）。'),
                __('【账户余额】注册会从经销商账户扣款，请保证余额充足。'),
                __('【联系人模板】建议在凭据「默认模板 ID」或购买弹窗中填写 template_id（mbid），对应控制台已审核的联系人模板；否则可能注册失败。'),
                __('【溢价域名】若返回溢价价，需在本系统填写确认金额后再提交。'),
            ],
            'help_steps' => [
                __('【开通 API】注册账号 → 升级经销商 → 签署 API 协议（商务 business@gname.com / 工单 / +65-65189986）'),
                __('【凭证】控制中心 → API 管理 → 复制 APP ID、APP Key 填入上方'),
                __('【接口地址】api.gname.com，勿填错。'),
            ],
        ];
    }

    public function testConnection(array $credentials): bool
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('api/user/info', [], $credentials);

        if (($response['code'] ?? 0) !== 1) {
            $errorMsg = $response['msg'] ?? __('未知错误');
            $errorCode = $response['code'] ?? 0;
            $requestId = $response['requestid'] ?? '';
            
            // 开发环境下返回更详细的错误信息
            if (DEV) {
                $details = [
                    'code' => $errorCode,
                    'msg' => $errorMsg,
                    'requestid' => $requestId,
                    'appid' => $credentials['appid'] ?? '',
                    'api_host' => $credentials['api_host'] ?? self::DEFAULT_API_HOST,
                ];
                throw new \RuntimeException(
                    __('GName API 连接失败：%{1}（错误码：%{2}，请求ID：%{3}，AppID：%{4}，API地址：%{5}）', [
                        $errorMsg,
                        $errorCode,
                        $requestId,
                        $details['appid'],
                        $details['api_host'],
                    ])
                );
            }
            
            throw new \RuntimeException(
                __('GName API 连接失败：%{1}', [$errorMsg])
            );
        }

        return true;
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

        $priceMap = $this->fetchTldPriceMap($credentials);
        $tld = $this->extractTld($domain);
        $tldPrice = $priceMap[$tld] ?? null;
        $estimatedPrice = (float) ($tldPrice['Register'] ?? 0);

        // 安全优先：GName 官方文档中 `api/domain/reg` 是真实注册接口。
        // 为避免“检查即下单”，可用性检查仅使用无副作用的 DNS 启发式判定。
        $hasNsRecords = $this->hasDnsRecords($domain, \DNS_NS);
        $hasAddressRecords = $this->hasDnsRecords($domain, \DNS_A | \DNS_AAAA);
        $looksRegistered = $hasNsRecords || $hasAddressRecords;

        return [
            'available' => !$looksRegistered,
            'domain' => $domain,
            'price' => $estimatedPrice,
            'currency' => 'USD',
            'premium' => false,
            'message' => $looksRegistered
                ? __('检测到 DNS 记录，域名大概率已注册')
                : __('未检测到 DNS 记录，域名可能可注册（以下单结果为准）'),
        ];
    }

    private function hasDnsRecords(string $domain, int $dnsType): bool
    {
        try {
            $records = @\dns_get_record($domain, $dnsType);
            return \is_array($records) && $records !== [];
        } catch (\Throwable) {
            return false;
        }
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

        // GName 有时会在域名已成功进入当前账户时返回“已被注册”类提示。
        // 对这类歧义响应执行二次确认，避免“已购买却显示失败”。
        if ($this->shouldConfirmPurchasedDomain($code, $response)) {
            $confirmedResult = $this->confirmDomainOwnedByCurrentAccount($domain, $credentials, $response);
            if ($confirmedResult !== null) {
                return $confirmedResult;
            }
        }

        $errorMsg = $response['msg'] ?? __('未知错误');
        return [
            'success' => false,
            'domain' => $domain,
            'message' => __('域名注册失败：%{1}（错误码：%{2}）', [$errorMsg, $code]),
        ];
    }

    public function getDomainList(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $page = 1;
        $pageSize = 100;
        $allDomains = [];

        do {
            $response = $this->makeRequest('api/domain/list', [
                'page' => (string) $page,
                'limit' => (string) $pageSize,
            ], $credentials);

            $code = (int) ($response['code'] ?? 0);

            if ($code !== 1) {
                if ($page === 1) {
                    $errorMsg = $response['msg'] ?? __('API 请求失败');
                    throw new \RuntimeException(
                        __('获取域名列表失败：%{1}（错误码：%{2}）', [$errorMsg, $code])
                    );
                }
                break;
            }

            if (!\is_array($response['data'] ?? null)) {
                break;
            }

            $list = $response['data']['list'] ?? $response['data'] ?? [];
            if (!\is_array($list) || $list === []) {
                break;
            }

            foreach ($list as $item) {
                $statusRaw = $item['ztstr'] ?? $item['zt'] ?? $item['status'] ?? '';
                $dnsStr = (string) ($item['dns'] ?? $item['ymdns'] ?? '');
                $nameservers = $dnsStr !== '' ? \array_map('trim', \explode(',', $dnsStr)) : [];

                $allDomains[] = [
                    'domain' => (string) ($item['ym'] ?? $item['domain'] ?? ''),
                    'status' => $this->normalizeStatus((string) $statusRaw),
                    'expires_at' => (string) ($item['dqsj'] ?? $item['expires_at'] ?? ''),
                    'auto_renew' => false,
                    'nameservers' => $nameservers,
                ];
            }

            $total = (int) ($response['count'] ?? $response['data']['total'] ?? 0);
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

        $code = (int) ($response['code'] ?? 0);
        if ($code !== 1) {
            $errorMsg = $response['msg'] ?? __('未知错误');
            throw new \RuntimeException(
                __('获取域名详情失败：%{1}（错误码：%{2}）', [$errorMsg, $code])
            );
        }

        $data = $response['data'] ?? [];
        $nameservers = [];
        // GName api/domain/info 官方返回字段为 ymdns（域名DNS），非 dns
        $dnsStr = (string) ($data['ymdns'] ?? $data['dns'] ?? '');
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
     * 修改域名 NS（内部实现，由 updateNameservers() 调用）
     *
     * @param string $domain 域名
     * @param string $dnsServers 逗号分隔的 NS，如 "ns1.cf.com,ns2.cf.com"
     * @param array $credentials API 凭据
     * @return array{success: bool, message: string}
     */
    private function modifyDns(string $domain, string $dnsServers, array $credentials): array
    {
        $this->validateCredentials($credentials);

        w_log_info(__('[GName] modifyDns 请求：domain=%{1}, dns=%{2}', [$domain, $dnsServers]), [], 'dns_cdn_switch');

        $response = $this->makeRequest('api/domain/dns', [
            'ym' => $domain,
            'dns' => $dnsServers,
        ], $credentials);

        w_log_info(__('[GName] dns 响应：code=%{1}, msg=%{2}', [
            (string) ($response['code'] ?? '?'),
            (string) ($response['msg'] ?? ''),
        ]), [], 'dns_cdn_switch');

        $code = (int) ($response['code'] ?? 0);

        if ($code === 1) {
            w_log_info(__('[GName] NS 切换成功：%{1} → %{2}', [$domain, $dnsServers]), [], 'dns_cdn_switch');
            return [
                'success' => true,
                'message' => $response['msg'] ?? __('DNS 修改成功'),
            ];
        }

        $errorMsg = $response['msg'] ?? __('未知错误');
        w_log_error(__('[GName] NS 切换失败：%{1}, code=%{2}, msg=%{3}', [$domain, (string) $code, $errorMsg]), [], 'dns_cdn_switch');
        return [
            'success' => false,
            'message' => __('DNS 修改失败：%{1}（错误码：%{2}）', [$errorMsg, $code]),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAccountBalance(array $credentials): array
    {
        return $this->getBalance($credentials);
    }

    /**
     * @inheritDoc
     */
    public function getContactTemplates(array $credentials): array
    {
        return $this->getTemplates($credentials);
    }

    /**
     * 获取联系人模板列表
     */
    private function getTemplates(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('api/tpl/list', [], $credentials);

        $code = (int) ($response['code'] ?? 0);
        if ($code !== 1) {
            $errorMsg = $response['msg'] ?? __('未知错误');
            throw new \RuntimeException(
                __('获取联系人模板列表失败：%{1}（错误码：%{2}）', [$errorMsg, $code])
            );
        }

        return $response['data'] ?? [];
    }

    /**
     * @inheritDoc
     */
    public function getTldPrices(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('api/domain/price', [], $credentials);

        $code = (int) ($response['code'] ?? 0);
        if ($code !== 1) {
            $errorMsg = $response['msg'] ?? __('未知错误');
            throw new \RuntimeException(
                __('获取 TLD 价格列表失败：%{1}（错误码：%{2}）', [$errorMsg, $code])
            );
        }

        return $response['data'] ?? [];
    }

    /**
     * 获取账户余额信息（内部实现）
     */
    private function getBalance(array $credentials): array
    {
        $this->validateCredentials($credentials);

        $response = $this->makeRequest('api/user/balance', [], $credentials);

        $code = (int) ($response['code'] ?? 0);
        if ($code !== 1) {
            $errorMsg = $response['msg'] ?? __('未知错误');
            throw new \RuntimeException(
                __('获取账户余额失败：%{1}（错误码：%{2}）', [$errorMsg, $code])
            );
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
     * 根据 API 主机和端点构建请求路径
     * api.gname.com（官方接口）：路径为 /api/domain/dns、/api/domain/reg 等，endpoint 原样返回
     * www.gname.com：路径为 /domain/api/... 格式（兼容旧配置）
     */
    private function buildApiPath(string $apiHost, string $endpoint): string
    {
        $endpoint = \ltrim($endpoint, '/');
        $hostLower = \strtolower($apiHost);
        if (\str_contains($hostLower, 'www.gname')) {
            $suffix = \preg_replace('#^api/#', '', $endpoint);
            return 'domain/api/' . $suffix;
        }
        return $endpoint;
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
        $normalizedEndpoint = \strtolower(\trim($endpoint, '/'));
        if ($dryRun && $normalizedEndpoint === 'api/domain/reg') {
            throw new \RuntimeException(
                __('安全限制：禁止使用注册接口执行可用性检查，以避免误购买')
            );
        }

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

        // 官方接口：api.gname.com，路径为 /api/domain/{接口} 等
        $path = $this->buildApiPath($apiHost, $endpoint);
        $url = 'https://' . $apiHost . '/' . $path;

        // DEBUG: 记录请求信息（开发环境下包含签名详情）
        // 重新计算签名字符串用于调试
        $debugParams = $data;
        unset($debugParams['gntoken']);
        \ksort($debugParams);
        $debugParts = [];
        foreach ($debugParams as $k => $v) {
            $debugParts[] = $k . '=' . \urlencode(\trim((string)$v));
        }
        $signString = \implode('&', $debugParts);
        $signStringWithKey = $signString . $appKey;

        // 完整的调试信息（JSON 格式，方便技术排查）
        $debugInfo = [
            'request_time' => \date('Y-m-d H:i:s'),
            'url' => $url,
            'method' => 'POST',
            'content_type' => 'application/x-www-form-urlencoded',
            'params' => [
                'appid' => $appId,
                'gntime' => $data['gntime'],
                'gntoken' => $gntoken,
            ],
            'signature' => [
                'step1_params_sorted' => $debugParams,
                'step2_string_before_key' => $signString,
                'step3_string_with_key' => $signStringWithKey,
                'step4_md5_upper' => $gntoken,
            ],
            'post_body' => \http_build_query($data),
        ];
        w_log_warning("===== GName API 请求详情 =====", [], 'gname_api');
        w_log_warning(\json_encode($debugInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), [], 'gname_api');

        $ch = \curl_init();

        $deployMode = Env::system('deploy') ?? 'prod';
        $isDev = \in_array($deployMode, ['dev', 'development', 'local'], true);

        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json, text/plain, */*',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Accept-Encoding: gzip, deflate',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Origin: https://' . $apiHost,
                'Referer: https://' . $apiHost . '/',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
            CURLOPT_SSL_VERIFYPEER => !$isDev,
            CURLOPT_SSL_VERIFYHOST => $isDev ? 0 : 2,  // 开发环境禁用 SSL 主机名验证
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_ENCODING => '',
        ];

        // 开发环境：完全禁用 SSL 验证，强制 TLS 1.2 以避免 unexpected eof
        if ($isDev) {
            $curlOpts[CURLOPT_SSLVERSION] = \CURL_SSLVERSION_TLSv1_2;
            if (\defined('CURLSSLOPT_NO_REVOKE')) {
                $curlOpts[CURLOPT_SSL_OPTIONS] = \CURLSSLOPT_NO_REVOKE;
            }
        }

        \curl_setopt_array($ch, $curlOpts);

        $responseBody = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = \curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = \curl_error($ch);
        $errno = \curl_errno($ch);

        \curl_close($ch);

        // DEBUG: 记录响应信息（含完整响应体）
        w_log_warning("响应: http_code={$httpCode}, effective_url={$effectiveUrl}, body=" . $responseBody, [], 'gname_api');

        if ($errno !== 0 || $responseBody === false) {
            w_log_error("请求失败: endpoint={$endpoint}, http_code={$httpCode}, error={$error}, errno={$errno}", [], 'gname_api');
            throw new \RuntimeException(
                __('GName API 请求失败：%{1}', [$error ?: __('网络错误')])
            );
        }

        $result = \json_decode((string) $responseBody, true);
        if (!\is_array($result)) {
            $bodyTrim = \trim((string) $responseBody);
            $bodySnippet = \mb_substr($bodyTrim, 0, 800);
            w_log_error("响应解析失败: endpoint={$endpoint}, http_code={$httpCode}, effective_url={$effectiveUrl}, body=" . \mb_substr($bodyTrim, 0, 500), [], 'gname_api');
            $detail = __('HTTP 状态: %{1} | 请求 URL: %{2} | 响应内容摘要: %{3}', [
                $httpCode,
                $effectiveUrl ?: $url,
                $bodySnippet !== '' ? $bodySnippet : __('空响应'),
            ]);
            throw new \RuntimeException(__('GName API 响应解析失败：%{1}', [$detail]));
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
        // 使用框架的部署模式检测
        $deployMode = Env::system('deploy') ?? 'prod';
        $isDev = \in_array($deployMode, ['dev', 'development', 'local'], true);

        // 开发环境下直接禁用 SSL 验证
        if ($isDev) {
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            return;
        }

        // 生产环境尝试查找 CA 证书
        $isWindows = \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
        
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

        // 找不到 CA 证书则禁用验证
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
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
        } catch (\RuntimeException $e) {
            if (\str_contains($e->getMessage(), '权限') || \str_contains($e->getMessage(), '-1002')) {
                throw $e;
            }
            w_log_warning('获取 TLD 价格失败（非致命）: ' . $e->getMessage(), [], 'gname_api');
            return [];
        } catch (\Throwable $e) {
            w_log_warning('获取 TLD 价格失败（非致命）: ' . $e->getMessage(), [], 'gname_api');
            return [];
        }
    }

    /**
     * 判断是否需要把购买结果再到当前账号中做一次确认。
     */
    private function shouldConfirmPurchasedDomain(int $code, array $response): bool
    {
        if ($code !== -1) {
            return false;
        }

        $message = \mb_strtolower(\trim((string) ($response['msg'] ?? '')));
        if ($message === '') {
            return true;
        }

        $keywords = [
            '已被注册',
            'already registered',
            'already exists',
            'has been registered',
        ];

        foreach ($keywords as $keyword) {
            if (\mb_stripos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 若域名已出现在当前账号域名列表中，则视为购买成功。
     */
    private function confirmDomainOwnedByCurrentAccount(string $domain, array $credentials, array $response): ?array
    {
        try {
            $domains = $this->getDomainList($credentials);
            if ($this->domainExistsInList($domain, $domains)) {
                $price = \is_numeric($response['data'] ?? null) ? (float) $response['data'] : 0.0;
                $originalMessage = (string) ($response['msg'] ?? __('域名已在当前账号下'));

                return [
                    'success' => true,
                    'domain' => $domain,
                    'price' => $price,
                    'currency' => 'USD',
                    'message' => __('域名已在当前账号下，已按成功处理：%{message}', ['message' => $originalMessage]),
                    'ownership_confirmed' => true,
                ];
            }
        } catch (\Throwable $e) {
            w_log_warning(
                __('GName 购买结果二次确认失败：%{domain}，错误：%{error}', [
                    'domain' => $domain,
                    'error' => $e->getMessage(),
                ]),
                [],
                'gname_api'
            );
        }

        return null;
    }

    /**
     * 检查域名是否已出现在账号域名列表中。
     */
    private function domainExistsInList(string $domain, array $domains): bool
    {
        $expectedDomain = \strtolower(\trim($domain));
        foreach ($domains as $item) {
            $currentDomain = \strtolower(\trim((string) ($item['domain'] ?? '')));
            if ($currentDomain === $expectedDomain) {
                return true;
            }
        }

        return false;
    }

    /**
     * 将 GName 域名状态标准化
     */
    /**
     * 标准化 GName 域名状态
     *
     * GName 状态码（zt 字段）：
     *   0 = 正常 (active)
     *   1 = 锁定/暂停
     *  -1 = 过期
     *
     * @param string $gnameStatus GName 返回的状态值（zt 或 ztstr）
     * @return string 标准化状态：active, pending, expired, suspended
     */
    private function normalizeStatus(string $gnameStatus): string
    {
        $statusMap = [
            // GName zt 数字状态码
            '0' => 'active',      // zt=0 表示正常
            '1' => 'suspended',   // zt=1 表示锁定/暂停
            '-1' => 'expired',    // zt=-1 表示过期
            // GName ztstr 中文状态
            '正常' => 'active',
            '锁定' => 'suspended',
            '暂停' => 'suspended',
            '过期' => 'expired',
            '赎回期' => 'expired',
            // 英文状态（兼容）
            'active' => 'active',
            'pending' => 'pending',
            'expired' => 'expired',
            'clienthold' => 'suspended',
            'serverhold' => 'suspended',
        ];

        $trimmed = \trim($gnameStatus);
        return $statusMap[$trimmed] ?? $statusMap[\strtolower($trimmed)] ?? $trimmed;
    }

    // ============================================================
    // DNS 记录管理（v1.5.0 新增，接口方法实现）
    // ============================================================

    /**
     * @inheritDoc
     */
    public function supportsDnsManagement(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getDnsRecords(string $domain, array $credentials): array
    {
        $this->validateCredentials($credentials);

        // 官方 /api/resolution/list 分页；须拉全量以便与 Cloudflare 等一致供权威记录比对
        $records = [];
        $page = 1;
        $perPage = 100;
        $domainLower = \strtolower(\trim($domain));

        do {
            $response = $this->makeRequest('api/resolution/list', [
                'ym' => $domain,
                'page' => (string) $page,
                'limit' => (string) $perPage,
            ], $credentials);

            $code = (int) ($response['code'] ?? 0);
            if ($code !== 1) {
                $errorMsg = $response['msg'] ?? __('未知错误');
                throw new \RuntimeException(
                    __('获取 DNS 记录失败：%{1}（错误码：%{2}）', [$errorMsg, $code])
                );
            }

            $list = $response['data'] ?? [];
            if (!\is_array($list) || $list === []) {
                break;
            }

            foreach ($list as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $hostRaw = \trim((string) ($item['host'] ?? $item['zj'] ?? $item['zjt'] ?? '@'), '.');
                $hostLower = \strtolower($hostRaw);
                if ($hostRaw === '' || $hostRaw === '@') {
                    $host = '@';
                } elseif ($hostLower === $domainLower) {
                    $host = '@';
                } elseif (\str_ends_with($hostLower, '.' . $domainLower)) {
                    $host = \substr($hostRaw, 0, -\strlen('.' . $domainLower));
                    if ($host === '') {
                        $host = '@';
                    }
                } else {
                    $host = $hostRaw;
                }
                $records[] = [
                    'record_id' => (string) ($item['jxid'] ?? $item['id'] ?? $item['record_id'] ?? ''),
                    'type' => \strtoupper((string) ($item['type'] ?? $item['lx'] ?? 'A')),
                    'host' => $host,
                    'value' => (string) ($item['value'] ?? $item['jlz'] ?? $item['jxz'] ?? ''),
                    'ttl' => (int) ($item['ttl'] ?? 600),
                    'priority' => (int) ($item['priority'] ?? $item['mx'] ?? 0),
                ];
            }

            $total = (int) ($response['count'] ?? 0);
            if ($total > 0 && \count($records) >= $total) {
                break;
            }
            if (\count($list) < $perPage) {
                break;
            }
            $page++;
        } while ($page <= 500);

        return $records;
    }

    /**
     * GName 权威解析列表字段与 host 归一化逻辑见 {@see getDnsRecords}，此处按相同规则将记录映射到 FQDN 再比对 A/AAAA。
     */
    public function checkFqdnOriginPointsToServer(
        string $fqdn,
        string $zoneRoot,
        array $credentials,
        string $serverIpv4,
        string $serverIpv6,
    ): array {
        $base = [
            'matches' => false,
            'api_ok' => false,
            'has_direct_records' => false,
            'origin_ipv4' => '',
            'origin_ipv6' => '',
            'error' => '',
        ];
        $fqdn = \strtolower(\trim($fqdn));
        $zoneRoot = \strtolower(\trim($zoneRoot));
        if ($fqdn === '' || $zoneRoot === '') {
            return $base;
        }
        if (!\str_ends_with($fqdn, $zoneRoot) && $fqdn !== $zoneRoot) {
            return \array_merge($base, ['error' => __('FQDN 与 Zone 根域不匹配')]);
        }
        try {
            $records = $this->getDnsRecords($zoneRoot, $credentials);
        } catch (\Throwable $e) {
            return \array_merge($base, ['error' => $e->getMessage()]);
        }
        $ipv4 = [];
        $ipv6 = [];
        foreach ($records as $r) {
            if (!\is_array($r)) {
                continue;
            }
            $host = \strtolower(\trim((string) ($r['host'] ?? '@'), '.'));
            $recordFqdn = ($host === '' || $host === '@') ? $zoneRoot : ($host . '.' . $zoneRoot);
            if ($recordFqdn !== $fqdn) {
                continue;
            }
            $type = \strtoupper((string) ($r['type'] ?? ''));
            $val = \trim((string) ($r['value'] ?? ''));
            if ($type === 'A' && $val !== '') {
                $ipv4[] = $val;
            }
            if ($type === 'AAAA' && $val !== '') {
                $ipv6[] = \strtolower($val);
            }
        }
        if ($ipv4 === [] && $ipv6 === []) {
            return [
                'matches' => false,
                'api_ok' => true,
                'has_direct_records' => false,
                'origin_ipv4' => '',
                'origin_ipv6' => '',
                'error' => '',
            ];
        }
        $originV4 = \implode(',', $ipv4);
        $originV6 = \implode(',', $ipv6);
        $matchV4 = $serverIpv4 !== '' && \in_array($serverIpv4, $ipv4, true);
        $matchV6 = $serverIpv6 !== '' && \in_array(\strtolower($serverIpv6), $ipv6, true);

        return [
            'matches' => $matchV4 || $matchV6,
            'api_ok' => true,
            'has_direct_records' => true,
            'origin_ipv4' => $originV4,
            'origin_ipv6' => $originV6,
            'error' => '',
        ];
    }

    /**
     * 新增解析：官方文档 https://www.gname.com/zhcn/domain/api/jiexi/add
     * POST /api/resolution/add — ym,lx,zj,jlz,mx,ttl,xl；lx 含 TXT；zj 为主机记录（如 _acme-challenge 或 _acme-challenge.www）。
     * 文档表「MX 值 mx」为必填：非 MX 类型须传 0（此前 TXT 未传 mx 可能与官方约定不一致）。
     *
     * @inheritDoc
     */
    public function addDnsRecord(string $domain, array $record, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $lx = \strtoupper((string) ($record['type'] ?? 'A'));
        $params = [
            'ym' => $domain,
            'lx' => $lx,
            'zj' => (string) ($record['host'] ?? '@'),
            'jlz' => (string) ($record['value'] ?? ''),
            'ttl' => (string) ($record['ttl'] ?? '600'),
            'xl' => (string) ($record['line'] ?? '0'),
            'mx' => $lx === 'MX' ? (string) (int) ($record['priority'] ?? 10) : '0',
        ];

        // API 成功 ≠ 全球权威查询稳定，见类注释
        $response = $this->makeRequest('api/resolution/add', $params, $credentials);

        $code = (int) ($response['code'] ?? 0);
        $dnsResponse = [
            'provider' => 'gname',
            'code' => $code,
            'msg' => $response['msg'] ?? '',
            'data' => $response['data'] ?? null,
        ];

        if ($code === 1) {
            $data = $response['data'] ?? null;
            if (\is_array($data)) {
                $recordId = (string) ($data['id'] ?? $data['jid'] ?? '');
            } else {
                $recordId = ($data !== null && $data !== '') ? (string) $data : '';
            }
            return [
                'success' => true,
                'record_id' => $recordId,
                'message' => $response['msg'] ?? __('DNS 记录添加成功'),
                'dns_response' => $dnsResponse,
            ];
        }

        $errorMsg = $response['msg'] ?? __('未知错误');

        // 提供更友好的错误解释
        $hint = '';
        if ($code === -1002 || $code === -1001) {
            $hint = __('（域名 DNS 可能已切换到其他服务商，请到对应服务商处添加解析）');
        } elseif ($code === -1003) {
            $hint = __('（域名可能不存在或已过期）');
        } elseif (\str_contains($errorMsg, '不存在') || \str_contains($errorMsg, 'not exist')) {
            $hint = __('（域名 DNS 可能已切换到其他服务商）');
        } elseif (\str_contains($errorMsg, '权限') || \str_contains($errorMsg, 'permission')) {
            $hint = __('（无权操作此域名 DNS，可能已切换到其他服务商）');
        }

        return [
            'success' => false,
            'message' => __('DNS 记录添加失败：%{1}（错误码：%{2}）', [$errorMsg, $code]) . $hint,
            'dns_response' => $dnsResponse,
        ];
    }

    /**
     * @inheritDoc
     */
    public function updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array
    {
        $this->validateCredentials($credentials);

        $lx = \strtoupper((string) ($record['type'] ?? 'A'));
        $params = [
            'ym' => $domain,
            'jxid' => $recordId,
            'lx' => $lx,
            'zj' => (string) ($record['host'] ?? '@'),
            'jlz' => (string) ($record['value'] ?? ''),
            'ttl' => (string) ($record['ttl'] ?? '600'),
            'xl' => (string) ($record['line'] ?? '0'),
            'mx' => $lx === 'MX' ? (string) (int) ($record['priority'] ?? 10) : '0',
        ];

        // 官方文档「修改域名解析」https://www.gname.com/zhcn/domain/api/jiexi/edit
        $response = $this->makeRequest('api/resolution/edit', $params, $credentials);

        $code = (int) ($response['code'] ?? 0);
        if ($code === 1) {
            return [
                'success' => true,
                'message' => $response['msg'] ?? __('DNS 记录更新成功'),
            ];
        }

        $errorMsg = $response['msg'] ?? __('未知错误');
        return [
            'success' => false,
            'message' => __('DNS 记录更新失败：%{1}（错误码：%{2}）', [$errorMsg, $code]),
        ];
    }

    /**
     * @inheritDoc
     */
    public function deleteDnsRecord(string $domain, string $recordId, array $credentials): array
    {
        $this->validateCredentials($credentials);

        // 官方文档「删除域名解析」请求 URL 为 /api/resolution/delete
        $response = $this->makeRequest('api/resolution/delete', [
            'ym' => $domain,
            'jxid' => $recordId,
        ], $credentials);

        $code = (int) ($response['code'] ?? 0);
        if ($code === 1) {
            return [
                'success' => true,
                'message' => $response['msg'] ?? __('DNS 记录删除成功'),
            ];
        }

        $errorMsg = $response['msg'] ?? __('未知错误');
        return [
            'success' => false,
            'message' => __('DNS 记录删除失败：%{1}（错误码：%{2}）', [$errorMsg, $code]),
        ];
    }

    /**
     * @inheritDoc
     */
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

    /**
     * @inheritDoc
     */
    public function updateNameservers(string $domain, array $nameservers, array $credentials): array
    {
        $dnsStr = \implode(',', \array_filter(\array_map('trim', $nameservers)));
        w_log_info(__('[GName] updateNameservers：domain=%{1}, ns=%{2}', [$domain, $dnsStr]), [], 'dns_cdn_switch');
        return $this->modifyDns($domain, $dnsStr, $credentials);
    }

    /**
     * @inheritDoc
     *
     * GName 使用自己的 DNS 服务器，返回 GName 默认的 Nameserver
     */
    public function getProviderNameservers(array $credentials, string $domain = ''): array
    {
        return [
            'success' => true,
            'nameservers' => [
                'ns1.gname.com',
                'ns2.gname.com',
            ],
            'message' => __('GName 默认 DNS 服务器'),
        ];
    }

    /**
     * @inheritDoc
     *
     * GName 是真正的域名注册商，可以购买和管理域名。
     * getDomainList 返回的是真正拥有的域名。
     */
    public function isDomainRegistrar(): bool
    {
        return true;
    }
}
