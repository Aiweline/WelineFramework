<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Adapter;

abstract class IndexNowSitemapAdapter extends CatalogSitemapAdapter
{
    protected const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

    public function supportsAutoSubmit(): bool
    {
        return true;
    }

    public function submitSitemap(string $sitemapUrl, array $accountConfig): array
    {
        $config = $this->resolveConfig($accountConfig);
        $key = trim((string)($config['indexnow_key'] ?? $config['api_key'] ?? $config['key'] ?? ''));

        if ($key === '') {
            return [
                'success' => false,
                'message' => __('缺少 IndexNow Key'),
                'response' => null,
            ];
        }

        $parsed = parse_url($sitemapUrl);
        $host = (string)($parsed['host'] ?? '');
        if ($host === '') {
            return [
                'success' => false,
                'message' => __('Sitemap URL 缺少主机名'),
                'response' => null,
            ];
        }

        $endpoint = trim((string)($config['indexnow_endpoint'] ?? $this->getDefaultIndexNowEndpoint()));
        if ($endpoint === '') {
            $endpoint = $this->getDefaultIndexNowEndpoint();
        }

        $payload = [
            'host' => $host,
            'key' => $key,
            'urlList' => [$sitemapUrl],
        ];

        $keyLocation = trim((string)($config['key_location'] ?? $config['keyLocation'] ?? ''));
        if ($keyLocation !== '') {
            $payload['keyLocation'] = $keyLocation;
        }

        $response = $this->httpRequest(
            $endpoint,
            'POST',
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if (!empty($response['error'])) {
            return [
                'success' => false,
                'message' => __('IndexNow 请求失败：%{1}', $response['error']),
                'response' => $response,
            ];
        }

        $httpCode = (int)($response['status_code'] ?? 0);
        $success = $httpCode >= 200 && $httpCode < 300;

        return [
            'success' => $success,
            'message' => $success
                ? __('已通过 IndexNow 通知 %{1}', $this->getPlatformName())
                : __('IndexNow 返回错误码：%{1}', $httpCode),
            'response' => [
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'body' => $response['body'] ?? '',
            ],
        ];
    }

    private function resolveConfig(array $accountConfig): array
    {
        $config = $accountConfig['config'] ?? $accountConfig;
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        return is_array($config) ? $config : [];
    }

    protected function getDefaultIndexNowEndpoint(): string
    {
        return static::INDEXNOW_ENDPOINT;
    }
}
