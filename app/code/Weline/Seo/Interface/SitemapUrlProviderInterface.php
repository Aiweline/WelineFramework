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
 * Sitemap URL 提供者接口
 *
 * 定义 URL 提供者的标准接口，所有 Sitemap Provider 必须实现此接口。
 * Provider 只负责提供 URL 数据，不负责生成 sitemap 文件。
 *
 * @package Weline_Seo
 */
interface SitemapUrlProviderInterface
{
    /**
     * 返回 Provider 的 scope 标识
     *
     * 用于区分不同类型的 URL 来源，如：
     * - catalog: 商品目录
     * - blog: 博客文章
     *
     * @return string
     */
    public function getScope(): string;

    /**
     * 返回 Provider 所属模块名
     *
     *
     * @return string
     */
    public function getModule(): string;

    /**
     * 返回此 Provider 管理的所有站点 ID
     *
     * @return int[]
     */
    public function getWebsiteIds(): array;

    /**
     * 获取指定站点的 URL 数据
     *
     * 返回格式：
     * [
     *     [
     *         'url_key' => 'unique_key',      // 必需：唯一标识符
     *         'loc' => 'https://...',         // 必需：完整URL
     *         'lastmod' => '2026-01-30',      // 可选：最后修改日期 (Y-m-d 格式)
     *         'changefreq' => 'daily',        // 可选：更新频率
     *         'priority' => '0.8',            // 可选：优先级 (0.0-1.0)
     *     ],
     *     // ...
     * ]
     *
     * 注意：
     * - url_key 在同一站点+scope+module下必须唯一
     * - 继承 AbstractSitemapUrlProvider 可自动同步到数据库
     *
     * @param int $websiteId 站点ID
     * @return array URL 数据列表
     */
    public function getUrlsForWebsite(int $websiteId): array;

    /**
     * 返回 Provider 的描述信息
     *
     * 用于后台显示和日志记录
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * 判断 Provider 是否启用
     *
     * @return bool
     */
    public function isEnabled(): bool;
}
