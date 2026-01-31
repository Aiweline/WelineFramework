<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Adapter;

use Weline\Seo\Interface\SitemapPlatformAdapterInterface;
use Weline\Seo\Model\SitemapUrl;

/**
 * Sitemap 平台适配器抽象基类
 *
 * 提供通用的 sitemap 生成逻辑，子类只需定义平台特定的规则和提交方法。
 *
 * @package Weline_Seo
 */
abstract class AbstractSitemapPlatformAdapter implements SitemapPlatformAdapterInterface
{
    /**
     * Sitemap 存储根目录
     */
    protected const SITEMAP_DIR = 'pub/sitemaps';

    /**
     * 获取平台目录路径
     */
    public function getPlatformDir(string $siteDir): string
    {
        return $siteDir . '/' . $this->getPlatformCode();
    }

    /**
     * 获取平台总索引 URL
     */
    public function getIndexUrl(string $baseUrl, string $websiteCode): string
    {
        return $baseUrl . '/sitemaps/' . $websiteCode . '/' . $this->getPlatformCode() . '/sitemap.xml';
    }

    /**
     * 生成 sitemap 文件（通用实现）
     */
    public function generateSitemapFiles(
        int $websiteId,
        string $websiteCode,
        string $baseUrl,
        array $groupedUrls
    ): array {
        $siteDir = BP . '/' . self::SITEMAP_DIR . '/' . $websiteCode;
        $platformDir = $this->getPlatformDir($siteDir);
        
        // 确保目录存在
        if (!is_dir($platformDir)) {
            mkdir($platformDir, 0755, true);
        }

        $maxUrls = $this->getMaxUrlsPerFile();
        $maxSize = $this->getMaxFileSizeBytes();
        
        $moduleResults = [];
        $totalUrls = 0;
        $totalFiles = 0;

        // 按模块生成 sitemap 文件
        foreach ($groupedUrls as $module => $urls) {
            $moduleResult = $this->generateModuleFiles(
                $platformDir,
                $module,
                $urls,
                $baseUrl,
                $websiteCode,
                $maxUrls,
                $maxSize
            );
            
            $moduleResults[$module] = $moduleResult;
            $totalUrls += $moduleResult['url_count'];
            $totalFiles += $moduleResult['file_count'];
        }

        // 生成平台总索引文件
        $indexFile = $this->generatePlatformIndex($platformDir, $moduleResults, $baseUrl, $websiteCode);
        $totalFiles++;

        return [
            'platform_code' => $this->getPlatformCode(),
            'platform_name' => $this->getPlatformName(),
            'platform_color' => $this->getPlatformColor(),
            'index' => $indexFile,
            'modules' => $moduleResults,
            'total_urls' => $totalUrls,
            'total_files' => $totalFiles,
        ];
    }

    /**
     * 为单个模块生成 sitemap 文件
     */
    protected function generateModuleFiles(
        string $platformDir,
        string $module,
        array $urls,
        string $baseUrl,
        string $websiteCode,
        int $maxUrls,
        int $maxSize
    ): array {
        $urlCount = count($urls);
        $files = [];
        
        $moduleName = $this->simplifyModuleName($module);
        $platformCode = $this->getPlatformCode();

        // 按最大 URL 数分割
        $chunks = array_chunk($urls, $maxUrls);
        
        foreach ($chunks as $index => $chunk) {
            $fileIndex = $index + 1;
            $filename = sprintf('sitemap_%s_%d.xml', $moduleName, $fileIndex);
            $filepath = $platformDir . '/' . $filename;
            
            $xml = $this->buildSitemapXml($chunk, $baseUrl);
            
            // 检查文件大小限制
            if (strlen($xml) > $maxSize) {
                $subChunks = $this->splitBySize($chunk, $baseUrl, $maxSize);
                foreach ($subChunks as $subIndex => $subChunk) {
                    $subFileIndex = count($files) + 1;
                    $subFilename = sprintf('sitemap_%s_%d.xml', $moduleName, $subFileIndex);
                    $subFilepath = $platformDir . '/' . $subFilename;
                    $subXml = $this->buildSitemapXml($subChunk, $baseUrl);
                    file_put_contents($subFilepath, $subXml);
                    
                    $files[] = [
                        'filename' => $subFilename,
                        'path' => $subFilepath,
                        'url' => $baseUrl . '/sitemaps/' . $websiteCode . '/' . $platformCode . '/' . $subFilename,
                        'count' => count($subChunk),
                        'size' => strlen($subXml),
                    ];
                }
            } else {
                file_put_contents($filepath, $xml);
                
                $files[] = [
                    'filename' => $filename,
                    'path' => $filepath,
                    'url' => $baseUrl . '/sitemaps/' . $websiteCode . '/' . $platformCode . '/' . $filename,
                    'count' => count($chunk),
                    'size' => strlen($xml),
                ];
            }
        }

        return [
            'module' => $module,
            'module_name' => $moduleName,
            'files' => $files,
            'url_count' => $urlCount,
            'file_count' => count($files),
        ];
    }

