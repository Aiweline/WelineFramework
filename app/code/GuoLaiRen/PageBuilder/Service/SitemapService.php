<?php

declare(strict_types=1);

/*
 * Sitemap 生成服务 - 负责生成站点的 sitemap.xml 文件
 * 遵循单一职责原则(SRP)
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Manager\ObjectManager;

/**
 * Sitemap 生成服务
 * 
 * 按站点（website_id）生成标准格式的 sitemap.xml 文件
 */
class SitemapService
{
    private Page $pageModel;

    /**
     * Sitemap 存储目录（相对于项目根目录）
     */
    private const SITEMAP_DIR = 'pub/sitemaps';

    public function __construct()
    {
        $this->pageModel = ObjectManager::getInstance(Page::class);
    }
    
    /**
     * 为指定站点生成 Sitemap
     * 
     * @param int $websiteId 站点ID
     * @return string|null 生成的 Sitemap URL，失败返回 null
     */
    public function generateForWebsite(int $websiteId): ?string
    {
        $website = w_query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
        if ($website === null) {
            return null;
        }
        
        // 收集该站点下所有已发布的页面
        $pages = $this->getPublishedPages($websiteId);
        
        if (empty($pages)) {
            return null;
        }
        
        // 生成 Sitemap XML 内容（$website 为数组）
        $xml = $this->buildSitemapXml($website, $pages);
        
        // 确保目录存在
        $websiteCode = ($website['code'] ?? '') ?: ('website_' . $websiteId);
        $dir = BP . DS . self::SITEMAP_DIR . DS . $websiteCode;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // 保存文件
        $filePath = $dir . DS . 'sitemap.xml';
        $result = file_put_contents($filePath, $xml);
        
        if ($result === false) {
            return null;
        }
        
        // 返回可访问的 URL（80/443 端口不输出）
        $baseUrl = \GuoLaiRen\PageBuilder\Helper\PageHelper::normalizeUrlDefaultPort(rtrim($website->getUrl(), '/'));
        return $baseUrl . '/sitemaps/' . $websiteCode . '/sitemap.xml';
    }
    
    /**
     * 为所有站点生成 Sitemap
     * 
     * @return array 生成的 Sitemap URL 列表
     */
    public function generateForAllWebsites(): array
    {
        $sitemapUrls = [];

        // 获取所有站点（通过查询器，避免跨模块直接调用）
        $websites = w_query('websites', 'getWebsiteList', []);

        foreach ($websites as $website) {
            $websiteId = (int)($website['website_id'] ?? 0);
            $url = $this->generateForWebsite($websiteId);
            if ($url !== null) {
                $sitemapUrls[] = $url;
            }
        }
        
        return $sitemapUrls;
    }
    
    /**
     * 获取指定站点的已发布页面
     * 
     * @param int $websiteId 站点ID
     * @return array 页面列表
     */
    private function getPublishedPages(int $websiteId): array
    {
        return $this->pageModel->reset()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED)
            ->order(Page::schema_fields_TYPE, 'ASC')
            ->order(Page::schema_fields_UPDATE_TIME, 'DESC')
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 构建 Sitemap XML 内容
     *
     * @param array $website 站点信息（来自 websites 查询器）
     * @param array $pages 页面列表
     * @return string XML 内容
     */
    private function buildSitemapXml(array $website, array $pages): string
    {
        $baseUrl = \GuoLaiRen\PageBuilder\Helper\PageHelper::normalizeUrlDefaultPort(rtrim($website['url'] ?? '', '/'));
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($pages as $page) {
            $handle = $page->getData(Page::schema_fields_HANDLE);
            $pageType = $page->getData(Page::schema_fields_TYPE);
            $updateTime = $page->getData(Page::schema_fields_UPDATE_TIME);
            
            // 首页特殊处理
            if ($pageType === Page::TYPE_HOME) {
                $loc = $baseUrl . '/';
            } else {
                // 其他页面使用 handle 作为路径
                $loc = $baseUrl . '/' . ltrim((string)$handle, '/');
            }
            
            // 格式化日期为 W3C Datetime 格式
            $lastmod = '';
            if ($updateTime) {
                try {
                    $lastmod = date('Y-m-d', strtotime($updateTime));
                } catch (\Throwable $e) {
                    $lastmod = date('Y-m-d');
                }
            }
            
            // 根据页面类型设置优先级和更新频率
            $priority = $this->getPriorityByPageType($pageType);
            $changefreq = $this->getChangefreqByPageType($pageType);
            
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . "</loc>\n";
            if ($lastmod) {
                $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            }
            $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
            $xml .= "    <priority>{$priority}</priority>\n";
            $xml .= "  </url>\n";
        }
        
        $xml .= '</urlset>';
        
        return $xml;
    }
    
    /**
     * 根据页面类型获取优先级
     * 
     * @param string|null $pageType 页面类型
     * @return string 优先级 (0.0 - 1.0)
     */
    private function getPriorityByPageType(?string $pageType): string
    {
        return match ($pageType) {
            Page::TYPE_HOME => '1.0',
            Page::TYPE_ABOUT, Page::TYPE_CONTACT => '0.8',
            Page::TYPE_BLOG_LIST => '0.7',
            Page::TYPE_BLOG, Page::TYPE_BLOG_CATEGORY => '0.6',
            Page::TYPE_PRIVACY_POLICY, Page::TYPE_TERMS_OF_SERVICE => '0.3',
            Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY, Page::TYPE_COOKIE_POLICY => '0.2',
            default => '0.5',
        };
    }
    
    /**
     * 根据页面类型获取更新频率
     * 
     * @param string|null $pageType 页面类型
     * @return string 更新频率
     */
    private function getChangefreqByPageType(?string $pageType): string
    {
        return match ($pageType) {
            Page::TYPE_HOME => 'daily',
            Page::TYPE_BLOG_LIST => 'daily',
            Page::TYPE_BLOG => 'weekly',
            Page::TYPE_ABOUT, Page::TYPE_CONTACT => 'monthly',
            default => 'monthly',
        };
    }
    
    /**
     * 获取指定站点的 Sitemap 文件路径
     * 
     * @param int $websiteId 站点ID
     * @return string|null 文件路径
     */
    public function getSitemapPath(int $websiteId): ?string
    {
        $website = w_query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
        if ($website === null) {
            return null;
        }

        $websiteCode = ($website['code'] ?? '') ?: ('website_' . $websiteId);
        $filePath = BP . DS . self::SITEMAP_DIR . DS . $websiteCode . DS . 'sitemap.xml';
        
        return file_exists($filePath) ? $filePath : null;
    }
    
    /**
     * 获取指定站点的 Sitemap URL
     * 
     * @param int $websiteId 站点ID
     * @return string|null Sitemap URL
     */
    public function getSitemapUrl(int $websiteId): ?string
    {
        $website = w_query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
        if ($website === null) {
            return null;
        }

        $websiteCode = ($website['code'] ?? '') ?: ('website_' . $websiteId);
        $filePath = BP . DS . self::SITEMAP_DIR . DS . $websiteCode . DS . 'sitemap.xml';
        
        if (!file_exists($filePath)) {
            return null;
        }

        $baseUrl = \GuoLaiRen\PageBuilder\Helper\PageHelper::normalizeUrlDefaultPort(rtrim($website['url'] ?? '', '/'));
        return $baseUrl . '/sitemaps/' . $websiteCode . '/sitemap.xml';
    }
}
