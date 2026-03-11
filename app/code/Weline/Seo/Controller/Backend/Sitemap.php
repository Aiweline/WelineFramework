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
#[Acl('Weline_Seo::sitemap_management', 'Sitemap管理', 'mdi-sitemap', 'Sitemap管理', 'Weline_Backend::seo_group')]
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
            
            // 获取所有注册的 SitemapProvider
            $providers = $this->getRegisteredProviders();
            
            // 获取 pub/sitemaps 目录下各模块生成的 sitemap（包含错误信息）
            $moduleSitemaps = $this->getModuleSitemapsWithStatus($websites);
            
            $this->assign('websites', $websites);
            $this->assign('providers', $providers);
            $this->assign('module_sitemaps', $moduleSitemaps);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载Sitemap管理失败：%{1}', $e->getMessage()));
            $this->assign('websites', []);
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
            // 获取 POST 数据，支持多种格式
            $postData = $this->getPostData();
            
            $websiteId = (int)($postData['website_id'] ?? 0);
            $generateAll = $this->toBool($postData['generate_all'] ?? false);
            $useProviders = $this->toBool($postData['use_providers'] ?? false);
            $providerModule = (string)($postData['provider_module'] ?? '');
            
            // 使用注册的 Provider 生成
            if ($useProviders) {
                $result = $this->generateByProviders($providerModule);
                $message = $this->buildDetailedSummary($result);
                return $this->jsonResponse(true, $message, $result);
            }
            
            if ($generateAll || $websiteId <= 0) {
                // 全站生成（包括未选择具体站点的情况）
                $result = $this->generateAllSitemaps();
                $message = $this->buildAllSitesSummary($result);
            } else {
                // 生成指定站点Sitemap
                $result = $this->generateSitemapForWebsite($websiteId);
                $message = $this->buildSingleSiteSummary($result);
            }
            
            return $this->jsonResponse(true, $message, $result);
            
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
        
        // 获取 SEO 账户信息服务
        /** @var \Weline\Seo\Service\WebSitemapData $webSitemapData */
        $webSitemapData = ObjectManager::getInstance(\Weline\Seo\Service\WebSitemapData::class);
        
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
            
            // 获取站点绑定的 SEO 账户信息（按平台分组）
            $websiteAccountsInfo = [];
            try {
                $accountsData = $webSitemapData->getWebsiteAccountsWithPlatforms($websiteInfo['website_id']);
                foreach ($accountsData as $accInfo) {
                    $pCode = $accInfo['platform_code'] ?? '';
                    if ($pCode) {
                        $account = $accInfo['account'];
                        $binding = $accInfo['binding'] ?? [];
                        $websiteAccountsInfo[$pCode] = [
                            'account_id' => $account->getId(),
                            'account_name' => $account->getData('name') ?: $account->getData('provider'),
                            'is_active' => $account->isActive(),
                            'is_auto_submit' => (int)($binding[\Weline\Seo\Model\SeoWebsiteAccount::schema_fields_IS_AUTO_SUBMIT] ?? 0) === 1,
                            'sitemap_frequency' => $binding[\Weline\Seo\Model\SeoWebsiteAccount::schema_fields_SITEMAP_FREQUENCY] ?? 'daily',
                            'enable_cron_push' => (int)$account->getData('enable_cron_push_urls') === 1,
                            'enable_cron_sitemap' => (int)$account->getData('enable_cron_sitemap') === 1,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // 忽略获取账户信息失败
            }
            
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
                        // SEO 账户信息
                        'seo_account' => $websiteAccountsInfo[$platformCode] ?? null,
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
    
    /**
     * 获取 POST 数据，支持多种格式
     * 
     * 支持：
     * - 标准 $_POST（application/x-www-form-urlencoded 和 multipart/form-data）
     * - JSON 请求体（application/json）
     * - 原始 URL 编码数据（用于某些 CLI 工具）
     * 
     * @return array
     */
    private function getPostData(): array
    {
        // 优先使用 $_POST
        if (!empty($_POST)) {
            return $_POST;
        }
        
        // 尝试从原始输入解析
        $rawBodyParams = $this->request->getBodyParams();
        $rawInput = is_string($rawBodyParams) ? $rawBodyParams : (is_array($rawBodyParams) ? json_encode($rawBodyParams) : '');
        if (empty($rawInput)) {
            return [];
        }
        
        // 尝试解析为 JSON
        $jsonData = json_decode($rawInput, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
            return $jsonData;
        }
        
        // 尝试解析为 URL 编码数据
        $parsed = [];
        parse_str($rawInput, $parsed);
        if (!empty($parsed)) {
            return $parsed;
        }
        
        return [];
    }
    
    /**
     * 将值转换为布尔值
     * 
     * @param mixed $value
     * @return bool
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        return (bool)$value;
    }

    /**
     * 构建 Provider 生成模式的详细摘要（HTML）
     *
     * @param array $result generateByProviders() 返回的结果
     * @return string
     */
    private function buildDetailedSummary(array $result): string
    {
        $totalUrlsSynced = $result['total_urls_synced'] ?? 0;
        $totalFilesGenerated = $result['total_files_generated'] ?? 0;
        $providerResults = $result['provider_results'] ?? [];
        $sitemapResults = $result['sitemap_results'] ?? [];

        // 概览
        $siteCount = count($sitemapResults);
        $providerCount = count($providerResults);
        $html = '<div style="text-align:left;">';
        $html .= '<p><strong>' . __('概览') . '</strong></p>';
        $html .= '<ul style="margin:0;padding-left:1.2em;">';
        $html .= '<li>' . __('同步 URL 总数：%{1}', $totalUrlsSynced) . '</li>';
        $html .= '<li>' . __('生成站点数：%{1}', $siteCount) . '</li>';
        $html .= '<li>' . __('生成文件总数：%{1}', $totalFilesGenerated) . '</li>';
        $html .= '<li>' . __('URL Provider 数：%{1}', $providerCount) . '</li>';
        $html .= '</ul>';

        // Provider 明细
        if (!empty($providerResults)) {
            $html .= '<hr style="margin:8px 0;"><p><strong>' . __('Provider 同步明细') . '</strong></p>';
            $html .= '<ul style="margin:0;padding-left:1.2em;">';
            foreach ($providerResults as $pr) {
                $icon = ($pr['success'] ?? false) ? '&#9989;' : '&#10060;';
                $module = $pr['module'] ?? __('未知');
                $count = $pr['url_count'] ?? 0;
                $desc = $pr['description'] ?? '';
                $line = $icon . ' ' . $module . ($desc ? " ({$desc})" : '') . ' — ' . __('%{1} 个URL', $count);
                if (!($pr['success'] ?? false) && !empty($pr['error'])) {
                    $line .= ' <span style="color:#dc3545;">(' . htmlspecialchars($pr['error']) . ')</span>';
                }
                $html .= '<li>' . $line . '</li>';
            }
            $html .= '</ul>';
        }

        // 各站点生成明细
        if (!empty($sitemapResults)) {
            $html .= '<hr style="margin:8px 0;"><p><strong>' . __('各站点生成明细') . '</strong></p>';
            $html .= '<ul style="margin:0;padding-left:1.2em;">';
            foreach ($sitemapResults as $sr) {
                $wid = $sr['website_id'] ?? '?';
                $urls = $sr['total_urls'] ?? 0;
                $files = $sr['total_files'] ?? 0;
                $platforms = $sr['platform_count'] ?? 0;
                $hasError = !empty($sr['error']);
                if ($hasError) {
                    $html .= '<li>&#10060; ' . __('站点 #%{1}：%{2}', [$wid, $sr['message'] ?? __('生成失败')]) . '</li>';
                } else {
                    $html .= '<li>&#9989; ' . __('站点 #%{1}：%{2} 个URL，%{3} 个平台，%{4} 个文件', [$wid, $urls, $platforms, $files]) . '</li>';
                }
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * 构建全站生成模式的摘要（HTML）
     *
     * @param array $results generateAllSitemaps() 返回的结果数组
     * @return string
     */
    private function buildAllSitesSummary(array $results): string
    {
        $siteCount = count($results);
        $totalUrls = 0;
        $totalFiles = 0;
        $successCount = 0;
        $failCount = 0;

        foreach ($results as $r) {
            if (!empty($r['error'])) {
                $failCount++;
            } else {
                $successCount++;
                $totalUrls += $r['total_urls'] ?? 0;
                $totalFiles += $r['total_files'] ?? 0;
            }
        }

        $html = '<div style="text-align:left;">';
        $html .= '<p><strong>' . __('概览') . '</strong></p>';
        $html .= '<ul style="margin:0;padding-left:1.2em;">';
        $html .= '<li>' . __('处理站点数：%{1}', $siteCount) . '</li>';
        $html .= '<li>' . __('成功：%{1}，失败：%{2}', [$successCount, $failCount]) . '</li>';
        $html .= '<li>' . __('URL 总数：%{1}', $totalUrls) . '</li>';
        $html .= '<li>' . __('生成文件总数：%{1}', $totalFiles) . '</li>';
        $html .= '</ul>';

        // 各站点明细
        if (!empty($results)) {
            $html .= '<hr style="margin:8px 0;"><p><strong>' . __('各站点明细') . '</strong></p>';
            $html .= '<ul style="margin:0;padding-left:1.2em;">';
            foreach ($results as $r) {
                $wid = $r['website_id'] ?? '?';
                if (!empty($r['error'])) {
                    $html .= '<li>&#10060; ' . __('站点 #%{1}：%{2}', [$wid, $r['message'] ?? __('生成失败')]) . '</li>';
                } else {
                    $urls = $r['total_urls'] ?? 0;
                    $files = $r['total_files'] ?? 0;
                    $platforms = $r['platform_count'] ?? 0;
                    $html .= '<li>&#9989; ' . __('站点 #%{1}：%{2} 个URL，%{3} 个平台，%{4} 个文件', [$wid, $urls, $platforms, $files]) . '</li>';
                }
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * 构建单站点生成模式的摘要（HTML）
     *
     * @param array $result generateSitemapForWebsite() 返回的结果
     * @return string
     */
    private function buildSingleSiteSummary(array $result): string
    {
        $wid = $result['website_id'] ?? '?';
        $urls = $result['total_urls'] ?? 0;
        $files = $result['total_files'] ?? 0;
        $platforms = $result['platform_count'] ?? 0;
        $hasError = !empty($result['error']);

        if ($hasError) {
            return __('站点 #%{1} 生成失败：%{2}', [$wid, $result['message'] ?? __('未知错误')]);
        }

        $html = '<div style="text-align:left;">';
        $html .= '<p><strong>' . __('站点 #%{1} 生成完毕', $wid) . '</strong></p>';
        $html .= '<ul style="margin:0;padding-left:1.2em;">';
        $html .= '<li>' . __('URL 数量：%{1}', $urls) . '</li>';
        $html .= '<li>' . __('平台数量：%{1}', $platforms) . '</li>';
        $html .= '<li>' . __('生成文件数：%{1}', $files) . '</li>';
        $html .= '</ul>';

        // 平台明细
        if (!empty($result['platforms'])) {
            $html .= '<hr style="margin:8px 0;"><p><strong>' . __('平台明细') . '</strong></p>';
            $html .= '<ul style="margin:0;padding-left:1.2em;">';
            foreach ($result['platforms'] as $platform => $info) {
                $pFiles = is_array($info) ? ($info['file_count'] ?? count($info['files'] ?? [])) : 0;
                $html .= '<li>' . htmlspecialchars($platform) . ' — ' . __('%{1} 个文件', $pFiles) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
        return $html;
    }
}
