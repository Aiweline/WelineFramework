<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Interface;

/**
 * Sitemap 平台适配器接口
 *
 * 每个搜索引擎平台实现此接口，负责：
 * 1. 定义平台规则（URL 限制、文件大小限制等）
 * 2. 生成符合平台规范的 sitemap 文件
 * 3. 提交 sitemap 到平台（可选）
 *
 * 遵循 SOLID 原则：
 * - 单一职责：每个适配器只负责自己平台的逻辑
 * - 开闭原则：添加新平台只需添加新适配器
 * - 依赖倒置：核心服务依赖此接口，而非具体实现
 *
 * @package Weline_Seo
 */
interface SitemapPlatformAdapterInterface
{
    /**
     * 获取平台代码（唯一标识）
     *
     * @return string 如 'google', 'bing', 'baidu'
     */
    public function getPlatformCode(): string;

    /**
     * 获取平台显示名称
     *
     * @return string 如 'Google', 'Bing', '百度'
     */
    public function getPlatformName(): string;

    /**
     * 获取平台颜色（用于 UI 展示）
     *
     * @return string 如 '#4285F4'
     */
    public function getPlatformColor(): string;

    /**
     * 获取单个 sitemap 文件的最大 URL 数量
     *
     * @return int
     */
    public function getMaxUrlsPerFile(): int;

    /**
     * 获取单个 sitemap 文件的最大大小（字节）
     *
     * @return int
     */
    public function getMaxFileSizeBytes(): int;

    /**
     * 为站点生成 sitemap 文件
     *
     * @param int $websiteId 站点 ID
     * @param string $websiteCode 站点代码
     * @param string $baseUrl 站点基础 URL
     * @param array $groupedUrls 按模块分组的 URL 数据 ['module_name' => [urls...], ...]
     * @return array 生成结果 [
     *     'index' => ['filename' => ..., 'url' => ..., 'path' => ...],
     *     'modules' => [
     *         'module_name' => [
     *             'files' => [['filename' => ..., 'url' => ..., 'count' => ...], ...],
     *             'url_count' => int,
     *         ],
     *         ...
     *     ],
     *     'total_urls' => int,
     *     'total_files' => int,
     * ]
     */
    public function generateSitemapFiles(
        int $websiteId,
        string $websiteCode,
        string $baseUrl,
        array $groupedUrls
    ): array;

    /**
     * 提交 sitemap 到平台
     *
     * @param string $sitemapUrl sitemap 索引文件的完整 URL
     * @param array $accountConfig SEO 账户配置
     * @return array 提交结果 ['success' => bool, 'message' => string, 'response' => mixed]
     */
    public function submitSitemap(string $sitemapUrl, array $accountConfig): array;

    /**
     * 检查适配器是否支持自动提交
     *
     * @return bool
     */
    public function supportsAutoSubmit(): bool;

    /**
     * 获取平台的 sitemap 目录路径
     *
     * @param string $siteDir 站点目录
     * @return string
     */
    public function getPlatformDir(string $siteDir): string;

    /**
     * 获取平台总索引文件的 URL
     *
     * @param string $baseUrl 站点基础 URL
     * @param string $websiteCode 站点代码
     * @return string
     */
    public function getIndexUrl(string $baseUrl, string $websiteCode): string;

    /**
     * 检查适配器是否支持获取统计数据
     *
     * @return bool
     */
    public function supportsStats(): bool;

    /**
     * 获取站点在该平台的统计数据
     *
     * 从平台 API 获取索引量、点击量、展示量等数据
     *
     * @param string $siteUrl 站点 URL
     * @param array $accountConfig SEO 账户配置
     * @return array 统计数据 [
     *     'success' => bool,
     *     'message' => string,
     *     'data' => [
     *         'indexed_pages' => int,      // 已索引页面数
     *         'submitted_urls' => int,     // 已提交 URL 数
     *         'crawled_pages' => int,      // 已抓取页面数
     *         'clicks' => int,             // 点击量
     *         'impressions' => int,        // 展示量
     *         'ctr' => float,              // 点击率
     *         'average_position' => float, // 平均排名
     *         'error_count' => int,        // 错误数
     *         'warning_count' => int,      // 警告数
     *         'daily_quota' => int,        // 每日配额
     *         'quota_used' => int,         // 已使用配额
     *         'extra' => array,            // 额外数据
     *     ],
     * ]
     */
    public function getStats(string $siteUrl, array $accountConfig): array;
}
