<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * Sitemap 提供者扩展
 * 
 * 功能：
 * - 为 PageBuilder 模块提供 Sitemap 生成能力
 * - 实现 Weline_Seo 模块的 SitemapProviderInterface 接口
 * - 自动被 SEO 模块的定时任务发现和调用
 */

namespace GuoLaiRen\PageBuilder\extends\module\Weline_Seo\SitemapProvider;

use GuoLaiRen\PageBuilder\Service\SitemapService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\SitemapProviderInterface;

/**
 * PageBuilder Sitemap 提供者
 * 
 * 通过 extends 扩展点注册到 Weline_Seo 模块
 * SEO 模块的 Cron/SitemapSubmit 定时任务会自动发现并调用此类
 */
class PageBuilderSitemapProvider implements SitemapProviderInterface
{
    private SitemapService $sitemapService;
    
    public function __construct()
    {
        $this->sitemapService = ObjectManager::getInstance(SitemapService::class);
    }
    
    /**
     * 返回该 Sitemap 提供者所属的 scope
     * 
     * @return string
     */
    public function getScope(): string
    {
        return 'page_builder';
    }
    
    /**
     * 返回该 Sitemap 提供者所属的模块名称
     * 
     * @return string
     */
    public function getModule(): string
    {
        return 'GuoLaiRen_PageBuilder';
    }
    
    /**
     * 生成 Sitemap 并返回可访问的 URL 列表
     * 
     * 此方法会为所有站点生成 sitemap.xml 文件，
     * 并返回生成的 Sitemap URL 数组
     * 
     * @return string[] Sitemap URL 数组
     */
    public function generateSitemaps(): array
    {
        try {
            return $this->sitemapService->generateForAllWebsites();
        } catch (\Throwable $e) {
            // 记录错误日志，但不中断流程
            if (defined('DEV') && DEV) {
                error_log('PageBuilderSitemapProvider::generateSitemaps() error: ' . $e->getMessage());
            }
            return [];
        }
    }
    
    /**
     * 返回该提供者的描述信息
     * 
     * @return string
     */
    public function getDescription(): string
    {
        return __('PageBuilder 页面构建器 Sitemap 生成器，为所有站点的已发布页面生成 sitemap.xml');
    }
}
