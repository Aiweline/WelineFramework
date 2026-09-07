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
        return (new \Weline\Seo\Service\Adapter\BingSearchEngineAdapter())->submitSitemap($sitemapUrl, $accountConfig);
    }

}
