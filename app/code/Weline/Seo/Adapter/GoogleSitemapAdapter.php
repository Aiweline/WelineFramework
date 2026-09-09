<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Adapter;

use Weline\Seo\Service\SeoAccountConfig;

/**
 * Google Search Console Sitemap 适配器
 *
 * Google 平台规则：
 * - 最大 50,000 条 URL
 * - 最大 50 MB（未压缩）
 * - 使用 Search Console Sitemap API 提交 Sitemap（2023年6月后 Ping 方式已弃用）
 * - 支持 Service Account 认证
 *
 * @package Weline_Seo
 */
class GoogleSitemapAdapter extends AbstractSitemapPlatformAdapter
{
    /**
     * Google 规则常量
     */
    public const MAX_URLS = 50000;
    public const MAX_SIZE = 52428800; // 50 MB
    public const INDEXING_API_URL = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    public const INDEXING_API_SCOPE = 'https://www.googleapis.com/auth/indexing';
    public const WEBMASTER_API_SCOPE = 'https://www.googleapis.com/auth/webmasters';
    public const WEBMASTER_READONLY_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    public const SITEMAP_SUBMIT_URL = 'https://www.googleapis.com/webmasters/v3/sites/%s/sitemaps/%s';
    public const SEARCH_ANALYTICS_URL = 'https://searchconsole.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query';
    public const SITEMAPS_LIST_URL = 'https://www.googleapis.com/webmasters/v3/sites/%s/sitemaps';
    
    /** @deprecated Google Ping 已于 2023 年弃用 */
    public const PING_URL = 'https://www.google.com/webmasters/sitemaps/ping?sitemap=';

    public function getPlatformCode(): string
    {
        return 'google';
    }

    public function getPlatformName(): string
    {
        return 'Google';
    }

    public function getPlatformColor(): string
    {
        return '#4285F4';
    }

    public function getMaxUrlsPerFile(): int
    {
        return self::MAX_URLS;
    }

    public function getMaxFileSizeBytes(): int
    {
        return self::MAX_SIZE;
    }

    public function supportsAutoSubmit(): bool
    {
        return true;
    }

    /**
     * 提交 sitemap 到 Google
     *
     * 使用 Search Console API（需要 OAuth2 配置）
     *
     * 账户配置支持代理设置：
     * - proxy: 代理地址，如 http://127.0.0.1:7890
     * - proxy_type: 代理类型，http 或 socks5（默认 http）
     */
    public function submitSitemap(string $sitemapUrl, array $accountConfig): array
    {
        // 从配置中提取代理设置
        $proxyConfig = $this->extractProxyConfig($accountConfig);
        
        $config = $this->resolveGoogleConfig($accountConfig);
        
        // 检测是否有 Service Account 配置（通过 client_email 和 private_key 判断）
        $hasServiceAccount = !empty($config['client_email']) && !empty($config['private_key']);
        
        // 优先使用 API 方式（Google Ping 已在 2023 年弃用）
        if ($hasServiceAccount) {
            return $this->submitViaApi($sitemapUrl, $accountConfig, $proxyConfig);
        }
        
        // 无 Service Account 配置，返回提示
        return [
            'success' => false,
            'message' => __('Google Ping 方式已于 2023 年弃用。请配置 Service Account 使用 Search Console API 提交。'),
            'response' => [
                'deprecated' => true,
                'reference' => 'https://developers.google.com/search/blog/2023/06/sitemaps-lastmod-ping',
            ],
        ];
    }

