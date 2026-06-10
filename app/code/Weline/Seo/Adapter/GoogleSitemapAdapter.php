<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Adapter;

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
                    'message' => __('Google Search Console API 请求失败：%{1}', $error),
                    'response' => null,
                ];
            }
            
            $responseData = json_decode($response, true);
            
            // HTTP 200 表示成功
            $success = $httpCode >= 200 && $httpCode < 300;
            
            if (!$success && isset($responseData['error'])) {
                $errorMessage = $responseData['error']['message'] ?? __('未知错误');
                $errorStatus = $responseData['error']['status'] ?? '';
                
                // 特殊处理权限错误
                if ($errorStatus === 'PERMISSION_DENIED') {
                    return [
                        'success' => false,
                        'message' => __('权限被拒绝：请确保 Service Account 已在 Search Console 中被添加为站点所有者'),
                        'response' => $responseData,
                    ];
                }
                
                return [
                    'success' => false,
                    'message' => __('Google Search Console API 错误：%{1}', $errorMessage),
                    'response' => $responseData,
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
                    'body' => $responseData,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('提交异常：%{1}', $e->getMessage()),
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
        $clientEmail = $config['client_email'] ?? '';
        $privateKey = $config['private_key'] ?? '';
        $tokenUri = $config['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        
        if (empty($clientEmail) || empty($privateKey)) {
            return null;
        }
        
        // 默认使用 Indexing API scope
        if (empty($scope)) {
            $scope = self::INDEXING_API_SCOPE;
        }
        
        // 构建 JWT
        $now = time();
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];
        
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
        
        // 使用私钥签名
        $signature = '';
        $privateKeyResource = openssl_pkey_get_private($privateKey);
        if (!$privateKeyResource) {
            return null;
        }
        
        if (!openssl_sign($signatureInput, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        
        $jwt = $signatureInput . '.' . $this->base64UrlEncode($signature);
        
        // 请求 Access Token
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
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
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
            
            // 1. 获取搜索分析数据（最近 28 天）
            $searchAnalyticsResult = $this->fetchSearchAnalytics($encodedSiteUrl, $accessToken, $proxyConfig);
            if ($searchAnalyticsResult['success'] && !empty($searchAnalyticsResult['data'])) {
                $analyticsData = $searchAnalyticsResult['data'];
                $statsData['clicks'] = (int)($analyticsData['clicks'] ?? 0);
                $statsData['impressions'] = (int)($analyticsData['impressions'] ?? 0);
                $statsData['ctr'] = round(($analyticsData['ctr'] ?? 0) * 100, 2); // 转为百分比
                $statsData['average_position'] = round($analyticsData['position'] ?? 0, 2);
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
     * 获取搜索分析数据
     */
    protected function fetchSearchAnalytics(string $encodedSiteUrl, string $accessToken, array $proxyConfig): array
    {
        $url = sprintf(self::SEARCH_ANALYTICS_URL, $encodedSiteUrl);
        
        // 请求最近 28 天的汇总数据
        $startDate = date('Y-m-d', strtotime('-28 days'));
        $endDate = date('Y-m-d', strtotime('-1 day'));
        
        $requestBody = json_encode([
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => [], // 空维度表示汇总数据
            'rowLimit' => 1,
        ]);
        
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
            return ['success' => false, 'data' => [], 'error' => $error ?: "HTTP $httpCode"];
        }
        
        $data = json_decode($response, true);
        
        // 汇总数据在 rows[0] 中
        if (!empty($data['rows'][0])) {
            return ['success' => true, 'data' => $data['rows'][0]];
        }
        
        // 如果没有行数据，但响应成功，返回默认值
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
}