    /**
     * 生成平台总索引文件
     */
    protected function generatePlatformIndex(
        string $platformDir,
        array $moduleResults,
        string $baseUrl,
        string $websiteCode
    ): array {
        $filepath = $platformDir . '/sitemap.xml';
        $platformCode = $this->getPlatformCode();
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        $today = date('Y-m-d');
        
        foreach ($moduleResults as $moduleData) {
            foreach ($moduleData['files'] as $file) {
                $sitemapUrl = $file['url'] ?? '';
                if (empty($sitemapUrl)) {
                    continue;
                }
                $xml .= "  <sitemap>\n";
                $xml .= "    <loc>" . htmlspecialchars($sitemapUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
                $xml .= "    <lastmod>{$today}</lastmod>\n";
                $xml .= "  </sitemap>\n";
            }
        }
        
        $xml .= '</sitemapindex>';
        
        file_put_contents($filepath, $xml);
        
        return [
            'filename' => 'sitemap.xml',
            'path' => $filepath,
            'url' => $baseUrl . '/sitemaps/' . $websiteCode . '/' . $platformCode . '/sitemap.xml',
            'size' => strlen($xml),
        ];
    }

    /**
     * 构建 sitemap XML
     */
    protected function buildSitemapXml(array $urls, string $baseUrl): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= $this->buildUrlEntry($url, $baseUrl);
        }

        $xml .= '</urlset>';
        
        return $xml;
    }

    /**
     * 构建单个 URL 条目
     */
    protected function buildUrlEntry(array $url, string $baseUrl): string
    {
        $loc = $url[SitemapUrl::fields_URL] ?? ''; // 使用 fields_URL 而不是 fields_LOC
        if (empty($loc)) {
            return '';
        }

        if (strpos($loc, 'http') !== 0) {
            $loc = $baseUrl . '/' . ltrim($loc, '/');
        }

        $xml = "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";

        $lastmod = $url[SitemapUrl::fields_LASTMOD] ?? '';
        if (!empty($lastmod)) {
            $lastmod = date('Y-m-d', strtotime($lastmod));
            $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        }

        $changefreq = $url[SitemapUrl::fields_CHANGEFREQ] ?? 'weekly';
        $xml .= "    <changefreq>{$changefreq}</changefreq>\n";

        $priority = $url[SitemapUrl::fields_PRIORITY] ?? '0.5';
        $xml .= "    <priority>{$priority}</priority>\n";

        $xml .= "  </url>\n";
        
        return $xml;
    }

    /**
     * 按文件大小分割 URL 数组
     */
    protected function splitBySize(array $urls, string $baseUrl, int $maxSize): array
    {
        $chunks = [];
        $currentChunk = [];
        $currentSize = 200;
        
        foreach ($urls as $url) {
            $urlXml = $this->buildUrlEntry($url, $baseUrl);
            $urlSize = strlen($urlXml);
            
            if ($currentSize + $urlSize > $maxSize && !empty($currentChunk)) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $currentSize = 200;
            }
            
            $currentChunk[] = $url;
            $currentSize += $urlSize;
        }
        
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }
        
        return $chunks;
    }

    /**
     * 简化模块名
     */
    protected function simplifyModuleName(string $module): string
    {
        $parts = explode('_', $module);
        $name = end($parts);
        return strtolower($name);
    }

    /**
     * 默认不支持自动提交（子类可覆盖）
     */
    public function supportsAutoSubmit(): bool
    {
        return false;
    }

    /**
     * 默认提交实现（子类应覆盖）
     */
    public function submitSitemap(string $sitemapUrl, array $accountConfig): array
    {
        return [
            'success' => false,
            'message' => __('该平台不支持自动提交'),
            'response' => null,
        ];
    }

    /**
     * 格式化文件大小
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
