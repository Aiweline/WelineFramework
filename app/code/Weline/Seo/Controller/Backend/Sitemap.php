<?php
declare(strict_types=1);

namespace Weline\Seo\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Websites\Model\Website;
use Weline\Seo\Service\SitemapRegistryService;

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
#[Acl('Weline_Seo::sitemap_management', 'Sitemap管理', 'mdi-sitemap', 'Sitemap管理', 'Weline_Seo::seo_management')]
class Sitemap extends BackendController
{
    const MAX_URLS_PER_SITEMAP = 50000;
    const PUB_DIR = BP . '/pub';
    
    /**
     * Sitemap管理首页
     * 
     * @return string
     */
    #[Acl('Weline_Seo::sitemap_management_index', '查看Sitemap管理', 'mdi-sitemap', '查看Sitemap管理')]
    public function index(): string
    {
        try {
            // 获取所有站点
            $websites = $this->getAllWebsites();
            
            // 获取主 sitemap 索引文件
            $indexFile = $this->getIndexSitemapFile();
            
            // 获取所有注册的 SitemapProvider
            $providers = $this->getRegisteredProviders();
            
            // 获取 pub/sitemaps 目录下各模块生成的 sitemap（包含错误信息）
            $moduleSitemaps = $this->getModuleSitemapsWithStatus($websites);
            
            $this->assign('websites', $websites);
            $this->assign('index_file', $indexFile);
            $this->assign('providers', $providers);
            $this->assign('module_sitemaps', $moduleSitemaps);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载Sitemap管理失败：%{1}', $e->getMessage()));
            $this->assign('websites', []);
            $this->assign('index_file', null);
            $this->assign('providers', []);
            $this->assign('module_sitemaps', []);
            return $this->fetch();
        }
    }
    
    /**
     * 生成Sitemap
     * 
     * @return string
     */
    #[Acl('Weline_Seo::sitemap_management_generate', '生成Sitemap', 'mdi-refresh', '生成Sitemap')]
    public function generate(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }
        
