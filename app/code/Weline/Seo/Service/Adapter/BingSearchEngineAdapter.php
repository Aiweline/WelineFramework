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
    private const SUBMIT_SITEMAP_API = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitFeed';

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
        if (!empty($config['use_indexnow'])) {
            $config['indexnow_endpoint'] = self::INDEX_NOW_URL;
            return (new IndexNowSearchEngineAdapter())->pushUrls($urls, ['config' => $config]);
        }
        if (strtolower((string)($options['action'] ?? '')) === 'delete') {
            return SubmissionResult::failure(__('Bing Webmaster URL API 不提供删除接口，请配置 IndexNow 通知删除'), 'unsupported');
        }
        $apiKey = trim((string)($config['api_key'] ?? ''));
        $siteUrl = trim((string)($config['site_url'] ?? ''));
        if ($apiKey === '' || !SubmissionResult::validUrl($siteUrl)) {
            return SubmissionResult::failure(__('请配置 Bing Webmaster API Key 和已验证站点 URL'), 'not_configured');
        }
        $urls = SubmissionResult::urls($urls);
        foreach ($urls as $url) {
            if (!SubmissionResult::belongsToSite($url, $siteUrl)) { return SubmissionResult::failure(__('Bing URL 必须属于已配置站点')); }
        }
        $submitted = $rejected = $errors = [];
        foreach (array_chunk($urls, 500) as $chunk) {
            $response = $this->requestApi(self::SUBMIT_URL_BATCH_API, $apiKey, ['siteUrl' => $siteUrl, 'urlList' => $chunk]);
            if ($response['success']) { array_push($submitted, ...$chunk); }
            else { array_push($rejected, ...$chunk); $errors[] = $response['message']; }
        }
        return SubmissionResult::complete(count($urls), $submitted, [], $rejected, $errors);
    }

    public function submitSitemap(string $sitemapUrl, array $options = []): array
    {
        $config = $this->resolveConfig($options);
        $apiKey = trim((string)($config['api_key'] ?? ''));
        $siteUrl = trim((string)($config['site_url'] ?? ''));
        if ($apiKey === '' || !SubmissionResult::validUrl($siteUrl)) {
            return SubmissionResult::failure(__('请配置 Bing Webmaster API Key 和已验证站点 URL'), 'not_configured');
        }
        if (!SubmissionResult::belongsToSite($sitemapUrl, $siteUrl)) { return SubmissionResult::failure(__('Sitemap 必须属于已配置站点')); }
        return $this->requestApi(self::SUBMIT_SITEMAP_API, $apiKey, ['siteUrl' => $siteUrl, 'feedUrl' => $sitemapUrl]);
    }

    private function requestApi(string $endpoint, string $apiKey, array $payload, bool $readOnly = false): array
    {
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $endpoint . '?apikey=' . rawurlencode($apiKey),
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        ];
        if ($readOnly) {
            unset($curlOptions[CURLOPT_POST], $curlOptions[CURLOPT_POSTFIELDS]);
            $curlOptions[CURLOPT_URL] .= '&' . http_build_query($payload);
        }
        curl_setopt_array($ch, $curlOptions);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $data = json_decode((string)$body, true);
        $hasError = is_array($data) && (isset($data['ErrorCode']) || isset($data['error']) || isset($data['Error'])
            || (is_array($data['d'] ?? null) && (isset($data['d']['ErrorCode']) || isset($data['d']['error']))));
        $success = $error === '' && $httpCode >= 200 && $httpCode < 300 && is_array($data) && array_key_exists('d', $data) && !$hasError;
        return [
            'success' => $success, 'accepted' => $success, 'status' => $success ? 'accepted' : 'failed',
            'message' => $success ? __('Bing 已接收提交') : __('Bing 请求失败（HTTP %{1}），请检查账户权限与配额', $httpCode),
            'data' => ['http_code' => $httpCode],
        ];
    }

    public function verifyAccount(array $config): array
    {
        $result = $this->requestApi('https://ssl.bing.com/webmaster/api.svc/json/GetUrlSubmissionQuota', (string)($config['api_key'] ?? ''), ['siteUrl' => (string)($config['site_url'] ?? '')], true);
        $result['remote_verified'] = $result['success'];
        $result['message'] = $result['success'] ? __('Bing 账户与站点权限验证通过') : $result['message'];
        return $result;
    }

    public function getRequirements(): array
    {
        return [
            'api_key' => 'Bing Webmaster API Key',
            'site_url' => __('已在 Bing Webmaster Tools 验证的站点 URL'),
        ];
    }

    public function getAccountConfigFields(): array
    {
        return [
            [
                'key' => 'api_key',
                'label' => (string)__('Bing Webmaster API Key'),
                'type' => 'password',
                'required' => false,
                'placeholder' => 'your-bing-webmaster-api-key',
                'hint' => (string)__('在 Bing Webmaster Tools → Settings → API Access 生成'),
            ],
            [
                'key' => 'site_url',
                'label' => (string)__('已验证站点 URL'),
                'type' => 'website_url',
                'required' => true,
                'placeholder' => 'https://www.example.com',
                'hint' => (string)__('从网站列表选择；须与 Bing Webmaster Tools 已验证站点一致'),
            ],
            ['key' => 'use_indexnow', 'label' => (string)__('使用 IndexNow 推送页面 URL'), 'type' => 'checkbox', 'required' => false],
            ['key' => 'indexnow_key', 'label' => 'IndexNow Key', 'type' => 'password', 'required' => false],
            ['key' => 'key_location', 'label' => (string)__('Key 文件公开地址'), 'type' => 'url', 'required' => false],
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }

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
