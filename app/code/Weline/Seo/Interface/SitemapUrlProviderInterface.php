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
 * Provider 是 sitemap URL 资产的权威来源：
 * - cron 会通过 SitemapRegistryService 自动发现 Provider 并调用 getUrlsForWebsite()
 * - 保存/发布事件会通过 SEO 服务定向同步相关站点的 Provider
 * - URL push 任务只消费 Provider/业务事件产出的站点 URL，不改变 Provider 发现能力
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
     * - 同一个 url_key 可以出现在多个站点
     * - 业务实体属于多个站点时，Provider 必须为每个站点分别提供 URL
     * - cron 会自动发现此 Provider 并立即拉取 URL，不能依赖后台手动写入
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
