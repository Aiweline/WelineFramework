<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Adapter;

use Weline\Seo\Interface\SearchEngineAdapterInterface;

class NaverSearchAdvisorAdapter implements SearchEngineAdapterInterface
{
    private const SUBMIT_URL = 'https://apis.naver.com/searchadvisor/crawl-request/submit.json';

    public function getCode(): string
    {
        return 'naver_searchadvisor';
    }

    public function getLabel(): string
    {
        return 'Naver Search Advisor';
    }

    public function pushUrls(array $urls, array $options = []): array
    {
        $config = $this->resolveConfig($options);
        $token = trim((string)($config['access_token'] ?? $config['naver_access_token'] ?? $config['api_key'] ?? ''));
        if ($token === '') {
            return [
                'success' => false,
                'message' => __('缺少 Naver Search Advisor Access Token'),
            ];
        }

        $filteredUrls = array_values(array_unique(array_filter(array_map('trim', $urls))));
        if (empty($filteredUrls)) {
            return [
                'success' => false,
                'message' => __('URL 列表为空'),
            ];
        }

        $type = strtolower(trim((string)($config['type'] ?? 'update')));
        if (!in_array($type, ['update', 'delete'], true)) {
            $type = 'update';
        }

        $successCount = 0;
        $errors = [];
        $lastResponse = [];

        foreach (array_chunk($filteredUrls, 1000) as $chunk) {
            $payloadUrls = [];
            foreach ($chunk as $url) {
                $payloadUrls[] = [
                    'url' => $url,
                    'type' => $type,
                ];
            }

            $response = $this->postJson(self::SUBMIT_URL, [
                'urls' => $payloadUrls,
            ], [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ]);
            $lastResponse = $response;

            if (!empty($response['error'])) {
                $errors[] = __('Naver Search Advisor 请求失败：%{1}', $response['error']);
                continue;
            }

            $httpCode = (int)($response['http_code'] ?? 0);
            $body = $response['body'];
            $errorCode = is_array($body) ? (int)($body['errorCode'] ?? $body['error_code'] ?? 0) : 0;
            if ($httpCode >= 200 && $httpCode < 300 && $errorCode === 0) {
                $successCount += count($chunk);
                continue;
            }

            $errors[] = __('Naver Search Advisor 返回错误：%{1}', is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : ('HTTP ' . $httpCode));
        }

        return [
            'success' => $successCount > 0 && empty($errors),
            'message' => $successCount > 0
                ? __('已通过 Naver Search Advisor 提交 %{1} 个 URL', $successCount)
                : implode('; ', $errors),
            'data' => [
                'submitted_urls' => $successCount,
                'errors' => $errors,
                'last_response' => $lastResponse,
            ],
        ];
    }

    public function submitSitemap(string $sitemapUrl, array $options = []): array
    {
        return [
            'success' => false,
            'message' => __('Naver Search Advisor 收集请求 API 面向落地页 URL，不用于 Sitemap 文件；请使用 IndexNow 或在 Naver Search Advisor 手动提交 Sitemap'),
            'data' => [
                'sitemap_url' => $sitemapUrl,
            ],
        ];
    }

    public function getRequirements(): array
    {
        return [
            'access_token' => __('Naver Search Advisor Access Token'),
            'type' => __('提交类型：update 或 delete'),
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    private function resolveConfig(array $options): array
    {
        $config = $options['config'] ?? [];
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        if (empty($config)) {
            $config = $options;
        }

        return is_array($config) ? $config : [];
    }

    private function postJson(string $url, array $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Weline SEO Naver Search Advisor Pusher/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'body' => json_decode($response ?: '', true) ?: ($response ?: ''),
            'error' => $error,
        ];
    }
}