    /** Read-only Search Console property permission check; no sitemap or URL submission. */
    public function verifyAccount(array $accountConfig): array
    {
        $config = $this->resolveGoogleConfig($accountConfig);
        $proxyConfig = $this->extractProxyConfig($accountConfig);
        $helpUrl = 'https://search.google.com/search-console';
        $site = trim((string)($config['site_url'] ?? ''));
        $clientEmail = trim((string)($config['client_email'] ?? ''));

        if ($site === '') {
            return [
                'success' => false,
                'remote_verified' => false,
                'help_url' => $helpUrl,
                'message' => (string)__('未填写 Search Console 站点属性 URL。请填写与 GSC 完全一致的属性（如 https://www.example.com/ 或 sc-domain:example.com），再到 Google Search Console 验证该属性。'),
                'data' => [
                    'error_code' => 'missing_site_url',
                    'http_code' => 0,
                    'help_url' => $helpUrl,
                ],
            ];
        }

        $tokenResult = $this->requestAccessToken($config, $proxyConfig, self::WEBMASTER_API_SCOPE);
        $token = $tokenResult['access_token'] ?? null;
        if (!$token) {
            return [
                'success' => false,
                'remote_verified' => false,
                'help_url' => $helpUrl,
                'message' => (string)($tokenResult['message'] ?? __('Google 身份验证失败，请检查 Service Account 凭证')),
                'data' => [
                    'token_error' => (string)($tokenResult['error_code'] ?? 'token_failed'),
                    'http_code' => (int)($tokenResult['http_code'] ?? 0),
                    'help_url' => $helpUrl,
                    'client_email' => $clientEmail,
                ],
            ];
        }

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($site),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        ];
        if (!empty($proxyConfig['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = $proxyConfig['proxy'];
            if (($proxyConfig['proxy_type'] ?? 'http') === 'socks5') {
                $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            }
        }
        curl_setopt_array($ch, $curlOptions);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = (int)curl_errno($ch);
        curl_close($ch);

        if ($error !== '') {
            $timeout = in_array($errno, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT], true)
                || stripos($error, 'timed out') !== false
                || stripos($error, 'timeout') !== false;
            $message = $timeout
                ? (string)__('无法连接 Google Search Console API（超时）。凭证已读到，请检查服务器出网/代理（浏览器能开 Google 不等于 PHP/WLS 能出网），再到 GSC 确认属性权限。')
                : (string)__('无法连接 Google Search Console API：%{1}。请检查服务器出网/代理后重试。', $error);

            return [
                'success' => false,
                'remote_verified' => false,
                'help_url' => $helpUrl,
                'message' => $message,
                'data' => [
                    'error_code' => $timeout ? 'gsc_timeout' : 'gsc_network',
                    'http_code' => $code,
                    'curl_errno' => $errno,
                    'help_url' => $helpUrl,
                ],
            ];
        }

        $data = json_decode((string)$body, true);
        $permission = is_array($data) ? (string)($data['permissionLevel'] ?? '') : '';
        $success = $code === 200 && in_array($permission, ['siteOwner', 'siteFullUser'], true);
        if ($success) {
            return [
                'success' => true,
                'remote_verified' => true,
                'message' => (string)__('Google Search Console 站点权限验证通过'),
                'data' => [
                    'http_code' => $code,
                    'permission_level' => $permission,
                    'site_url' => $site,
                ],
            ];
        }

        $googleMessage = '';
        if (is_array($data)) {
            $googleMessage = trim((string)(($data['error']['message'] ?? '') ?: ''));
        }
        $visibleSites = $this->listSearchConsoleSites($token, $proxyConfig);
        $message = $this->buildSearchConsoleVerifyFailureMessage(
            $code,
            $permission,
            $site,
            $clientEmail,
            $googleMessage,
            $visibleSites,
            $helpUrl
        );

        return [
            'success' => false,
            'remote_verified' => false,
            'help_url' => $helpUrl,
            'message' => $message,
            'data' => [
                'error_code' => $code === 404 ? 'gsc_site_not_found' : ($code === 403 ? 'gsc_forbidden' : 'gsc_permission'),
                'http_code' => $code,
                'permission_level' => $permission,
                'site_url' => $site,
                'client_email' => $clientEmail,
                'google_message' => $googleMessage,
                'visible_sites' => $visibleSites,
                'help_url' => $helpUrl,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function listSearchConsoleSites(string $accessToken, array $proxyConfig = []): array
    {
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => 'https://www.googleapis.com/webmasters/v3/sites',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ];
        if (!empty($proxyConfig['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = $proxyConfig['proxy'];
            if (($proxyConfig['proxy_type'] ?? 'http') === 'socks5') {
                $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            }
        }
        curl_setopt_array($ch, $curlOptions);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            return [];
        }
        $decoded = json_decode((string)$body, true);
        $entries = is_array($decoded['siteEntry'] ?? null) ? $decoded['siteEntry'] : [];
        $sites = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $url = trim((string)($entry['siteUrl'] ?? ''));
            if ($url !== '') {
                $sites[] = $url;
            }
        }

        return array_values(array_unique($sites));
    }

    /**
     * @param list<string> $visibleSites
     */
    protected function buildSearchConsoleVerifyFailureMessage(
        int $httpCode,
        string $permission,
        string $site,
        string $clientEmail,
        string $googleMessage,
        array $visibleSites,
        string $helpUrl
    ): string {
        $emailHint = $clientEmail !== '' ? $clientEmail : (string)__('（当前 JSON 中的 client_email）');

        if ($httpCode === 404) {
            if ($visibleSites === []) {
                return (string)__(
                    'GSC 属性「%{1}」对该服务账号不可见（HTTP 404），且该账号下暂无任何属性。请先在 Google Search Console 验证域名/网址前缀，再将 %{2} 加成「所有者」，并把本处属性 URL 改成与 GSC 完全一致后再验证。前往：%{3}',
                    [$site, $emailHint, $helpUrl]
                );
            }

            return (string)__(
                '属性 URL 不一致：本处填写「%{1}」，但服务账号 %{2} 当前可见的是「%{3}」。说明账号多半已加入 GSC，请把本处改成与 GSC 左侧属性名完全相同的字符串（常见：域名属性要用 sc-domain:example.com，不能写成 https://www.example.com）。改完保存后再点验证。前往：%{4}',
                [$site, $emailHint, implode('、', array_slice($visibleSites, 0, 5)), $helpUrl]
            );
        }

        if ($httpCode === 403) {
            return (string)__(
                '服务账号 %{1} 无权访问 GSC 属性「%{2}」（HTTP 403）。请到 Search Console → 设置 → 用户和权限，将其加成「所有者」。前往：%{3}',
                [$emailHint, $site, $helpUrl]
            );
        }

        if ($httpCode === 200 && $permission !== '') {
            return (string)__(
                'GSC 属性「%{1}」当前权限为 %{2}，需要 siteOwner 或 siteFullUser。请将服务账号 %{3} 加成「所有者」。前往：%{4}',
                [$site, $permission, $emailHint, $helpUrl]
            );
        }

        $detail = $googleMessage !== '' ? $googleMessage : (string)__('请确认属性已验证、URL 一致，且 Service Account 已是所有者。');

        return (string)__(
            'Google Search Console 站点权限验证失败（HTTP %{1}）。%{2} 前往：%{3}',
            [$httpCode, $detail, $helpUrl]
        );
    }

    /**
     * 从账户配置中提取代理设置
     */
    protected function extractProxyConfig(array $accountConfig): array
    {
        $config = $accountConfig['config'] ?? [];
        
        return [
            'proxy' => $config['proxy'] ?? $accountConfig['proxy'] ?? '',
            'proxy_type' => $config['proxy_type'] ?? $accountConfig['proxy_type'] ?? 'http',
        ];
    }

    /**
     * 通过 Ping 方式提交
     */
    protected function submitViaPing(string $sitemapUrl, array $proxyConfig = []): array
    {
        $pingUrl = self::PING_URL . urlencode($sitemapUrl);
        
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $pingUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Weline SEO Sitemap Submitter/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        
        // 添加代理设置
        if (!empty($proxyConfig['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = $proxyConfig['proxy'];
            $curlOptions[CURLOPT_HTTPPROXYTUNNEL] = true;
            
            // 设置代理类型
            if (($proxyConfig['proxy_type'] ?? 'http') === 'socks5') {
                $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            }
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => __('Ping 请求失败：%{1}', $error),
                'response' => null,
            ];
        }
        
        $success = $httpCode >= 200 && $httpCode < 300;
        
        return [
            'success' => $success,
            'message' => $success 
                ? __('已成功通知 Google 更新 sitemap') 
                : __('Ping 请求返回错误码：%{1}', $httpCode),
            'response' => [
                'http_code' => $httpCode,
                'body' => $response,
            ],
        ];
    }

    /**
     * 通过 Google Search Console Sitemap API 提交
     *
     * 使用 Service Account 进行 JWT 认证，调用 Search Console API 绑定 sitemap。
     */
    protected function submitViaApi(string $sitemapUrl, array $accountConfig, array $proxyConfig = []): array
    {
        $config = $this->resolveGoogleConfig($accountConfig);
        
        // 验证必要的配置
        if (empty($config['client_email']) || empty($config['private_key'])) {
            return [
                'success' => false,
                'message' => __('缺少 Service Account 配置（client_email 或 private_key）'),
                'response' => null,
            ];
        }
        
        try {
            $siteUrl = $this->resolveSearchConsoleSiteUrl($sitemapUrl, $config);
            if ($siteUrl === '') {
                return [
                    'success' => false,
                    'message' => __('缺少 Search Console 站点属性 URL'),
                    'response' => null,
                ];
            }

            // 1. 获取 Access Token（使用 Search Console sitemap 写权限 scope）
            $accessToken = $this->getAccessToken($config, $proxyConfig, self::WEBMASTER_API_SCOPE);
            if (empty($accessToken)) {
                return [
                    'success' => false,
                    'message' => __('获取 Google Access Token 失败'),
                    'response' => null,
                ];
            }
            
            // 2. 调用 Search Console Sitemap API 提交 sitemap
            $apiUrl = sprintf(
                self::SITEMAP_SUBMIT_URL,
                rawurlencode($siteUrl),
                rawurlencode($sitemapUrl)
            );

            $ch = curl_init();
            $curlOptions = [
                CURLOPT_URL => $apiUrl,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Length: 0',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            
            // 添加代理设置
            if (!empty($proxyConfig['proxy'])) {
                $curlOptions[CURLOPT_PROXY] = $proxyConfig['proxy'];
                if (($proxyConfig['proxy_type'] ?? 'http') === 'socks5') {
                    $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
                }
            }
            
            curl_setopt_array($ch, $curlOptions);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                return [
                    'success' => false,
                    'message' => __('Google Search Console API 连接失败'),
                    'response' => null,
                ];
            }
            
            $responseData = json_decode($response, true);
            
            // HTTP 200 表示成功
            $success = $httpCode >= 200 && $httpCode < 300 && !isset($responseData['error']);
            
            if (!$success && isset($responseData['error'])) {
                $errorMessage = $responseData['error']['message'] ?? __('未知错误');
                $errorStatus = $responseData['error']['status'] ?? '';
                
                // 特殊处理权限错误
                if ($errorStatus === 'PERMISSION_DENIED') {
                    return [
                        'success' => false,
                        'message' => __('权限被拒绝：请确保 Service Account 已在 Search Console 中被添加为站点所有者'),
                        'response' => ['http_code' => $httpCode],
                    ];
                }
                
                return [
                    'success' => false,
                    'message' => __('Google Search Console API 请求失败（HTTP %{1}）', $httpCode),
                    'response' => ['http_code' => $httpCode],
                ];
            }
            
            return [
                'success' => $success,
                'message' => $success 
                    ? __('已通过 Google Search Console API 提交 Sitemap')
                    : __('Google Search Console API 返回错误码：%{1}', $httpCode),
                'response' => [
                    'api_url' => $apiUrl,
                    'site_url' => $siteUrl,
                    'http_code' => $httpCode,
                    'body' => null,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('Google Sitemap 提交失败，请检查账户配置和网络连接'),
                'response' => null,
            ];
        }
    }
    
    /**
     * 使用 Service Account 获取 Access Token
     *
     * @param array $config Service Account 配置
     * @param array $proxyConfig 代理配置
     * @param string $scope API scope（默认使用 Indexing API scope）
     */
    protected function getAccessToken(array $config, array $proxyConfig = [], string $scope = ''): ?string
    {
        $result = $this->requestAccessToken($config, $proxyConfig, $scope);
        $token = $result['access_token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @return array{access_token:?string,message:string,error_code:string,http_code:int}
     */
    protected function requestAccessToken(array $config, array $proxyConfig = [], string $scope = ''): array
    {
        $clientEmail = trim((string)($config['client_email'] ?? ''));
        $privateKey = (string)($config['private_key'] ?? '');
        $tokenUri = 'https://oauth2.googleapis.com/token';

        if ($clientEmail === '' || $privateKey === '') {
            return [
                'access_token' => null,
                'message' => (string)__('未读取到 Service Account（client_email/private_key 为空），请重新上传 JSON 凭证'),
                'error_code' => 'missing_credentials',
                'http_code' => 0,
            ];
        }

        if ($scope === '') {
            $scope = self::INDEXING_API_SCOPE;
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $clientEmail,
            'scope' => $scope,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $claimsEncoded = $this->base64UrlEncode(json_encode($claims));
        $signatureInput = $headerEncoded . '.' . $claimsEncoded;

        $signature = '';
        $privateKeyResource = openssl_pkey_get_private($privateKey);
        if (!$privateKeyResource) {
            return [
                'access_token' => null,
                'message' => (string)__('Service Account 私钥无法解析，请检查 JSON 中 private_key 是否完整'),
                'error_code' => 'invalid_private_key',
                'http_code' => 0,
            ];
        }

        if (!openssl_sign($signatureInput, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256)) {
            return [
                'access_token' => null,
                'message' => (string)__('Service Account JWT 签名失败，请检查私钥'),
                'error_code' => 'jwt_sign_failed',
                'http_code' => 0,
            ];
        }

        $jwt = $signatureInput . '.' . $this->base64UrlEncode($signature);

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $tokenUri,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (!empty($proxyConfig['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = $proxyConfig['proxy'];
            if (($proxyConfig['proxy_type'] ?? 'http') === 'socks5') {
                $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            }
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string)curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            $timeout = stripos($curlError, 'timed out') !== false || stripos($curlError, 'timeout') !== false;
            return [
                'access_token' => null,
                'message' => $timeout
                    ? (string)__('无法连接 Google OAuth（oauth2.googleapis.com 超时）。凭证已读到，请检查服务器出网/代理，而非重新上传 JSON')
                    : (string)__('无法连接 Google OAuth：%{1}', $curlError !== '' ? $curlError : 'network_error'),
                'error_code' => $timeout ? 'oauth_timeout' : 'oauth_network',
                'http_code' => $httpCode,
            ];
        }

        if ($httpCode !== 200) {
            $payload = json_decode((string)$response, true);
            $description = '';
            if (is_array($payload)) {
                $description = trim((string)($payload['error_description'] ?? $payload['error'] ?? ''));
            }
            return [
                'access_token' => null,
                'message' => $description !== ''
                    ? (string)__('Google OAuth 拒绝令牌（HTTP %{1}）：%{2}', [$httpCode, $description])
                    : (string)__('Google OAuth 拒绝令牌（HTTP %{1}），请检查 Service Account 与 API 启用状态', $httpCode),
                'error_code' => 'oauth_http_' . $httpCode,
                'http_code' => $httpCode,
            ];
        }

        $data = json_decode((string)$response, true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            return [
                'access_token' => null,
                'message' => (string)__('Google OAuth 响应缺少 access_token'),
                'error_code' => 'oauth_empty_token',
                'http_code' => $httpCode,
            ];
        }

        return [
            'access_token' => $token,
            'message' => '',
            'error_code' => '',
            'http_code' => $httpCode,
        ];
    }
    
    /**
     * Base64 URL 安全编码
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * 兼容 service_account 嵌套 JSON 与平铺配置。
     */
    protected function resolveGoogleConfig(array $accountConfig): array
    {
        $config = $accountConfig['config'] ?? $accountConfig;
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($config)) {
            return [];
        }

        $serviceAccount = $config['service_account'] ?? null;
        if (is_string($serviceAccount)) {
            $decoded = json_decode($serviceAccount, true);
            $serviceAccount = is_array($decoded) ? $decoded : null;
        }

        if (is_array($serviceAccount)) {
            $config = array_merge($config, $serviceAccount);
        }

        if (isset($config['site_url']) && is_string($config['site_url'])) {
            $config['site_url'] = \Weline\Seo\Service\SeoAccountConfig::normalizeGoogleSiteProperty($config['site_url']);
        }
        if (isset($config['search_console_site_url']) && is_string($config['search_console_site_url'])) {
            $config['search_console_site_url'] = \Weline\Seo\Service\SeoAccountConfig::normalizeGoogleSiteProperty($config['search_console_site_url']);
        }

        return $config;
    }

    /**
     * 获取 Search Console 中已验证的站点属性 URL。
     */
    protected function resolveSearchConsoleSiteUrl(string $sitemapUrl, array $config): string
    {
        $siteUrl = trim((string)($config['search_console_site_url'] ?? $config['site_url'] ?? ''));
        if ($siteUrl !== '') {
            return $siteUrl;
        }

        $parsed = parse_url($sitemapUrl);
        $host = (string)($parsed['host'] ?? '');
        if ($host === '') {
            return '';
        }

        return ($parsed['scheme'] ?? 'https') . '://' . $host . '/';
    }

    /**
     * Google 支持获取统计数据
     */
    public function supportsStats(): bool
    {
        return true;
    }

    /**
     * 获取 Google Search Console 统计数据
     * 
     * 包括：
     * - 搜索分析数据（点击量、展示量、CTR、平均排名）
     * - Sitemap 提交状态
     * - 索引覆盖率（如可用）
     */
    public function getStats(string $siteUrl, array $accountConfig): array
    {
        $config = $this->resolveGoogleConfig($accountConfig);
        $proxyConfig = $this->extractProxyConfig($accountConfig);
        
        // 验证必要的配置
        if (empty($config['client_email']) || empty($config['private_key'])) {
            return [
                'success' => false,
                'message' => __('缺少 Service Account 配置'),
                'data' => [],
            ];
        }
        
        try {
            // 获取 Access Token（使用 Webmaster 只读权限）
            $accessToken = $this->getAccessToken($config, $proxyConfig, self::WEBMASTER_READONLY_SCOPE);
            if (empty($accessToken)) {
                return [
                    'success' => false,
                    'message' => __('获取 Google Access Token 失败'),
                    'data' => [],
                ];
            }
            
            // 格式化站点 URL（Google 要求 URL 编码）
            $encodedSiteUrl = rawurlencode($siteUrl);
            
            $statsData = [
                'indexed_pages' => 0,
                'submitted_urls' => 0,
                'crawled_pages' => 0,
                'clicks' => 0,
                'impressions' => 0,
                'ctr' => 0.0,
                'average_position' => 0.0,
                'error_count' => 0,
                'warning_count' => 0,
                'daily_quota' => 0,
                'quota_used' => 0,
                'extra' => [],
            ];
            
            $windowStart = date('Y-m-d', strtotime('-28 days'));
            $windowEnd = date('Y-m-d', strtotime('-1 day'));
            $statsData['search_window'] = ['start' => $windowStart, 'end' => $windowEnd];
            $statsData['search_queries'] = [];
            $statsData['extra']['search_window'] = $statsData['search_window'];

            // 1. 获取搜索分析数据（最近 28 天）
            $searchAnalyticsResult = $this->fetchSearchAnalytics($encodedSiteUrl, $accessToken, $proxyConfig, $windowStart, $windowEnd);
            if ($searchAnalyticsResult['success'] && !empty($searchAnalyticsResult['data'])) {
                $analyticsData = $searchAnalyticsResult['data'];
                $statsData['clicks'] = (int)($analyticsData['clicks'] ?? 0);
                $statsData['impressions'] = (int)($analyticsData['impressions'] ?? 0);
                $statsData['ctr'] = round(($analyticsData['ctr'] ?? 0) * 100, 2); // 转为百分比
                $statsData['average_position'] = round($analyticsData['position'] ?? 0, 2);
            }
            $queryAnalyticsResult = $this->fetchSearchQueryAnalytics($encodedSiteUrl, $accessToken, $proxyConfig, $windowStart, $windowEnd);
            if ($queryAnalyticsResult['success'] && !empty($queryAnalyticsResult['rows'])) {
                $statsData['search_queries'] = $queryAnalyticsResult['rows'];
                $statsData['extra']['search_queries'] = $queryAnalyticsResult['rows'];
            }

            $statsData['extra']['distribution_channels'] = $this->extractDistributionChannels($config);
            $statsData['extra']['platform_property'] = [
                'api_status' => 'manual_only',
                'note' => (string)__('GSC Platform Property（社交/视频）暂无公开 API；渠道 URL 仅本地登记，请在 Search Console 手动添加平台属性。'),
                'gsc_url' => 'https://search.google.com/search-console',
            ];

            if (!empty($config['enable_discover_stats'])) {
                $discoverResult = $this->fetchSearchAnalytics(
                    $encodedSiteUrl,
                    $accessToken,
                    $proxyConfig,
                    $windowStart,
                    $windowEnd,
                    'discover'
                );
                if ($discoverResult['success'] && !empty($discoverResult['data'])) {
                    $discover = $discoverResult['data'];
                    $statsData['extra']['discover'] = [
                        'clicks' => (int)($discover['clicks'] ?? 0),
                        'impressions' => (int)($discover['impressions'] ?? 0),
                        'ctr' => round(((float)($discover['ctr'] ?? 0)) * 100, 2),
                        'window' => ['start' => $windowStart, 'end' => $windowEnd],
                    ];
                } else {
                    $statsData['extra']['discover'] = [
                        'clicks' => 0,
                        'impressions' => 0,
                        'ctr' => 0.0,
                        'window' => ['start' => $windowStart, 'end' => $windowEnd],
                        'error' => (string)($discoverResult['error'] ?? ''),
                    ];
                }
            }

            if (!empty($config['enable_google_news_stats'])) {
                $newsResult = $this->fetchSearchAnalytics(
                    $encodedSiteUrl,
                    $accessToken,
                    $proxyConfig,
                    $windowStart,
                    $windowEnd,
                    'googleNews'
                );
                if ($newsResult['success'] && !empty($newsResult['data'])) {
                    $news = $newsResult['data'];
                    $statsData['extra']['google_news'] = [
                        'clicks' => (int)($news['clicks'] ?? 0),
                        'impressions' => (int)($news['impressions'] ?? 0),
                        'ctr' => round(((float)($news['ctr'] ?? 0)) * 100, 2),
                        'window' => ['start' => $windowStart, 'end' => $windowEnd],
                    ];
                } else {
                    $statsData['extra']['google_news'] = [
                        'clicks' => 0,
                        'impressions' => 0,
                        'ctr' => 0.0,
                        'window' => ['start' => $windowStart, 'end' => $windowEnd],
                        'error' => (string)($newsResult['error'] ?? ''),
                    ];
                }
            }
            
            // 2. 获取 Sitemap 信息
            $sitemapsResult = $this->fetchSitemapsList($encodedSiteUrl, $accessToken, $proxyConfig);
            if ($sitemapsResult['success'] && !empty($sitemapsResult['data'])) {
                $sitemapsData = $sitemapsResult['data'];
                $totalSubmitted = 0;
                $totalIndexed = 0;
                $errors = 0;
                $warnings = 0;
                
                foreach ($sitemapsData as $sitemap) {
                    $contents = $sitemap['contents'] ?? [];
                    foreach ($contents as $content) {
                        $totalSubmitted += (int)($content['submitted'] ?? 0);
                        $totalIndexed += (int)($content['indexed'] ?? 0);
                    }
                    $errors += (int)($sitemap['errors'] ?? 0);
                    $warnings += (int)($sitemap['warnings'] ?? 0);
                }
                
                $statsData['submitted_urls'] = $totalSubmitted;
                $statsData['indexed_pages'] = $totalIndexed;
                $statsData['error_count'] = $errors;
                $statsData['warning_count'] = $warnings;
                $statsData['extra']['sitemaps'] = $sitemapsData;
            }
            
            return [
                'success' => true,
                'message' => __('成功获取 Google Search Console 统计数据'),
                'data' => $statsData,
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('获取统计数据失败：%{1}', $e->getMessage()),
                'data' => [],
            ];
        }
    }

    /**
     * @return list<array{platform:string,url:string}>
     */
    protected function extractDistributionChannels(array $config): array
    {
        $map = [
            'youtube_channel_url' => 'youtube',
            'x_profile_url' => 'x',
            'instagram_profile_url' => 'instagram',
            'tiktok_profile_url' => 'tiktok',
            'linkedin_profile_url' => 'linkedin',
        ];
        $channels = [];
        foreach ($map as $key => $platform) {
            $url = trim((string)($config[$key] ?? ''));
            if ($url === '') {
                continue;
            }
            $channels[] = [
                'platform' => $platform,
                'url' => $url,
            ];
        }

        return $channels;
    }

    /**
     * 获取搜索分析数据
     */
    protected function fetchSearchAnalytics(
        string $encodedSiteUrl,
        string $accessToken,
        array $proxyConfig,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $type = null,
    ): array {
        $startDate = $startDate ?: date('Y-m-d', strtotime('-28 days'));
        $endDate = $endDate ?: date('Y-m-d', strtotime('-1 day'));
        $body = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => [],
            'rowLimit' => 1,
        ];
        if ($type !== null && $type !== '') {
            $body['type'] = $type;
        }
        $payload = $this->requestSearchAnalytics($encodedSiteUrl, $accessToken, $proxyConfig, $body);
        if (!$payload['success']) {
            return ['success' => false, 'data' => [], 'error' => $payload['error'] ?? ''];
        }
        $data = \is_array($payload['data'] ?? null) ? $payload['data'] : [];
        if (!empty($data['rows'][0]) && \is_array($data['rows'][0])) {
            return ['success' => true, 'data' => $data['rows'][0]];
        }

        return [
            'success' => true,
            'data' => [
                'clicks' => 0,
                'impressions' => 0,
                'ctr' => 0,
                'position' => 0,
            ],
        ];
    }

    /**
     * Query-dimension GSC rows for site word-cloud heat.
     *
     * @return array{success:bool,rows:list<array<string,mixed>>,error?:string}
     */
    protected function fetchSearchQueryAnalytics(
        string $encodedSiteUrl,
        string $accessToken,
        array $proxyConfig,
        ?string $startDate = null,
        ?string $endDate = null,
        int $rowLimit = 250,
    ): array {
        $startDate = $startDate ?: date('Y-m-d', strtotime('-28 days'));
        $endDate = $endDate ?: date('Y-m-d', strtotime('-1 day'));
        $payload = $this->requestSearchAnalytics($encodedSiteUrl, $accessToken, $proxyConfig, [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['query'],
            'rowLimit' => \max(1, \min(1000, $rowLimit)),
        ]);
        if (!$payload['success']) {
            return ['success' => false, 'rows' => [], 'error' => $payload['error'] ?? ''];
        }
        $rows = [];
        $rawRows = \is_array($payload['data']['rows'] ?? null) ? $payload['data']['rows'] : [];
        foreach ($rawRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $query = \trim((string)($row['keys'][0] ?? $row['query'] ?? ''));
            if ($query === '') {
                continue;
            }
            $rows[] = [
                'query' => $query,
                'clicks' => \max(0, (int)($row['clicks'] ?? 0)),
                'impressions' => \max(0, (int)($row['impressions'] ?? 0)),
                'ctr' => (float)($row['ctr'] ?? 0),
                'position' => (float)($row['position'] ?? 0),
            ];
        }

        return ['success' => true, 'rows' => $rows];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{success:bool,data?:array<string,mixed>,error?:string}
     */
    protected function requestSearchAnalytics(
        string $encodedSiteUrl,
        string $accessToken,
        array $proxyConfig,
        array $body,
    ): array {
        $url = sprintf(self::SEARCH_ANALYTICS_URL, $encodedSiteUrl);
        $requestBody = json_encode($body);
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (!empty($proxyConfig['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = $proxyConfig['proxy'];
            if (($proxyConfig['proxy_type'] ?? 'http') === 'socks5') {
                $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            }
        }
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error || $httpCode !== 200) {
            return ['success' => false, 'error' => $error ?: "HTTP $httpCode"];
        }
        $data = json_decode((string)$response, true);

        return [
            'success' => true,
            'data' => \is_array($data) ? $data : [],
        ];
    }

    /**
     * 获取 Sitemap 列表和状态
     */
    protected function fetchSitemapsList(string $encodedSiteUrl, string $accessToken, array $proxyConfig): array
    {
        $url = sprintf(self::SITEMAPS_LIST_URL, $encodedSiteUrl);
        
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        
        if (!empty($proxyConfig['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = $proxyConfig['proxy'];
            if (($proxyConfig['proxy_type'] ?? 'http') === 'socks5') {
                $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            }
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $httpCode !== 200) {
            return ['success' => false, 'data' => [], 'error' => $error ?: "HTTP $httpCode"];
        }
        
        $data = json_decode($response, true);
        
        return [
            'success' => true,
            'data' => $data['sitemap'] ?? [],
        ];
    }

    /**
     * Google URL Inspection API（面板 SEO Tab 按需调用，不进前台常态请求）。
     *
     * @param array<string, mixed> $accountConfig
     * @return array{success:bool,message:string,data:array<string,mixed>}
     */
    public function inspectUrl(string $inspectionUrl, string $siteUrl, array $accountConfig): array
    {
        $inspectionUrl = trim($inspectionUrl);
        $siteUrl = SeoAccountConfig::normalizeGoogleSiteProperty($siteUrl);
        if ($inspectionUrl === '' || $siteUrl === '') {
            return [
                'success' => false,
                'message' => (string)__('缺少 inspectionUrl 或 site_url'),
                'data' => [],
            ];
        }

        $config = $this->resolveGoogleConfig($accountConfig);
        $proxyConfig = $this->extractProxyConfig($accountConfig);
        if (empty($config['client_email']) || empty($config['private_key'])) {
            return [
                'success' => false,
                'message' => (string)__('缺少 Service Account 配置'),
                'data' => [],
            ];
        }

        try {
            $accessToken = $this->getAccessToken($config, $proxyConfig, self::WEBMASTER_READONLY_SCOPE);
            if ($accessToken === null || $accessToken === '') {
                return [
                    'success' => false,
                    'message' => (string)__('获取 Google Access Token 失败'),
                    'data' => [],
                ];
            }

            $payload = json_encode([
                'inspectionUrl' => $inspectionUrl,
                'siteUrl' => $siteUrl,
                'languageCode' => 'zh-CN',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($payload) || $payload === '') {
                return [
                    'success' => false,
                    'message' => (string)__('无法编码 URL Inspection 请求'),
                    'data' => [],
                ];
            }

            $ch = curl_init();
            $curlOptions = [
                CURLOPT_URL => 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if (!empty($proxyConfig['proxy'])) {
                $curlOptions[CURLOPT_PROXY] = $proxyConfig['proxy'];
                if (($proxyConfig['proxy_type'] ?? 'http') === 'socks5') {
                    $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
                }
            }
            curl_setopt_array($ch, $curlOptions);
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = (string)curl_error($ch);
            curl_close($ch);

            if ($error !== '' || $httpCode < 200 || $httpCode >= 300 || !is_string($response)) {
                return [
                    'success' => false,
                    'message' => $error !== '' ? $error : (string)__('Google URL Inspection HTTP %{1}', [$httpCode]),
                    'data' => [
                        'http_code' => $httpCode,
                    ],
                ];
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                return [
                    'success' => false,
                    'message' => (string)__('Google URL Inspection 响应无法解析'),
                    'data' => [],
                ];
            }

            $result = is_array($decoded['inspectionResult'] ?? null) ? $decoded['inspectionResult'] : $decoded;
            $indexStatus = is_array($result['indexStatusResult'] ?? null) ? $result['indexStatusResult'] : [];
            $richResults = is_array($result['richResultsResult'] ?? null) ? $result['richResultsResult'] : [];

            return [
                'success' => true,
                'message' => (string)__('URL Inspection 完成'),
                'data' => [
                    'inspectionUrl' => $inspectionUrl,
                    'siteUrl' => $siteUrl,
                    'verdict' => (string)($indexStatus['verdict'] ?? ''),
                    'coverageState' => (string)($indexStatus['coverageState'] ?? ''),
                    'robotsTxtState' => (string)($indexStatus['robotsTxtState'] ?? ''),
                    'indexingState' => (string)($indexStatus['indexingState'] ?? ''),
                    'lastCrawlTime' => (string)($indexStatus['lastCrawlTime'] ?? ''),
                    'pageFetchState' => (string)($indexStatus['pageFetchState'] ?? ''),
                    'googleCanonical' => (string)($indexStatus['googleCanonical'] ?? ''),
                    'userCanonical' => (string)($indexStatus['userCanonical'] ?? ''),
                    'richResultsVerdict' => (string)($richResults['verdict'] ?? ''),
                    'detectedItems' => is_array($richResults['detectedItems'] ?? null) ? $richResults['detectedItems'] : [],
                    'raw' => $result,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }
}
