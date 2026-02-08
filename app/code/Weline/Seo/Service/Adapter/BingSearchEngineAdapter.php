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
 * Bing SearchEngine 适配器
 *
 * 支持两种提交方式：
 * 1. Bing Webmaster API（批量提交 URL）
 * 2. IndexNow 协议（即时通知 URL 变更）
 *
 * 配置字段说明：
 * - api_key: Bing Webmaster API Key
 * - site_url: 已验证的站点 URL
 * - indexnow_key: IndexNow Key（可选，默认使用 api_key）
 * - use_indexnow: 是否使用 IndexNow 协议（默认 false）
 *
 * @package Weline_Seo
 */
class BingSearchEngineAdapter implements SearchEngineAdapterInterface
{
    private const SUBMIT_URL_BATCH_API = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch';
    private const INDEX_NOW_URL = 'https://www.bing.com/indexnow';
    private const SUBMIT_SITEMAP_API = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitSitemap';

    public function getCode(): string
    {
        return 'bing_webmaster';
    }

    public function getLabel(): string
    {
        return 'Bing Webmaster Tools';
    }

    public function pushUrls(array $urls, array $options = []): array
    {
        $config = $this->resolveConfig($options);
        $apiKey = $config['api_key'] ?? '';
        $siteUrl = $config['site_url'] ?? '';
        $useIndexNow = !empty($config['use_indexnow']);

        if (empty($apiKey)) {
            return [
                'success' => false,
                'message' => __('缺少 Bing Webmaster API Key'),
            ];
        }

        // 使用 IndexNow 协议
        if ($useIndexNow) {
            return $this->pushViaIndexNow($urls, $config);
        }

        // 使用 Webmaster API 批量提交
        return $this->pushViaApi($urls, $apiKey, $siteUrl);
    }

    public function submitSitemap(string $sitemapUrl, array $options = []): array
    {
        $config = $this->resolveConfig($options);
        $apiKey = $config['api_key'] ?? '';
        $siteUrl = $config['site_url'] ?? '';

        if (empty($apiKey)) {
            return [
                'success' => false,
                'message' => __('缺少 Bing Webmaster API Key'),
            ];
        }

        if (empty($siteUrl)) {
            $parsed = parse_url($sitemapUrl);
            $siteUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        }

        $apiEndpoint = sprintf(
            '%s?apikey=%s&siteUrl=%s&feedPath=%s',
            self::SUBMIT_SITEMAP_API,
            urlencode($apiKey),
            urlencode($siteUrl),
            urlencode($sitemapUrl)
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiEndpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERAGENT => 'Weline SEO Sitemap Submitter/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'message' => __('Bing API 请求失败：%{1}', $error),
            ];
        }

        $success = $httpCode >= 200 && $httpCode < 300;

        return [
            'success' => $success,
            'message' => $success
                ? __('已成功提交 sitemap 到 Bing Webmaster')
                : __('Bing API 返回错误码：%{1}', $httpCode),
            'data' => ['http_code' => $httpCode, 'body' => $response],
        ];
    }

    public function getRequirements(): array
    {
        return [
            'api_key' => 'Bing Webmaster API Key',
            'site_url' => __('已在 Bing Webmaster Tools 验证的站点 URL'),
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * 通过 Webmaster API 批量提交 URL
     */
    private function pushViaApi(array $urls, string $apiKey, string $siteUrl): array
    {
        if (empty($siteUrl) && !empty($urls[0])) {
            $parsed = parse_url((string)$urls[0]);
            $siteUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        }

        $apiEndpoint = self::SUBMIT_URL_BATCH_API . '?apikey=' . urlencode($apiKey);

        $body = json_encode([
            'siteUrl' => $siteUrl,
            'urlList' => array_values(array_filter(array_map('trim', $urls))),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiEndpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_USERAGENT => 'Weline SEO URL Pusher/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'message' => __('Bing API 请求失败：%{1}', $error),
            ];
        }

        $success = $httpCode >= 200 && $httpCode < 300;

        return [
            'success' => $success,
            'message' => $success
                ? __('已成功向 Bing 提交 %{1} 个 URL', count($urls))
                : __('Bing API 返回错误码：%{1}', $httpCode),
            'data' => ['http_code' => $httpCode, 'body' => $response],
        ];
    }

    /**
     * 通过 IndexNow 协议提交 URL
     */
    private function pushViaIndexNow(array $urls, array $config): array
    {
        $key = $config['indexnow_key'] ?? $config['api_key'] ?? '';
        
        if (empty($key)) {
            return [
                'success' => false,
                'message' => __('缺少 IndexNow Key'),
            ];
        }

        // IndexNow 支持批量提交
        $filteredUrls = array_values(array_filter(array_map('trim', $urls)));
        if (empty($filteredUrls)) {
            return [
                'success' => false,
                'message' => __('URL 列表为空'),
            ];
        }

        $parsed = parse_url($filteredUrls[0]);
        $host = $parsed['host'] ?? '';

        $body = json_encode([
            'host' => $host,
            'key' => $key,
            'urlList' => $filteredUrls,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::INDEX_NOW_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_USERAGENT => 'Weline SEO URL Pusher/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'message' => __('IndexNow 请求失败：%{1}', $error),
            ];
        }

        $success = $httpCode >= 200 && $httpCode < 300;

        return [
            'success' => $success,
            'message' => $success
                ? __('已通过 IndexNow 提交 %{1} 个 URL', count($filteredUrls))
                : __('IndexNow 返回错误码：%{1}', $httpCode),
            'data' => ['http_code' => $httpCode, 'body' => $response],
        ];
    }

    /**
     * 从 options 中解析配置（兼容嵌套和平铺两种格式）
     */
    private function resolveConfig(array $options): array
    {
        // 优先使用嵌套的 config 键
        $config = $options['config'] ?? [];
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        // 如果嵌套 config 为空，回退到 options 本身（平铺格式）
        if (empty($config)) {
            $config = $options;
        }

        return $config;
    }
}
