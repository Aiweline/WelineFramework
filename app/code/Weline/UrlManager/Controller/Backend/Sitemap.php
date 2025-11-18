<?php
declare(strict_types=1);

namespace Weline\UrlManager\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Websites\Model\Website;

/**
 * Sitemap管理控制器
 * 
 * 功能：
 * - 根据站点生成sitemap.xml
 * - 保存到pub目录
 * - 支持全站生成
 * - 支持分割（超过50000条URL时分割）
 * - 生成sitemap索引文件
 */
#[Acl('Weline_UrlManager::sitemap_management', 'Sitemap管理', 'mdi-sitemap', 'Sitemap管理', 'Weline_UrlManager::seo_optimization')]
class Sitemap extends BackendController
{
    const MAX_URLS_PER_SITEMAP = 50000;
    const PUB_DIR = BP . '/pub';
    
    /**
     * Sitemap管理首页
     * 
     * @return string
     */
    #[Acl('Weline_UrlManager::sitemap_management_index', '查看Sitemap管理', 'mdi-sitemap', '查看Sitemap管理')]
    public function index(): string
    {
        try {
            // 获取所有站点
            $websites = $this->getAllWebsites();
            
            // 获取已生成的sitemap文件列表
            $sitemapFiles = $this->getSitemapFiles();
            
            $this->assign('websites', $websites);
            $this->assign('sitemap_files', $sitemapFiles);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载Sitemap管理失败：%{1}', $e->getMessage()));
            $this->assign('websites', []);
            $this->assign('sitemap_files', []);
            return $this->fetch();
        }
    }
    
    /**
     * 生成Sitemap
     * 
     * @return string
     */
    #[Acl('Weline_UrlManager::sitemap_management_generate', '生成Sitemap', 'mdi-refresh', '生成Sitemap')]
    public function generate(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }
        
        try {
            $websiteId = (int)$this->request->getPost('website_id', 0);
            $generateAll = (bool)$this->request->getPost('generate_all', false);
            
            if ($generateAll) {
                // 生成全站Sitemap
                $result = $this->generateAllSitemaps();
            } else {
                // 生成指定站点Sitemap
                if ($websiteId <= 0) {
                    return $this->jsonResponse(false, __('请选择站点'));
                }
                $result = $this->generateSitemapForWebsite($websiteId);
            }
            
            return $this->jsonResponse(true, __('Sitemap生成成功'), $result);
            
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('生成Sitemap失败：%{1}', $e->getMessage()));
        }
    }
    
    /**
     * 获取所有站点
     * 
     * @return array
     */
    private function getAllWebsites(): array
    {
        try {
            /** @var Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(Website::class);
            return $websiteModel->select()->fetchArray();
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 获取已生成的sitemap文件列表
     * 
     * @return array
     */
    private function getSitemapFiles(): array
    {
        $files = [];
        $sitemapDir = self::PUB_DIR;
        
        if (!is_dir($sitemapDir)) {
            return $files;
        }
        
        $pattern = $sitemapDir . '/sitemap*.xml';
        $foundFiles = glob($pattern);
        
        foreach ($foundFiles as $file) {
            $files[] = [
                'name' => basename($file),
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }
        
        return $files;
    }
    
    /**
     * 生成全站Sitemap
     * 
     * @return array
     */
    private function generateAllSitemaps(): array
    {
        $websites = $this->getAllWebsites();
        $results = [];
        
        foreach ($websites as $website) {
            $websiteId = $website['website_id'] ?? $website['id'] ?? 0;
            if ($websiteId > 0) {
                $results[] = $this->generateSitemapForWebsite($websiteId);
            }
        }
        
        // 生成sitemap索引文件
        $this->generateSitemapIndex();
        
        return $results;
    }
    
    /**
     * 为指定站点生成Sitemap
     * 
     * @param int $websiteId
     * @return array
     */
    private function generateSitemapForWebsite(int $websiteId): array
    {
        // 获取站点URL列表
        $urls = $this->getUrlsForWebsite($websiteId);
        
        if (empty($urls)) {
            return ['website_id' => $websiteId, 'files' => [], 'message' => __('该站点没有URL')];
        }
        
        // 如果URL数量超过限制，需要分割
        $urlChunks = array_chunk($urls, self::MAX_URLS_PER_SITEMAP);
        $files = [];
        
        foreach ($urlChunks as $index => $chunk) {
            $filename = count($urlChunks) > 1 
                ? "sitemap_{$websiteId}_" . ($index + 1) . ".xml"
                : "sitemap_{$websiteId}.xml";
            
            $filepath = self::PUB_DIR . '/' . $filename;
            $this->writeSitemapFile($filepath, $chunk);
            $files[] = $filename;
        }
        
        return [
            'website_id' => $websiteId,
            'files' => $files,
            'total_urls' => count($urls),
        ];
    }
    
    /**
     * 获取站点的URL列表
     * 
     * @param int $websiteId
     * @return array
     */
    private function getUrlsForWebsite(int $websiteId): array
    {
        // TODO: 实现获取站点URL列表的逻辑
        // 这里需要根据实际业务逻辑获取URL
        // 例如：产品URL、分类URL、页面URL等
        
        $urls = [];
        
        // 示例：获取产品URL
        // $products = ...;
        // foreach ($products as $product) {
        //     $urls[] = [
        //         'loc' => $product->getUrl(),
        //         'lastmod' => $product->getUpdatedAt(),
        //         'changefreq' => 'daily',
        //         'priority' => '0.8',
        //     ];
        // }
        
        return $urls;
    }
    
    /**
     * 写入Sitemap文件
     * 
     * @param string $filepath
     * @param array $urls
     * @return void
     */
    private function writeSitemapFile(string $filepath, array $urls): void
    {
        // 确保目录存在
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($url['loc'] ?? '') . "</loc>\n";
            if (isset($url['lastmod'])) {
                $xml .= "    <lastmod>" . htmlspecialchars($url['lastmod']) . "</lastmod>\n";
            }
            if (isset($url['changefreq'])) {
                $xml .= "    <changefreq>" . htmlspecialchars($url['changefreq']) . "</changefreq>\n";
            }
            if (isset($url['priority'])) {
                $xml .= "    <priority>" . htmlspecialchars($url['priority']) . "</priority>\n";
            }
            $xml .= "  </url>\n";
        }
        
        $xml .= '</urlset>';
        
        file_put_contents($filepath, $xml);
    }
    
    /**
     * 生成Sitemap索引文件
     * 
     * @return void
     */
    private function generateSitemapIndex(): void
    {
        $sitemapFiles = $this->getSitemapFiles();
        $baseUrl = $this->request->getBaseUrl();
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($sitemapFiles as $file) {
            $xml .= "  <sitemap>\n";
            $xml .= "    <loc>" . htmlspecialchars($baseUrl . '/pub/' . $file['name']) . "</loc>\n";
            $xml .= "    <lastmod>" . htmlspecialchars($file['modified']) . "</lastmod>\n";
            $xml .= "  </sitemap>\n";
        }
        
        $xml .= '</sitemapindex>';
        
        $filepath = self::PUB_DIR . '/sitemap.xml';
        file_put_contents($filepath, $xml);
    }
    
    /**
     * JSON响应
     * 
     * @param bool $success
     * @param string $message
     * @param array $data
     * @return string
     */
    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}

