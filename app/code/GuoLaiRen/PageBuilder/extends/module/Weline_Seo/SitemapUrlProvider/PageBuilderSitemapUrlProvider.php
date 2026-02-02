<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * Sitemap URL 提供者扩展
 * 
 * 功能：
 * - 为 PageBuilder 模块提供 Sitemap URL 数据
 * - 实现 Weline_Seo 模块的 SitemapUrlProviderInterface 接口
 * - 自动同步 URL 到数据库，由平台适配器生成实际文件
 */

namespace GuoLaiRen\PageBuilder\extends\module\Weline_Seo\SitemapUrlProvider;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Provider\AbstractSitemapUrlProvider;
use Weline\Websites\Model\Website;

/**
 * PageBuilder Sitemap URL 提供者
 * 
 * 通过 extends 扩展点注册到 Weline_Seo 模块
 * SEO 模块的 Cron/SitemapSubmit 定时任务会自动发现并调用此类
 */
class PageBuilderSitemapUrlProvider extends AbstractSitemapUrlProvider
{
    private Page $pageModel;
    private Website $websiteModel;
    
    public function __construct()
    {
        parent::__construct();
        $this->pageModel = ObjectManager::getInstance(Page::class);
        $this->websiteModel = ObjectManager::getInstance(Website::class);
    }
    
    /**
     * 返回该 Provider 的 scope 标识
     */
    public function getScope(): string
    {
        return 'page_builder';
    }
    
    /**
     * 返回该 Provider 所属的模块名称
     */
    public function getModule(): string
    {
        return 'GuoLaiRen_PageBuilder';
    }
    
    /**
     * 返回此 Provider 管理的所有站点 ID
     */
    public function getWebsiteIds(): array
    {
        $websites = $this->websiteModel->reset()
            ->select()
            ->fetch()
            ->getItems();
        
        $ids = [];
        foreach ($websites as $website) {
            $ids[] = (int)$website->getId();
        }
        
        return $ids;
    }
    
    /**
     * 获取指定站点的 URL 数据
     * 
     * 返回格式：
     * [
     *     [
     *         'url_key' => 'page-123',           // 必需：唯一标识符
     *         'loc' => 'https://...',            // 必需：完整URL
     *         'lastmod' => '2026-01-30',         // 可选：最后修改日期
     *         'changefreq' => 'daily',           // 可选：更新频率
     *         'priority' => '0.8',               // 可选：优先级
     *     ],
     * ]
     */
    public function getUrlsForWebsite(int $websiteId): array
    {
        // 获取站点信息
        $website = clone $this->websiteModel;
        $website->load($websiteId);
        
        if (!$website->getId()) {
            return [];
        }
        
        $baseUrl = rtrim($website->getData('url') ?? '', '/');
        
        // 获取该站点的所有已发布页面
        $pages = $this->pageModel->reset()
            ->where(Page::fields_WEBSITE_ID, $websiteId)
            ->where(Page::fields_STATUS, 1) // 已发布
            ->select()
            ->fetch()
            ->getItems();
        
        $urls = [];
        
        foreach ($pages as $page) {
            $pageId = $page->getId();
            $handle = $page->getData(Page::fields_HANDLE); // PageBuilder 使用 handle 作为 URL 标识
            $updatedAt = $page->getData(Page::fields_UPDATE_TIME) ?? $page->getData(Page::fields_CREATE_TIME);
            
            if (!$handle) {
                continue; // 跳过没有 handle 的页面
            }
            
            // 构建完整 URL
            $fullUrl = $baseUrl . '/' . ltrim($handle, '/');
            
            // 确定更新频率和优先级
            $pageType = $page->getData(Page::fields_TYPE) ?? 'page';
            $changefreq = $this->getChangefreqByType($pageType);
            $priority = $this->getPriorityByType($pageType);
            
            $urls[] = [
                'url_key' => 'page-' . $pageId, // 使用 page-{id} 作为唯一标识
                'loc' => $fullUrl,
                'lastmod' => $this->formatDate($updatedAt),
                'changefreq' => $changefreq,
                'priority' => $priority,
            ];
        }
        
        return $urls;
    }
    
    /**
     * 返回 Provider 的描述信息
     */
    public function getDescription(): string
    {
        return __('PageBuilder 页面构建器 Sitemap URL 提供者，为所有站点的已发布页面提供 URL 数据');
    }
    
    /**
     * 根据页面类型确定更新频率
     */
    private function getChangefreqByType(string $type): string
    {
        $map = [
            'home' => 'daily',
            'blog' => 'daily',
            'news' => 'daily',
            'product' => 'weekly',
            'category' => 'weekly',
            'page' => 'monthly',
        ];
        
        return $map[$type] ?? 'monthly';
    }
    
    /**
     * 根据页面类型确定优先级
     */
    private function getPriorityByType(string $type): string
    {
        $map = [
            'home' => '1.0',
            'blog' => '0.7',
            'news' => '0.7',
            'product' => '0.8',
            'category' => '0.6',
            'page' => '0.5',
        ];
        
        return $map[$type] ?? '0.5';
    }
    
    /**
     * 格式化日期为 Y-m-d 格式
     */
    private function formatDate(?string $date): string
    {
        if (!$date) {
            return date('Y-m-d');
        }
        
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return date('Y-m-d');
        }
        
        return date('Y-m-d', $timestamp);
    }
}
