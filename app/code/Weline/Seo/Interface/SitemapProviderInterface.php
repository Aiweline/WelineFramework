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
 * Sitemap 衍生数据提供接口
 *
 * 由 Website / 站点模块实现，用于生成 Sitemap 文件并返回其 URL。
 */
interface SitemapProviderInterface
{
    /**
     * 返回该 Sitemap 提供者所属的 scope，例如 page_builder、website、blog 等。
     */
    public function getScope(): string;

    /**
     * 返回该 Sitemap 提供者所属的模块名称，例如 Weline_Websites、GuoLaiRen_PageBuilder。
     */
    public function getModule(): string;

    /**
     * 生成 Sitemap 并返回可访问的 URL 列表。
     *
     * @return string[] Sitemap URL 数组
     */
    public function generateSitemaps(): array;

    /**
     * 可选：返回该提供者的描述信息，用于调试与文档。
     */
    public function getDescription(): string;
}

