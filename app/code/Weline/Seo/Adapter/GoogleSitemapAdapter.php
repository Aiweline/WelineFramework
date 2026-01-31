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
 * - 支持 Ping 方式提交
 * - 支持 Search Console API 提交
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
    public const PING_URL = 'https://www.google.com/ping?sitemap=';

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
     */
    public function submitSitemap(string $sitemapUrl, array $accountConfig): array
    {
        // 优先使用 API 方式
        if (!empty($accountConfig['api_key']) || !empty($accountConfig['service_account'])) {
            return $this->submitViaApi($sitemapUrl, $accountConfig);
        }
        
        // 回退到 Ping 方式
        return $this->submitViaPing($sitemapUrl);
    }

    /**
     * 通过 Ping 方式提交
     */
    protected function submitViaPing(string $sitemapUrl): array
    {
        $pingUrl = self::PING_URL . urlencode($sitemapUrl);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $pingUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Weline SEO Sitemap Submitter/1.0',
        ]);
        
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
     * 通过 Search Console API 提交
     */
    protected function submitViaApi(string $sitemapUrl, array $accountConfig): array
    {
        // TODO: 实现 Google Search Console API 提交
        // 需要 OAuth2 认证和服务账户配置
        
        return [
            'success' => false,
            'message' => __('Google Search Console API 提交尚未实现，请使用 Ping 方式'),
            'response' => null,
        ];
    }
}