        try {
            $websiteId = (int)$this->request->getPost('website_id', 0);
            $generateAll = (bool)$this->request->getPost('generate_all', false);
            $useProviders = (bool)$this->request->getPost('use_providers', false);
            $providerModule = (string)$this->request->getPost('provider_module', '');
            
            // 使用注册的 Provider 生成
            if ($useProviders) {
                $result = $this->generateByProviders($providerModule);
                $message = __('已同步 %{1} 个URL，生成 %{2} 个sitemap文件', [
                    $result['total_urls_synced'] ?? 0,
                    $result['total_files_generated'] ?? 0
                ]);
                return $this->jsonResponse(true, $message, $result);
            }
            
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
     * 通过注册的 Provider 生成 Sitemap
     * 
     * 统一的生成流程：
     * 1. 调用所有 URL Provider 同步 URL 数据到数据库
     * 2. 为每个站点生成实际的 sitemap XML 文件
     * 
     * @param string $filterModule 过滤指定模块，为空则调用所有 Provider
     * @return array
     */
    private function generateByProviders(string $filterModule = ''): array
    {
        /** @var SitemapRegistryService $registry */
        $registry = ObjectManager::getInstance(SitemapRegistryService::class);
        
        // 使用新架构：获取 URL Provider
        $urlProviders = $registry->getUrlProviders(true); // 强制刷新
        
        $providerResults = [];
        $totalUrlsSynced = 0;
        
        // 第一步：调用所有 URL Provider 同步 URL 数据到数据库
        foreach ($urlProviders as $provider) {
            // 如果指定了模块，只调用该模块的 Provider
            if ($filterModule !== '' && $provider->getModule() !== $filterModule) {
                continue;
            }
            
            // 检查 Provider 是否启用
            if (!$provider->isEnabled()) {
                continue;
            }
            
            try {
                // 调用新方法：saveUrls() 保存 URL 到数据库
                $urlCount = $provider->saveUrls();
                $totalUrlsSynced += $urlCount;
                
                $providerResults[] = [
                    'module' => $provider->getModule(),
                    'scope' => $provider->getScope(),
                    'description' => $provider->getDescription(),
                    'url_count' => $urlCount,
                    'success' => true,
                ];
            } catch (\Throwable $e) {
                $providerResults[] = [
                    'module' => $provider->getModule(),
                    'scope' => $provider->getScope(),
                    'description' => $provider->getDescription(),
                    'url_count' => 0,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        // 第二步：为所有站点生成实际的 sitemap XML 文件
        $websites = $this->getAllWebsites();
        $sitemapResults = [];
        $totalFiles = 0;
        
        foreach ($websites as $website) {
            $websiteId = (int)($website['website_id'] ?? $website['id'] ?? 0);
            if ($websiteId <= 0) {
                continue;
            }
            
            try {
                $result = $this->generateSitemapForWebsite($websiteId);
                $sitemapResults[] = $result;
                $totalFiles += $result['total_files'] ?? 0;
            } catch (\Throwable $e) {
                $sitemapResults[] = [
                    'website_id' => $websiteId,
                    'platforms' => [],
                    'message' => __('生成失败：%{1}', $e->getMessage()),
                    'error' => true,
                ];
            }
        }
        
        // 生成跨站点总索引文件
        if ($totalFiles > 0) {
            $this->generateSitemapIndex();
        }
        
        return [
            'provider_results' => $providerResults,
            'sitemap_results' => $sitemapResults,
            'total_urls_synced' => $totalUrlsSynced,
            'total_files_generated' => $totalFiles,
        ];
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
     * 获取所有注册的 SitemapProvider
     * 
     * @return array
     */
    private function getRegisteredProviders(): array
    {
        try {
            /** @var SitemapRegistryService $registry */
            $registry = ObjectManager::getInstance(SitemapRegistryService::class);
            $providers = $registry->getProviders();
            
            $result = [];
            foreach ($providers as $provider) {
                $result[] = [
                    'scope' => $provider->getScope(),
                    'module' => $provider->getModule(),
                    'description' => $provider->getDescription(),
                    'class' => get_class($provider),
                ];
            }
            
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 获取各模块生成的 Sitemap 文件（包含状态信息）
     * 
     * 检查站点是否有 URL 数据和 SEO 账户绑定
     * 
     * @param array $websites
     * @return array
     */
    private function getModuleSitemapsWithStatus(array $websites): array
    {
        $result = $this->getModuleSitemaps($websites);
        
        /** @var \Weline\Seo\Service\WebSitemapData $webSitemapData */
        $webSitemapData = ObjectManager::getInstance(\Weline\Seo\Service\WebSitemapData::class);
        
        // 为每个站点检查状态
        foreach ($websites as $website) {
            $websiteId = $website['website_id'] ?? $website['id'] ?? 0;
            $websiteCode = $website['code'] ?? ('website_' . $websiteId);
            
            // 如果该站点在 result 中不存在，检查是否有错误状态
            if (!isset($result[$websiteCode])) {
                $statusResult = $webSitemapData->generateSitemapFiles($websiteId);
                
                if (isset($statusResult['error'])) {
                    $result[$websiteCode] = [
                        'website_code' => $websiteCode,
                        'website_id' => $websiteId,
                        'website_name' => $website['name'] ?? $website['domain'] ?? __('站点') . ' ' . $websiteId,
                        'website_domain' => $website['domain'] ?? '',
                        'website_url' => $website['url'] ?? '',
                        'platforms' => [],
                        'error' => $statusResult['error'],
                        'message' => $statusResult['message'] ?? '',
                        'total_urls' => $statusResult['total_urls'] ?? 0,
                    ];
                }
            }
        }
        
        return $result;
    }

    /**
     * 获取各模块生成的 Sitemap 文件（pub/sitemaps 目录）
     * 
     * 站点-平台两级分组结构：
     * pub/sitemaps/{website_code}/
     * ├── google/                           # 平台目录
     * │   ├── sitemap.xml                   # 平台总索引（提交给 Google）
     * │   ├── sitemap_pagebuilder_1.xml     # 模块文件
     * │   └── sitemap_product_1.xml
     * ├── bing/
     * │   └── ...
     * └── baidu/
     *     └── ...
     * 
     * @param array $websites
     * @return array
     */
    private function getModuleSitemaps(array $websites): array
    {
        $result = [];
        $sitemapsDir = self::PUB_DIR . '/sitemaps';
        
        if (!is_dir($sitemapsDir)) {
            return $result;
        }
        
        // 平台显示名称和颜色
        $platformInfo = [
            'google' => ['name' => 'Google', 'color' => '#4285F4'],
            'bing' => ['name' => 'Bing', 'color' => '#00809D'],
            'baidu' => ['name' => '百度', 'color' => '#2932E1'],
            'yandex' => ['name' => 'Yandex', 'color' => '#FF0000'],
            'naver' => ['name' => 'Naver', 'color' => '#03C75A'],
        ];
        
        // 构建站点 code 到信息的映射
        $websiteMap = [];
        foreach ($websites as $website) {
            $code = $website['code'] ?? '';
            $id = $website['website_id'] ?? $website['id'] ?? 0;
            if ($code) {
                $websiteMap[$code] = [
                    'website_id' => $id,
                    'name' => $website['name'] ?? $website['domain'] ?? __('站点') . ' ' . $id,
                    'domain' => $website['domain'] ?? '',
                    'url' => $website['url'] ?? '',
                ];
            }
            $websiteMap['website_' . $id] = $websiteMap[$code] ?? [
                'website_id' => $id,
                'name' => $website['name'] ?? $website['domain'] ?? __('站点') . ' ' . $id,
                'domain' => $website['domain'] ?? '',
                'url' => $website['url'] ?? '',
            ];
        }
        
        // 扫描站点目录
        $siteDirs = glob($sitemapsDir . '/*', GLOB_ONLYDIR);
        
        foreach ($siteDirs as $siteDir) {
            $websiteCode = basename($siteDir);
            $websiteInfo = $websiteMap[$websiteCode] ?? null;
            
            if (!$websiteInfo) {
                continue;
            }
            
            // 扫描平台目录
            $platforms = [];
            $platformDirs = glob($siteDir . '/*', GLOB_ONLYDIR);
            $totalFiles = 0;
            
            foreach ($platformDirs as $platformDir) {
                $platformCode = basename($platformDir);
                $pInfo = $platformInfo[$platformCode] ?? ['name' => ucfirst($platformCode), 'color' => '#6c757d'];
                
                // 平台总索引文件（sitemap.xml）
                $platformIndex = null;
                $indexPath = $platformDir . '/sitemap.xml';
                if (file_exists($indexPath)) {
                    $fileSize = filesize($indexPath);
                    $platformIndex = [
                        'name' => 'sitemap.xml',
                        'path' => $indexPath,
                        'size' => $fileSize,
                        'size_formatted' => $this->formatBytes($fileSize),
                        'modified' => date('Y-m-d H:i:s', filemtime($indexPath)),
                        'url' => '/sitemaps/' . $websiteCode . '/' . $platformCode . '/sitemap.xml',
                    ];
                    $totalFiles++;
                }
                
                // 扫描模块 sitemap 文件（sitemap_{module}_{n}.xml）
                $modules = [];
                $sitemapFiles = glob($platformDir . '/sitemap_*.xml');
                
                foreach ($sitemapFiles as $file) {
                    $filename = basename($file);
                    // 解析文件名：sitemap_{module}_{n}.xml
                    if (preg_match('/^sitemap_([a-z]+)_(\d+)\.xml$/', $filename, $matches)) {
                        $moduleName = $matches[1];
                        $fileIndex = (int)$matches[2];
                        
                        if (!isset($modules[$moduleName])) {
                            $modules[$moduleName] = [
                                'module_name' => $moduleName,
                                'files' => [],
                                'url_count' => 0,
                            ];
                        }
                        
                        $fileSize = filesize($file);
                        $modules[$moduleName]['files'][] = [
                            'name' => $filename,
                            'path' => $file,
                            'index' => $fileIndex,
                            'size' => $fileSize,
                            'size_formatted' => $this->formatBytes($fileSize),
                            'modified' => date('Y-m-d H:i:s', filemtime($file)),
                            'url' => '/sitemaps/' . $websiteCode . '/' . $platformCode . '/' . $filename,
                        ];
                        $totalFiles++;
                    }
                }
                
                // 按文件索引排序
                foreach ($modules as &$moduleData) {
                    usort($moduleData['files'], fn($a, $b) => $a['index'] <=> $b['index']);
                    $moduleData['file_count'] = count($moduleData['files']);
                }
                unset($moduleData);
                
                if ($platformIndex || !empty($modules)) {
                    $platforms[$platformCode] = [
                        'platform_code' => $platformCode,
                        'platform_name' => $pInfo['name'],
                        'platform_color' => $pInfo['color'],
                        'index' => $platformIndex,
                        'modules' => $modules,
                        'module_count' => count($modules),
                        'file_count' => count($sitemapFiles) + ($platformIndex ? 1 : 0),
                    ];
                }
            }
            
            $result[$websiteCode] = [
                'website_code' => $websiteCode,
                'website_id' => $websiteInfo['website_id'],
                'website_name' => $websiteInfo['name'],
                'website_domain' => $websiteInfo['domain'],
                'website_url' => $websiteInfo['url'],
                'source' => 'module',
                'platforms' => $platforms,
                'platform_count' => count($platforms),
                'total_files' => $totalFiles,
            ];
        }
        
        return $result;
    }
    
    /**
     * 获取主 sitemap 索引文件信息
     * 
     * @return array|null
     */
    private function getIndexSitemapFile(): ?array
    {
        $indexFile = self::PUB_DIR . '/sitemaps/sitemap.xml';
        
        if (!file_exists($indexFile)) {
            return null;
        }
        
        // 获取前台基础 URL（纯域名，不含后台路径）
        $frontendBaseUrl = $this->request->getBaseHost();
        if (!preg_match('/^https?:\/\//i', $frontendBaseUrl)) {
            $scheme = $this->request->isSecure() ? 'https' : 'http';
            $host = $this->request->getHttpHost();
            $frontendBaseUrl = $scheme . '://' . $host;
        }
        $frontendBaseUrl = rtrim($frontendBaseUrl, '/');
        
        $fileSize = filesize($indexFile);
        return [
            'name' => 'sitemap.xml',
            'path' => $indexFile,
            'size' => $fileSize,
            'size_formatted' => $this->formatBytes($fileSize),
            'modified' => date('Y-m-d H:i:s', filemtime($indexFile)),
            'url' => '/sitemaps/sitemap.xml',  // 相对路径
            'full_url' => $frontendBaseUrl . '/sitemaps/sitemap.xml',  // 完整前台 URL
        ];
    }
    
    /**
     * 格式化文件大小
     * 
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
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
     * 使用 WebSitemapData 服务和平台适配器架构生成 sitemap
     * 
     * @param int $websiteId
     * @return array
     */
    private function generateSitemapForWebsite(int $websiteId): array
    {
        try {
            /** @var \Weline\Seo\Service\WebSitemapData $webSitemapData */
            $webSitemapData = ObjectManager::getInstance(\Weline\Seo\Service\WebSitemapData::class);
            
            // 使用适配器架构生成 sitemap 文件
            $result = $webSitemapData->generateSitemapFiles($websiteId);
            
            // 检查是否有错误
            if (isset($result['error'])) {
                return [
                    'website_id' => $websiteId,
                    'files' => [],
                    'platforms' => [],
                    'total_urls' => $result['total_urls'] ?? 0,
                    'error' => $result['error'],
                    'message' => $result['message'],
                ];
            }
            
            // 成功生成
            return [
                'website_id' => $websiteId,
                'platforms' => $result['platforms'] ?? [],
                'total_urls' => $result['total_urls'] ?? 0,
                'total_files' => $result['total_files'] ?? 0,
                'platform_count' => $result['platform_count'] ?? 0,
                'message' => __('成功生成 %{1} 个平台的 sitemap，共 %{2} 个文件', [
                    $result['platform_count'] ?? 0,
                    $result['total_files'] ?? 0
                ]),
            ];
        } catch (\Throwable $e) {
            return [
                'website_id' => $websiteId,
                'files' => [],
                'platforms' => [],
                'message' => __('生成失败：%{1}', $e->getMessage()),
                'error' => true,
            ];
        }
    }
    
    /**
     * 获取站点的URL列表
     * 
     * 通过 WebSitemapData 服务获取站点的活跃 URL
     * 
     * @param int $websiteId
     * @return array
     */
    private function getUrlsForWebsite(int $websiteId): array
    {
        try {
            /** @var \Weline\Seo\Service\WebSitemapData $webSitemapData */
            $webSitemapData = ObjectManager::getInstance(\Weline\Seo\Service\WebSitemapData::class);
            
            // 获取站点的活跃 URL
            $activeUrls = $webSitemapData->getActiveUrls($websiteId);
            
            if (empty($activeUrls)) {
                return [];
            }
            
            // 转换格式为 sitemap 需要的格式
            $urls = [];
            foreach ($activeUrls as $urlData) {
                $urls[] = [
                    'loc' => $urlData['loc'] ?? '',
                    'lastmod' => $urlData['lastmod'] ?? date('Y-m-d'),
                    'changefreq' => $urlData['changefreq'] ?? 'weekly',
                    'priority' => $urlData['priority'] ?? '0.5',
                ];
            }
            
            return $urls;
        } catch (\Throwable $e) {
            // 如果获取失败，返回空数组
            return [];
        }
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
        
        // 获取前台基础 URL（纯域名，不含后台路径）
        $frontendBaseUrl = $this->request->getBaseHost();
        if (!preg_match('/^https?:\/\//i', $frontendBaseUrl)) {
            $scheme = $this->request->isSecure() ? 'https' : 'http';
            $host = $this->request->getHttpHost();
            $frontendBaseUrl = $scheme . '://' . $host;
        }
        $frontendBaseUrl = rtrim($frontendBaseUrl, '/');
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($sitemapFiles as $file) {
            $xml .= "  <sitemap>\n";
            $xml .= "    <loc>" . htmlspecialchars($frontendBaseUrl . '/sitemaps/' . $file['name']) . "</loc>\n";
            $xml .= "    <lastmod>" . htmlspecialchars($file['modified']) . "</lastmod>\n";
            $xml .= "  </sitemap>\n";
        }
        
        $xml .= '</sitemapindex>';
        
        // 确保目录存在
        $sitemapsDir = self::PUB_DIR . '/sitemaps';
        if (!is_dir($sitemapsDir)) {
            mkdir($sitemapsDir, 0755, true);
        }
        
        $filepath = $sitemapsDir . '/sitemap.xml';
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
