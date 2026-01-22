<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service\Adapter;

use Weline\Seo\Interface\SearchEngineAdapterInterface;

/**
 * Google Indexing API 适配器
 *
 * 文档参考：
 * - https://indexing.googleapis.com/v3/urlNotifications:publish
 * - https://developers.google.com/search/apis/indexing-api/v3/quickstart
 */
class GoogleIndexingApiAdapter implements SearchEngineAdapterInterface
{
    private const TOKEN_URL = 'https://www.googleapis.com/oauth2/v4/token';
    private const INDEXING_URL = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

    public function getCode(): string
    {
        return 'google_indexing_api';
    }

    public function getLabel(): string
    {
        return 'Google Indexing API';
    }

    public function pushUrls(array $urls, array $options = []): array
    {
        $results = [];
        $successCount = 0;
        $errors = [];

        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }

            $result = $this->notifyUrl($url, $options);
            $results[] = $result;

            if ($result['success'] ?? false) {
                $successCount++;
            } else {
                $errors[] = $result['message'] ?? ('提交失败: ' . $url);
            }
        }

        return [
            'success' => $successCount > 0 && count($errors) === 0,
            'message' => $successCount > 0
                ? __('已向Google Indexing API 提交 %{success} 个URL', ['success' => $successCount])
                : __('向Google Indexing API 提交URL失败'),
            'data' => $results,
            'errors' => $errors,
        ];
    }

    public function submitSitemap(string $sitemapUrl, array $options = []): array
    {
        $sitemapUrl = trim($sitemapUrl);
        if ($sitemapUrl === '') {
            return [
                'success' => false,
                'message' => __('Sitemap URL为空'),
            ];
        }

        // 对于 Google Indexing API，可以直接把 sitemap 当作普通 URL 通知
        return $this->notifyUrl($sitemapUrl, $options);
    }

    public function getRequirements(): array
    {
        return [
            'service_account_json' => 'Google Service Account JSON 凭据内容',
            'scope' => 'https://www.googleapis.com/auth/indexing',
        ];
    }

    public function isConfigured(): bool
    {
        // 适配器本身为无状态，实际配置在账户层面验证
        return true;
    }

    /**
     * 向 Google Indexing API 发送单个 URL 通知
     */
    private function notifyUrl(string $url, array $options): array
    {
        $config = $options['config'] ?? [];
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            if (is_array($decoded)) {
                $config = $decoded;
            } else {
                $config = [];
            }
        }

        $serviceAccount = $config['service_account'] ?? null;
        if (is_string($serviceAccount)) {
            $decodedService = json_decode($serviceAccount, true);
            if (is_array($decodedService)) {
                $serviceAccount = $decodedService;
            } else {
                $serviceAccount = null;
            }
        }

        if (!is_array($serviceAccount)) {
            return [
                'success' => false,
                'message' => __('Google Service Account 配置缺失或格式错误'),
            ];
        }

        $accessToken = $this->createAccessToken($serviceAccount);
        if ($accessToken === '') {
            return [
                'success' => false,
                'message' => __('获取 Google Indexing API 访问令牌失败'),
            ];
        }

        $body = [
            'url' => $url,
            'type' => 'URL_UPDATED',
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ];

        $response = $this->postJson(self::INDEXING_URL, $body, $headers);

        if (!($response['success'] ?? false)) {
            return $response;
        }

        return [
            'success' => true,
            'message' => __('URL 已提交给 Google Indexing API'),
            'data' => $response['data'] ?? null,
        ];
    }

    /**
     * 使用 Service Account 生成访问令牌
     */
    private function createAccessToken(array $serviceAccount): string
    {
        $clientEmail = $serviceAccount['client_email'] ?? '';
        $privateKey = $serviceAccount['private_key'] ?? '';

        if ($clientEmail === '' || $privateKey === '') {
            return '';
        }

        $now = time();
        $jwtHeader = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $jwtClaimSet = [
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $base64Header = rtrim(strtr(base64_encode(json_encode($jwtHeader)), '+/', '-_'), '=');
        $base64Claim = rtrim(strtr(base64_encode(json_encode($jwtClaimSet)), '+/', '-_'), '=');
        $unsignedJwt = $base64Header . '.' . $base64Claim;

        $signature = '';
        $ok = openssl_sign($unsignedJwt, $signature, $privateKey, 'SHA256');
        if (!$ok) {
            return '';
        }

        $base64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $jwt = $unsignedJwt . '.' . $base64Signature;

        $postFields = json_encode([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type: application/json',
        ];

        $response = $this->postJson(self::TOKEN_URL, $postFields, $headers, false);
        if (!($response['success'] ?? false)) {
            return '';
        }

        $data = $response['data'] ?? [];
        $token = is_array($data) ? ($data['access_token'] ?? '') : '';
        return (string)$token;
    }

    /**
     * 发送 JSON POST 请求
     *
     * @param string $url
     * @param array|string $body
     * @param array $headers
     * @param bool $decodeJson
     * @return array
     */
    private function postJson(string $url, $body, array $headers, bool $decodeJson = true): array
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'message' => __('服务器未启用 cURL 扩展，无法调用 Google Indexing API'),
            ];
        }

        $ch = curl_init($url);

        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'message' => __('请求失败: %{1}', [$error]),
            ];
        }

        $data = $decodeJson ? json_decode($response ?? '', true) : $response;

        if ($httpCode >= 400) {
            return [
                'success' => false,
                'message' => __('HTTP错误: %{1}', [$httpCode]),
                'data' => $data,
            ];
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }
}

