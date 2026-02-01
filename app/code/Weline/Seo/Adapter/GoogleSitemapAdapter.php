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
 * Google Indexing API Sitemap 适配器
 *
 * Google 平台规则：
 * - 最大 50,000 条 URL
 * - 最大 50 MB（未压缩）
 * - 使用 Indexing API 提交 URL（2023年6月后 Ping 方式已弃用）
 * - 支持 Service Account 认证
 *
 * 注意：Indexing API 每天有配额限制（默认 200 次/天）
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
     * 支持两种方式：
     * 1. Ping 方式（无需 API 密钥）
     * 2. Search Console API（需要 OAuth2 配置）
     *
     * 账户配置支持代理设置：
     * - proxy: 代理地址，如 http://127.0.0.1:7890
     * - proxy_type: 代理类型，http 或 socks5（默认 http）
     */
    public function submitSitemap(string $sitemapUrl, array $accountConfig): array
    {
        // 从配置中提取代理设置
        $proxyConfig = $this->extractProxyConfig($accountConfig);
        
        $config = $accountConfig['config'] ?? $accountConfig;
        
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
     * 通过 Google Indexing API 提交
     *
     * 使用 Service Account 进行 JWT 认证，调用 Indexing API 通知 Google 更新 URL
     * 注意：Indexing API 每天有配额限制（默认 200 次/天）
     */
    protected function submitViaApi(string $sitemapUrl, array $accountConfig, array $proxyConfig = []): array
    {
        $config = $accountConfig['config'] ?? $accountConfig;
        
        // 验证必要的配置
        if (empty($config['client_email']) || empty($config['private_key'])) {
            return [
                'success' => false,
                'message' => __('缺少 Service Account 配置（client_email 或 private_key）'),
                'response' => null,
            ];
        }
        
        try {
            // 1. 获取 Access Token（使用 Indexing API scope）
            $accessToken = $this->getAccessToken($config, $proxyConfig, self::INDEXING_API_SCOPE);
            if (empty($accessToken)) {
                return [
                    'success' => false,
                    'message' => __('获取 Google Access Token 失败'),
                    'response' => null,
                ];
            }
            
            // 2. 调用 Indexing API 通知 URL 更新
            $ch = curl_init();
            $curlOptions = [
                CURLOPT_URL => self::INDEXING_API_URL,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'url' => $sitemapUrl,
                    'type' => 'URL_UPDATED',
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
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
                    'message' => __('Indexing API 请求失败：%{1}', $error),
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
                    'message' => __('Google Indexing API 错误：%{1}', $errorMessage),
                    'response' => $responseData,
                ];
            }
            
            return [
                'success' => $success,
                'message' => $success 
                    ? __('已成功通过 Indexing API 通知 Google 更新 URL') 
                    : __('Indexing API 返回错误码：%{1}', $httpCode),
                'response' => [
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
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
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
}
