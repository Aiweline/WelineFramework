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
 * 百度站长平台 Sitemap 适配器
 *
 * 百度平台规则：
 * - 最大 50,000 条 URL
 * - 最大 10 MB（比 Google/Bing 更小）
 * - 支持普通收录 API
 * - 支持快速收录 API（需要配额）
 *
 * @package Weline_Seo
 */
class BaiduSitemapAdapter extends AbstractSitemapPlatformAdapter
{
    /**
     * 百度规则常量（注意：百度文件大小限制更小）
     */
    public const MAX_URLS = 50000;
    public const MAX_SIZE = 10485760; // 10 MB（百度限制）
    public const PUSH_API_URL = 'http://data.zz.baidu.com/urls';
    public const FAST_PUSH_API_URL = 'http://data.zz.baidu.com/urls'; // type=daily 在拼接时追加

    public function getPlatformCode(): string
    {
        return 'baidu';
    }

    public function getPlatformName(): string
    {
        return '百度';
    }

    public function getPlatformColor(): string
    {
        return '#2932E1';
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
        return false;
    }

    /**
     * 提交 sitemap 到百度
     *
     * 百度不支持直接提交 sitemap URL，需要使用链接提交 API
     * 这里我们提交 sitemap 索引 URL 作为一条链接
     */
    public function submitSitemap(string $sitemapUrl, array $accountConfig): array
    {
        return (new \Weline\Seo\Service\Adapter\BaiduSearchEngineAdapter())->submitSitemap($sitemapUrl, $accountConfig);
    }

    /**
     * 批量提交 URL 到百度（用于 URL 级别提交）
     *
     * @param array $urls URL 列表
     * @param string $site 站点
     * @param string $token Token
     * @param bool $useFastPush 是否使用快速收录
     * @return array
     */
    public function submitUrls(array $urls, string $site, string $token, bool $useFastPush = false): array
    {
        return (new \Weline\Seo\Service\Adapter\BaiduSearchEngineAdapter())->pushUrls($urls, ['config' => ['site' => $site, 'token' => $token, 'use_fast_push' => $useFastPush]]);
    }
}
