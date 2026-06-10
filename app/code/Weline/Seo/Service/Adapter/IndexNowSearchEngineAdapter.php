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

class IndexNowSearchEngineAdapter implements SearchEngineAdapterInterface
{
    private const INDEX_NOW_URL = 'https://api.indexnow.org/indexnow';

    public function getCode(): string
    {
        return 'indexnow';
    }

    public function getLabel(): string
    {
        return 'IndexNow';
    }

    public function pushUrls(array $urls, array $options = []): array
    {
        $config = $this->resolveConfig($options);
        $key = trim((string)($config['indexnow_key'] ?? $config['api_key'] ?? $config['key'] ?? ''));

        if ($key === '') {
            return [
                'success' => false,
                'message' => __('缺少 IndexNow Key'),
            ];
        }

        $filteredUrls = array_values(array_filter(array_map('trim', $urls)));
        if (empty($filteredUrls)) {
            return [
                'success' => false,
                'message' => __('URL 列表为空'),
            ];
        }

        $endpoint = trim((string)($config['indexnow_endpoint'] ?? self::INDEX_NOW_URL));
        if ($endpoint === '') {
            $endpoint = self::INDEX_NOW_URL;
        }

        $successCount = 0;
        $lastResponse = [];
        $errors = [];

        foreach (array_chunk($filteredUrls, 10000) as $chunk) {
            $parsed = parse_url($chunk[0]);
            $host = (string)($parsed['host'] ?? '');
            if ($host === '') {
                $errors[] = __('URL 缺少主机名：%{1}', $chunk[0]);
                continue;
            }

            $payload = [
                'host' => $host,
                'key' => $key,
                'urlList' => $chunk,
            ];

            $keyLocation = trim((string)($config['key_location'] ?? $config['keyLocation'] ?? ''));
            if ($keyLocation !== '') {
                $payload['keyLocation'] = $keyLocation;
            }

            $response = $this->httpRequest(
                $endpoint,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $lastResponse = $response;

            if (!empty($response['error'])) {
                $errors[] = __('IndexNow 请求失败：%{1}', $response['error']);
                continue;
            }

            $httpCode = (int)($response['http_code'] ?? 0);
            if ($httpCode >= 200 && $httpCode < 300) {
                $successCount += count($chunk);
                continue;
            }

            $errors[] = __('IndexNow 返回错误码：%{1}', $httpCode);
        }

        return [
            'success' => $successCount > 0,
            'message' => $successCount > 0
                ? __('已通过 IndexNow 提交 %{1} 个 URL', $successCount)
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
        return $this->pushUrls([$sitemapUrl], $options);
    }

    public function getRequirements(): array
    {
        return [
            'indexnow_key' => __('IndexNow Key'),
            'key_location' => __('可公开访问的 IndexNow Key 文件地址'),
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

    private function httpRequest(string $endpoint, string $body): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_USERAGENT => 'Weline SEO IndexNow Pusher/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'body' => $response ?: '',
            'error' => $error,
        ];
    }
}
