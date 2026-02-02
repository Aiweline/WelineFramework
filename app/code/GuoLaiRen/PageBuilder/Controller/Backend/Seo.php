<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Websites\Model\Website;
use Weline\Seo\Service\SitemapRegistryService;

/**
 * PageBuilder SEO 综合管理控制器
 * 
 * 整合SEO账户、站点关联、SEO主体管理
 * 
 * @package GuoLaiRen_PageBuilder
 */
#[Acl('GuoLaiRen_PageBuilder::seo_management', 'PageBuilder SEO管理', 'mdi-chart-line', 'PageBuilder SEO管理', 'GuoLaiRen_PageBuilder::menu_page_management')]
class Seo extends BaseController
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * SEO 综合管理首页
     * 
     * @return string
     */
    #[Acl('GuoLaiRen_PageBuilder::seo_management_index', '查看PageBuilder SEO管理', 'mdi-chart-line', '查看PageBuilder SEO管理')]
    public function index(): string
    {
        $module = 'GuoLaiRen_PageBuilder';
        $scope = 'page_builder';

        // 获取分页和搜索参数
        $accountPage = (int)$this->request->getGet('account_page', 1);
        $accountPageSize = 10;
        $accountSearch = trim((string)$this->request->getGet('account_search', ''));
        
        $websitePage = (int)$this->request->getGet('website_page', 1);
        $websitePageSize = 10;
        $websiteSearch = trim((string)$this->request->getGet('website_search', ''));

        // 获取SEO账户列表（按module/scope过滤+分页）
        /** @var SeoAccount $accountModel */
        $accountModel = $this->objectManager->getInstance(SeoAccount::class);
        
        // 方式1: 查询当前module/scope的账户
        $accountQuery1 = $accountModel->reset()->select();
        $accountQuery1->where(SeoAccount::fields_MODULE, $module)
                      ->where(SeoAccount::fields_SCOPE, $scope);
        if ($accountSearch !== '') {
            $accountQuery1->where('name', '%' . $accountSearch . '%', 'LIKE');
        }
        $accounts1 = $accountQuery1->fetchArray();
        
        // 方式2: 查询空module和空scope的通用账户
        $accountQuery2 = $accountModel->reset()->select();
        $accountQuery2->where(SeoAccount::fields_MODULE, '')
                      ->where(SeoAccount::fields_SCOPE, '');
        if ($accountSearch !== '') {
            $accountQuery2->where('name', '%' . $accountSearch . '%', 'LIKE');
        }
        $accounts2 = $accountQuery2->fetchArray();
        
        // 合并并去重
        $accountsMap = [];
        foreach (array_merge($accounts1, $accounts2) as $account) {
            $accountsMap[(int)$account['account_id']] = $account;
        }
        
        // 转换为普通数组并按创建时间排序
        $accountsList = array_values($accountsMap);
        usort($accountsList, function($a, $b) {
            return strtotime($b['created_at'] ?? '') <=> strtotime($a['created_at'] ?? '');
        });
        
        // 统计总数
        $accountTotal = count($accountsList);
        
        // 手动分页
        $accounts = array_slice($accountsList, ($accountPage - 1) * $accountPageSize, $accountPageSize);

        // 获取站点列表（分页+搜索）
        /** @var Website $websiteModel */
        $websiteModel = $this->objectManager->getInstance(Website::class);
        
        if ($websiteSearch !== '') {
            // 如果有搜索条件，分别查询name和code，然后合并
            $websiteQuery1 = $websiteModel->reset()->select();
            $websiteQuery1->where('name', '%' . $websiteSearch . '%', 'LIKE');
            $websites1 = $websiteQuery1->fetchArray();
            
            $websiteQuery2 = $websiteModel->reset()->select();
            $websiteQuery2->where('code', '%' . $websiteSearch . '%', 'LIKE');
            $websites2 = $websiteQuery2->fetchArray();
            
            // 合并并去重
            $websitesMap = [];
            foreach (array_merge($websites1, $websites2) as $website) {
                $websitesMap[(int)$website['website_id']] = $website;
            }
            
            // 按ID排序（保持关联数组）
            ksort($websitesMap);
            
            // 转换为普通数组用于分页
            $websitesList = array_values($websitesMap);
            
            $websiteTotal = count($websitesList);
            $websites = array_slice($websitesList, ($websitePage - 1) * $websitePageSize, $websitePageSize);
        } else {
            // 没有搜索条件，直接分页查询
            // 先统计总数
            $websiteTotal = $websiteModel->reset()->select()->count();
            
            // 再查询分页数据（使用新的查询对象）
            $websites = $websiteModel->reset()
                ->select()
                ->limit($websitePageSize, ($websitePage - 1) * $websitePageSize)
                ->order(Website::fields_ID, 'ASC')
                ->fetchArray();
        }

        // 获取站点-SEO账户关联关系
        /** @var SeoWebsiteAccount $websiteAccountModel */
        $websiteAccountModel = $this->objectManager->getInstance(SeoWebsiteAccount::class);
        $websiteAccountBindings = $websiteAccountModel->reset()
            ->select()
            ->fetchArray();

        // 重组为 website_id => [account_data, ...]
        $websiteBindingsMap = [];
        $boundAccountIds = []; // 收集所有已绑定的账户ID
        foreach ($websiteAccountBindings as $binding) {
            $websiteId = (int)$binding['website_id'];
            $accountId = (int)$binding['account_id'];
            if (!isset($websiteBindingsMap[$websiteId])) {
                $websiteBindingsMap[$websiteId] = [];
            }
            $websiteBindingsMap[$websiteId][] = $binding;
            $boundAccountIds[$accountId] = true; // 记录已绑定的账户ID
        }
        
        // 查询所有已绑定的账户信息（用于显示，不分页）
        $boundAccounts = [];
        if (!empty($boundAccountIds)) {
            $boundAccountIdsList = array_keys($boundAccountIds);
            $boundAccountsQuery = $accountModel->reset()->select();
            $boundAccountsQuery->where('account_id', $boundAccountIdsList, 'IN');
            $boundAccountsResult = $boundAccountsQuery->fetchArray();
            
            // 重组为 account_id => account_data
            foreach ($boundAccountsResult as $account) {
                $boundAccounts[(int)$account['account_id']] = $account;
            }
        }

        $this->assign('title', __('PageBuilder SEO管理'));
        $this->assign('module', $module);
        $this->assign('scope', $scope);
        
        // 账户分页数据
        $this->assign('accounts', $accounts);
        $this->assign('accountPage', $accountPage);
        $this->assign('accountPageSize', $accountPageSize);
        $this->assign('accountTotal', $accountTotal);
        $this->assign('accountTotalPages', ceil($accountTotal / $accountPageSize));
        $this->assign('accountSearch', $accountSearch);
        
        // 站点分页数据
        $this->assign('websites', $websites);
        $this->assign('websitePage', $websitePage);
        $this->assign('websitePageSize', $websitePageSize);
        $this->assign('websiteTotal', $websiteTotal);
        $this->assign('websiteTotalPages', ceil($websiteTotal / $websitePageSize));
        $this->assign('websiteSearch', $websiteSearch);
        
        $this->assign('websiteBindingsMap', $websiteBindingsMap);
        $this->assign('websiteAccountBindings', $websiteAccountBindings);
        $this->assign('boundAccounts', $boundAccounts);
        
        // Sitemap 数据
        $sitemapData = $this->getSitemapData($websites);
        $this->assign('sitemap_index_file', $sitemapData['index_file']);
        $this->assign('sitemap_providers', $sitemapData['providers']);
        $this->assign('sitemap_module_sitemaps', $sitemapData['module_sitemaps']);

        return $this->fetch();
    }
    
    /**
     * 获取 Sitemap 数据
     * 
     * @param array $websites
     * @return array
     */
    private function getSitemapData(array $websites): array
    {
        try {
            // 获取主 sitemap 索引文件
            $indexFile = $this->getIndexSitemapFile();
            
            // 获取所有注册的 SitemapProvider
            $providers = $this->getRegisteredProviders();
            
            // 获取 pub/sitemaps 目录下各模块生成的 sitemap（包含错误信息）
            $moduleSitemaps = $this->getModuleSitemapsWithStatus($websites);
            
            return [
                'index_file' => $indexFile,
                'providers' => $providers,
                'module_sitemaps' => $moduleSitemaps,
            ];
        } catch (\Throwable $e) {
            return [
                'index_file' => null,
                'providers' => [],
                'module_sitemaps' => [],
            ];
        }
    }
    
    /**
     * 获取主 sitemap 索引文件信息
     * 
     * @return array|null
     */
    private function getIndexSitemapFile(): ?array
    {
        $indexFile = BP . '/pub/sitemaps/sitemap.xml';
        
        if (!file_exists($indexFile)) {
            return null;
        }
        
        // 获取前台基础 URL
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
            'url' => '/sitemaps/sitemap.xml',
            'full_url' => $frontendBaseUrl . '/sitemaps/sitemap.xml',
        ];
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
            $registry = $this->objectManager->getInstance(SitemapRegistryService::class);
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
     * @param array $websites
     * @return array
     */
    private function getModuleSitemapsWithStatus(array $websites): array
    {
        $result = $this->getModuleSitemaps($websites);
        
        /** @var \Weline\Seo\Service\WebSitemapData $webSitemapData */
        $webSitemapData = $this->objectManager->getInstance(\Weline\Seo\Service\WebSitemapData::class);
        
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
     * @param array $websites
     * @return array
     */
    private function getModuleSitemaps(array $websites): array
    {
        $result = [];
        $sitemapsDir = BP . '/pub/sitemaps';
        
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
        $webSitemapData = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Seo\Service\WebSitemapData::class);
        
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
                            'is_auto_submit' => (int)($binding[\Weline\Seo\Model\SeoWebsiteAccount::fields_IS_AUTO_SUBMIT] ?? 0) === 1,
                            'sitemap_frequency' => $binding[\Weline\Seo\Model\SeoWebsiteAccount::fields_SITEMAP_FREQUENCY] ?? 'daily',
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
}
