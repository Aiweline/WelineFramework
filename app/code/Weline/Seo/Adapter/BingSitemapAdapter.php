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
 * Bing Webmaster Tools Sitemap 适配器
 *
 * Bing 平台规则：
 * - 最大 50,000 条 URL
 * - 最大 50 MB（未压缩）
 * - 支持 Webmaster API 提交
 * - 支持 IndexNow 协议
 *
 * @package Weline_Seo
 */
class BingSitemapAdapter extends AbstractSitemapPlatformAdapter
{
    /**
     * Bing 规则常量
     */
    public const MAX_URLS = 50000;
    public const MAX_SIZE = 52428800; // 50 MB
    public const API_URL = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch';
    public const INDEX_NOW_URL = 'https://www.bing.com/indexnow';

    public function getPlatformCode(): string
    {
        return 'bing';
    }

    public function getPlatformName(): string
    {
        return 'Bing';
    }

    public function getPlatformColor(): string
    {
        return '#00809D';
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
     * 提交 sitemap 到 Bing
     *
     * 支持两种方式：
     * 1. Webmaster API（需要 API Key）
     * 2. IndexNow 协议（需要 Key）
     */
    public function submitSitemap(string $sitemapUrl, array $accountConfig): array
    {
        // 兼容嵌套和平铺两种 config 格式
        $config = $accountConfig['config'] ?? $accountConfig;
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : $accountConfig;
        }
        
        $apiKey = $config['api_key'] ?? '';
        
        if (empty($apiKey)) {
            return [
                'success' => false,
                'message' => __('缺少 Bing Webmaster API Key'),
                'response' => null,
            ];
        }

        // 使用 IndexNow 协议
        if (!empty($config['use_indexnow'])) {
            return $this->submitViaIndexNow($sitemapUrl, $config);
        }
        
        // 使用 Webmaster API
        return $this->submitViaApi($sitemapUrl, $config);
    }

    /**
     * 通过 Webmaster API 提交
     */
    protected function submitViaApi(string $sitemapUrl, array $accountConfig): array
    {
        $apiKey = $accountConfig['api_key'] ?? '';
        $siteUrl = $accountConfig['site_url'] ?? '';
        
        if (empty($siteUrl)) {
            // 从 sitemap URL 提取站点 URL
            $parsed = parse_url($sitemapUrl);
            $siteUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        }
        
        $apiEndpoint = sprintf(
            'https://ssl.bing.com/webmaster/api.svc/json/SubmitSitemap?apikey=%s&siteUrl=%s&feedPath=%s',
            urlencode($apiKey),
            urlencode($siteUrl),
            urlencode($sitemapUrl)
        );
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiEndpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_USERAGENT => 'Weline SEO Sitemap Submitter/1.0',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => __('API 请求失败：%{1}', $error),
                'response' => null,
            ];
        }
        
        $success = $httpCode >= 200 && $httpCode < 300;
        
        return [
            'success' => $success,
            'message' => $success 
                ? __('已成功提交 sitemap 到 Bing Webmaster') 
                : __('API 请求返回错误码：%{1}', $httpCode),
            'response' => [
                'http_code' => $httpCode,
                'body' => $response,
            ],
        ];
    }

    /**
     * 通过 IndexNow 协议提交
     */
    protected function submitViaIndexNow(string $sitemapUrl, array $accountConfig): array
    {
        $key = $accountConfig['indexnow_key'] ?? $accountConfig['api_key'] ?? '';
        
        $parsed = parse_url($sitemapUrl);
        $host = $parsed['host'] ?? '';
        
        $indexNowUrl = sprintf(
            '%s?url=%s&key=%s',
            self::INDEX_NOW_URL,
            urlencode($sitemapUrl),
            urlencode($key)
        );
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $indexNowUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Weline SEO Sitemap Submitter/1.0',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => __('IndexNow 请求失败：%{1}', $error),
                'response' => null,
            ];
        }
        
        $success = $httpCode >= 200 && $httpCode < 300;
        
        return [
            'success' => $success,
            'message' => $success 
                ? __('已通过 IndexNow 通知 Bing 更新') 
                : __('IndexNow 请求返回错误码：%{1}', $httpCode),
            'response' => [
                'http_code' => $httpCode,
                'body' => $response,
            ],
        ];
    }
}
