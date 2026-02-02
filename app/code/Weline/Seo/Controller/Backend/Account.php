<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Service\SitemapAdapterRegistry;

/**
 * SEO 账户管理后台控制器
 */
#[AclAttribute('Weline_Seo::seo_account', 'SEO账户管理', 'mdi-account-key', 'SEO账户管理', 'Weline_Seo::seo_management')]
class Account extends BackendController
{
    private SitemapAdapterRegistry $adapterRegistry;

    public function __construct(SitemapAdapterRegistry $adapterRegistry)
    {
        $this->adapterRegistry = $adapterRegistry;
    }

    private function getAccountModel(): SeoAccount
    {
        return ObjectManager::getInstance(SeoAccount::class);
    }

    #[AclAttribute('Weline_Seo::seo_account_index', '查看SEO账户列表', 'mdi-view-list', '查看SEO账户列表')]
    public function index(): string
    {
        $scope = trim((string)$this->request->getGet('scope', ''));

        $query = $this->getAccountModel()->reset()->select();

        if ($scope !== '') {
            $query->where(SeoAccount::fields_SCOPE, $scope);
        }

        $query->order(SeoAccount::fields_CREATED_AT, 'DESC');

        $accounts = $query->fetchArray();
        
        // 获取每个账户绑定的站点数量
        /** @var \Weline\Seo\Model\SeoWebsiteAccount $websiteAccountModel */
        $websiteAccountModel = ObjectManager::getInstance(\Weline\Seo\Model\SeoWebsiteAccount::class);
        $bindingCounts = [];
        foreach ($accounts as $account) {
            $accountId = (int)($account['account_id'] ?? 0);
            if ($accountId > 0) {
                $bindings = $websiteAccountModel->reset()
                    ->where(\Weline\Seo\Model\SeoWebsiteAccount::fields_ACCOUNT_ID, $accountId)
                    ->select()
                    ->fetchArray();
                $bindingCounts[$accountId] = count($bindings);
            }
        }
        
        // 获取每个账户的统计数据汇总
        /** @var \Weline\Seo\Model\SeoWebsiteStats $statsModel */
        $statsModel = ObjectManager::getInstance(\Weline\Seo\Model\SeoWebsiteStats::class);
        $accountStats = [];
        foreach ($accounts as $account) {
            $accountId = (int)($account['account_id'] ?? 0);
            if ($accountId > 0) {
                // 获取该账户关联的所有统计数据（取每个站点最新一条汇总）
                $stats = $statsModel->reset()
                    ->where(\Weline\Seo\Model\SeoWebsiteStats::fields_ACCOUNT_ID, $accountId)
                    ->order(\Weline\Seo\Model\SeoWebsiteStats::fields_STATS_DATE, 'DESC')
                    ->select()
                    ->fetchArray();
                
                // 汇总统计数据（去重：每个站点只取最新一条）
                $processedWebsites = [];
                $totalStats = [
                    'indexed_pages' => 0,
                    'submitted_urls' => 0,
                    'clicks' => 0,
                    'impressions' => 0,
                    'error_count' => 0,
                    'last_sync_at' => null,
                ];
                
                foreach ($stats as $stat) {
                    $websiteId = (int)($stat[\Weline\Seo\Model\SeoWebsiteStats::fields_WEBSITE_ID] ?? 0);
                    if ($websiteId > 0 && !in_array($websiteId, $processedWebsites)) {
                        $processedWebsites[] = $websiteId;
                        $totalStats['indexed_pages'] += (int)($stat[\Weline\Seo\Model\SeoWebsiteStats::fields_INDEXED_PAGES] ?? 0);
                        $totalStats['submitted_urls'] += (int)($stat[\Weline\Seo\Model\SeoWebsiteStats::fields_SUBMITTED_URLS] ?? 0);
                        $totalStats['clicks'] += (int)($stat[\Weline\Seo\Model\SeoWebsiteStats::fields_CLICKS] ?? 0);
                        $totalStats['impressions'] += (int)($stat[\Weline\Seo\Model\SeoWebsiteStats::fields_IMPRESSIONS] ?? 0);
                        $totalStats['error_count'] += (int)($stat[\Weline\Seo\Model\SeoWebsiteStats::fields_ERROR_COUNT] ?? 0);
                        
                        // 取最新的同步时间
                        $syncAt = $stat[\Weline\Seo\Model\SeoWebsiteStats::fields_LAST_SYNC_AT] ?? null;
                        if ($syncAt && (!$totalStats['last_sync_at'] || $syncAt > $totalStats['last_sync_at'])) {
                            $totalStats['last_sync_at'] = $syncAt;
                        }
                    }
                }
                
                $accountStats[$accountId] = $totalStats;
            }
        }
        
        // 将绑定数量和统计数据添加到账户数据中
        foreach ($accounts as &$account) {
            $accountId = (int)($account['account_id'] ?? 0);
            $account['bound_websites_count'] = $bindingCounts[$accountId] ?? 0;
            $account['stats'] = $accountStats[$accountId] ?? null;
        }
        unset($account);

        // 检测是否是 AJAX 请求（三方调用）
        $xRequestedWith = (string)($this->request->getHeader('X-Requested-With') ?? '');
        $isAjax = $xRequestedWith === 'XMLHttpRequest';

        $this->assign('accounts', $accounts);
        $this->assign('scope', $scope);
        $this->assign('is_ajax', $isAjax);

        return $this->fetch();
    }

    #[AclAttribute('Weline_Seo::seo_account_form', 'SEO账户表单', 'mdi-form-select', '创建/编辑SEO账户表单')]
    public function form(): string
    {
        $id = (int)$this->request->getGet('id');
        $scope = trim((string)$this->request->getGet('scope', ''));

        $account = $this->getAccountModel()->reset();
        if ($id) {
            $account->load($id);
            if (!$account->getId()) {
                Message::error(__('账户不存在'));
                $this->redirect('seo/backend/account/index');
                return '';
            }
        } else {
            if ($scope !== '') {
                $account->setData(SeoAccount::fields_SCOPE, $scope);
            }
        }

        // 检测是否是轻量级模式（不需要完整后端布局）
        // 1. AJAX 请求
        // 2. 有 scope 参数（说明是从特定模块调用，应该轻量级显示）
        // 3. 有 lightweight=1 参数
        $xRequestedWith = (string)($this->request->getHeader('X-Requested-With') ?? '');
        $isAjax = $xRequestedWith === 'XMLHttpRequest' 
                  || $this->request->isAjax()
                  || $scope !== ''  // 从特定模块调用
                  || $this->request->getGet('lightweight') === '1';

        // 获取所有可用的平台适配器
        $platforms = $this->adapterRegistry->getPlatformInfo();
        
        $this->assign('account', $account);
        $this->assign('scope', $scope);
        $this->assign('is_ajax', $isAjax);
        $this->assign('platforms', $platforms);

        return $this->fetch();
    }

    #[AclAttribute('Weline_Seo::seo_account_save', '保存SEO账户', 'mdi-content-save', '保存SEO账户')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $data = $this->request->getPost();

        try {
            $account = $this->getAccountModel()->reset();
            if ($id) {
                $account->load($id);
                if (!$account->getId()) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('账户不存在'),
                    ]);
                }
            }

            $name = trim((string)($data['name'] ?? ''));
            $platform = trim((string)($data['platform'] ?? ''));

            if ($name === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('账户名称不能为空'),
                ]);
            }
            if ($platform === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('平台不能为空'),
                ]);
            }

            $scope = trim((string)($data['scope'] ?? ''));

            $account->setData(SeoAccount::fields_NAME, $name)
                ->setData(SeoAccount::fields_PLATFORM, $platform)
                ->setData(SeoAccount::fields_PROVIDER, $platform) // 向后兼容
                ->setData(SeoAccount::fields_SCOPE, $scope)
                ->setData(SeoAccount::fields_DESCRIPTION, (string)($data['description'] ?? ''))
                ->setData(SeoAccount::fields_IS_ACTIVE, (int)($data['is_active'] ?? SeoAccount::STATUS_ACTIVE))
                ->setData(SeoAccount::fields_ENABLE_CRON_PUSH_URLS, (int)($data['enable_cron_push_urls'] ?? 1))
                ->setData(SeoAccount::fields_ENABLE_CRON_SITEMAP, (int)($data['enable_cron_sitemap'] ?? 0));

            $configJson = (string)($data['config_json'] ?? '');
            if ($configJson !== '') {
                $decoded = json_decode($configJson, true);
                $config = is_array($decoded) ? $decoded : [];
                $account->setConfigArray($config);
            }

            $account->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('账户保存成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('账户保存失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    #[AclAttribute('Weline_Seo::seo_account_websites', '获取站点列表', 'mdi-web', '获取账户可绑定的站点列表')]
    public function websites(): string
    {
        $accountId = (int)$this->request->getGet('account_id');
        
        if ($accountId <= 0) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('账户ID无效'),
            ]);
        }
        
        try {
            // 获取所有站点
            /** @var \Weline\Websites\Model\Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $websites = $websiteModel->reset()->select()->fetchArray();
            
            // 格式化站点数据
            $formattedWebsites = [];
            foreach ($websites as $website) {
                $formattedWebsites[] = [
                    'website_id' => (int)($website['website_id'] ?? 0),
                    'name' => (string)($website['name'] ?? ''),
                    'domain' => (string)($website['domain'] ?? ''),
                    'is_default' => (int)($website['is_default'] ?? 0) === 1,
                ];
            }
            
            // 获取已绑定的站点ID
            /** @var \Weline\Seo\Model\SeoWebsiteAccount $websiteAccountModel */
            $websiteAccountModel = ObjectManager::getInstance(\Weline\Seo\Model\SeoWebsiteAccount::class);
            $bindings = $websiteAccountModel->reset()
                ->where(\Weline\Seo\Model\SeoWebsiteAccount::fields_ACCOUNT_ID, $accountId)
                ->select()
                ->fetchArray();
            
            $boundWebsiteIds = [];
            foreach ($bindings as $binding) {
                $boundWebsiteIds[] = (int)($binding[\Weline\Seo\Model\SeoWebsiteAccount::fields_WEBSITE_ID] ?? 0);
            }
            
            // 获取每个绑定网站的配置信息
            $configs = [];
            foreach ($bindings as $binding) {
                $websiteId = (int)($binding[\Weline\Seo\Model\SeoWebsiteAccount::fields_WEBSITE_ID] ?? 0);
                if ($websiteId > 0) {
                    $configs[$websiteId] = [
                        'sitemap_frequency' => $binding[\Weline\Seo\Model\SeoWebsiteAccount::fields_SITEMAP_FREQUENCY] ?? 'daily',
                        'crawl_frequency' => $binding[\Weline\Seo\Model\SeoWebsiteAccount::fields_CRAWL_FREQUENCY] ?? 'weekly',
                        'priority' => $binding[\Weline\Seo\Model\SeoWebsiteAccount::fields_PRIORITY] ?? '0.50',
                        'is_auto_submit' => $binding[\Weline\Seo\Model\SeoWebsiteAccount::fields_IS_AUTO_SUBMIT] ?? 1,
                    ];
                }
            }
            
            return $this->jsonResponse([
                'success' => true,
                'websites' => $formattedWebsites,
                'bound_website_ids' => $boundWebsiteIds,
                'configs' => $configs,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('获取站点列表失败：%{1}', $e->getMessage()),
            ]);
        }
    }
    
    #[AclAttribute('Weline_Seo::seo_account_bind_websites', '绑定站点', 'mdi-link-variant', '保存账户与站点的绑定关系')]
    public function saveWebsiteBindings(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }
        
        try {
            // 读取 JSON 数据 - 尝试多种方式
            $body = $this->request->getBody();
            if (empty($body)) {
                // 如果 getBody() 返回空，直接读取 php://input
                $body = file_get_contents('php://input');
            }
            
            if (empty($body)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('未接收到请求数据'),
                ]);
            }
            
            $data = json_decode($body, true);
            
            if (!is_array($data)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('无效的请求数据格式'),
                ]);
            }
            
            $accountId = (int)($data['account_id'] ?? 0);
            $websiteIds = $data['website_ids'] ?? [];
            
            if ($accountId <= 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('账户ID无效'),
                ]);
            }
            
            // 确保 website_ids 是整数数组
            if (!is_array($websiteIds)) {
                $websiteIds = [];
            }
            $websiteIds = array_map('intval', $websiteIds);
            $websiteIds = array_filter($websiteIds, fn($id) => $id > 0);
            
            /** @var \Weline\Seo\Model\SeoWebsiteAccount $websiteAccountModel */
            $websiteAccountModel = ObjectManager::getInstance(\Weline\Seo\Model\SeoWebsiteAccount::class);
            
            // 获取当前已绑定的站点
            $existingBindings = $websiteAccountModel->reset()
                ->where(\Weline\Seo\Model\SeoWebsiteAccount::fields_ACCOUNT_ID, $accountId)
                ->select()
                ->fetchArray();
            
            $existingWebsiteIds = [];
            foreach ($existingBindings as $binding) {
                $existingWebsiteIds[] = (int)($binding[\Weline\Seo\Model\SeoWebsiteAccount::fields_WEBSITE_ID] ?? 0);
            }
            
            // 找出需要删除的绑定（在旧列表中但不在新列表中）
            $toDelete = array_diff($existingWebsiteIds, $websiteIds);
            $deletedCount = 0;
            foreach ($toDelete as $websiteId) {
                if ($websiteAccountModel->unbindWebsiteAccount($websiteId, $accountId)) {
                    $deletedCount++;
                }
            }
            
            // 找出需要新增的绑定（在新列表中但不在旧列表中）
            $toAdd = array_diff($websiteIds, $existingWebsiteIds);
            $addedCount = 0;
            foreach ($toAdd as $websiteId) {
                $websiteAccountModel->bindWebsiteAccount($websiteId, $accountId);
                $addedCount++;
            }
            
            // 根据操作结果生成消息
            $message = '';
            if ($addedCount > 0 && $deletedCount > 0) {
                $message = __('绑定了 %{1} 个站点，解绑了 %{2} 个站点', [$addedCount, $deletedCount]);
            } elseif ($addedCount > 0) {
                $message = __('成功绑定 %{1} 个站点', $addedCount);
            } elseif ($deletedCount > 0) {
                $message = __('成功解绑 %{1} 个站点', $deletedCount);
            } else {
                $message = __('站点绑定未发生变化');
            }
            
            return $this->jsonResponse([
                'success' => true,
                'message' => $message,
                'added' => $addedCount,
                'deleted' => $deletedCount,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    /**
     * 保存单个网站的Sitemap配置
     */
    #[AclAttribute('Weline_Seo::seo_account_save_website_config', '保存站点配置', 'mdi-cog', '保存单个站点的Sitemap配置')]
    public function saveWebsiteConfig(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }
        
        try {
            $body = $this->request->getBody();
            if (empty($body)) {
                $body = file_get_contents('php://input');
            }
            
            if (empty($body)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('未接收到请求数据'),
                ]);
            }
            
            $data = json_decode($body, true);
            
            if (!is_array($data)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('无效的请求数据格式'),
                ]);
            }
            
            $accountId = (int)($data['account_id'] ?? 0);
            $websiteId = (int)($data['website_id'] ?? 0);
            $config = $data['config'] ?? [];
            
            if ($accountId <= 0 || $websiteId <= 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('账户ID或站点ID无效'),
                ]);
            }
            
            /** @var \Weline\Seo\Model\SeoWebsiteAccount $websiteAccountModel */
            $websiteAccountModel = ObjectManager::getInstance(\Weline\Seo\Model\SeoWebsiteAccount::class);
            
            // 查找现有绑定
            $binding = $websiteAccountModel->reset()
                ->where(\Weline\Seo\Model\SeoWebsiteAccount::fields_ACCOUNT_ID, $accountId)
                ->where(\Weline\Seo\Model\SeoWebsiteAccount::fields_WEBSITE_ID, $websiteId)
                ->find()
                ->fetch();
            
            if (!$binding) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('该账户未绑定此站点'),
                ]);
            }
            
            // 更新配置
            $binding->setData([
                \Weline\Seo\Model\SeoWebsiteAccount::fields_SITEMAP_FREQUENCY => $config['sitemap_frequency'] ?? 'daily',
                \Weline\Seo\Model\SeoWebsiteAccount::fields_CRAWL_FREQUENCY => $config['crawl_frequency'] ?? 'weekly',
                \Weline\Seo\Model\SeoWebsiteAccount::fields_PRIORITY => $config['priority'] ?? '0.50',
                \Weline\Seo\Model\SeoWebsiteAccount::fields_IS_AUTO_SUBMIT => (int)($config['is_auto_submit'] ?? 1),
            ])->save();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('配置保存成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage()),
            ]);
        }
    }
    
    /**
     * 从账户解绑单个网站
     */
    #[AclAttribute('Weline_Seo::seo_account_unbind_website', '解绑站点', 'mdi-link-off', '从账户解绑单个站点')]
    public function unbindWebsite(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }
        
        try {
            $body = $this->request->getBody();
            if (empty($body)) {
                $body = file_get_contents('php://input');
            }
            
            if (empty($body)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('未接收到请求数据'),
                ]);
            }
            
            $data = json_decode($body, true);
            
            if (!is_array($data)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('无效的请求数据格式'),
                ]);
            }
            
            $accountId = (int)($data['account_id'] ?? 0);
            $websiteId = (int)($data['website_id'] ?? 0);
            
            if ($accountId <= 0 || $websiteId <= 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('账户ID或站点ID无效'),
                ]);
            }
            
            /** @var \Weline\Seo\Model\SeoWebsiteAccount $websiteAccountModel */
            $websiteAccountModel = ObjectManager::getInstance(\Weline\Seo\Model\SeoWebsiteAccount::class);
            
            // 先查询绑定是否存在
            $websiteAccountModel->reset()
                ->where(\Weline\Seo\Model\SeoWebsiteAccount::fields_WEBSITE_ID, $websiteId)
                ->where(\Weline\Seo\Model\SeoWebsiteAccount::fields_ACCOUNT_ID, $accountId)
                ->find()
                ->fetch();
            
            $bindingId = $websiteAccountModel->getId();
            
            if (!$bindingId) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('未找到该绑定关系 (website_id=%1, account_id=%2)', $websiteId, $accountId),
                ]);
            }
            
            // 执行删除
            $websiteAccountModel->delete();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('解绑成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('解绑失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    /**
     * JSON 响应工具
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 手动同步账户统计数据
     */
    #[AclAttribute('Weline_Seo::seo_account_sync_stats', '同步统计数据', 'mdi-sync', '手动同步账户统计数据')]
    public function syncStats(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }
        
        $accountId = (int)$this->request->getPost('account_id', 0);
        
        if ($accountId <= 0) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('账户ID无效'),
            ]);
        }
        
        try {
            // 加载账户
            $account = $this->getAccountModel()->reset()->load($accountId);
            if (!$account->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('账户不存在'),
                ]);
            }
            
            $platform = $account->getPlatform();
            if (empty($platform)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('账户未配置平台'),
                ]);
            }
            
            // 获取适配器
            $adapter = $this->adapterRegistry->getAdapter($platform);
            if (!$adapter || !$adapter->supportsStats()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('该平台不支持统计数据获取'),
                ]);
            }
            
            // 获取绑定的站点
            /** @var \Weline\Seo\Model\SeoWebsiteAccount $websiteAccountModel */
            $websiteAccountModel = ObjectManager::getInstance(\Weline\Seo\Model\SeoWebsiteAccount::class);
            $bindings = $websiteAccountModel->reset()
                ->where(\Weline\Seo\Model\SeoWebsiteAccount::fields_ACCOUNT_ID, $accountId)
                ->select()
                ->fetchArray();
            
            if (empty($bindings)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('该账户没有绑定任何站点'),
                ]);
            }
            
            /** @var \Weline\Websites\Model\Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            
            /** @var \Weline\Seo\Model\SeoWebsiteStats $statsModel */
            $statsModel = ObjectManager::getInstance(\Weline\Seo\Model\SeoWebsiteStats::class);
            
            $accountConfig = ['config' => $account->getConfigArray()];
            $syncedCount = 0;
            $errors = [];
            
            foreach ($bindings as $binding) {
                $websiteId = (int)($binding[\Weline\Seo\Model\SeoWebsiteAccount::fields_WEBSITE_ID] ?? 0);
                if ($websiteId <= 0) {
                    continue;
                }
                
                $website = $websiteModel->reset()->load($websiteId);
                if (!$website->getId()) {
                    continue;
                }
                
                $siteUrl = $website->getData('url') ?: '';
                if (empty($siteUrl)) {
                    continue;
                }
                
                $result = $adapter->getStats($siteUrl, $accountConfig);
                
                if ($result['success'] && !empty($result['data'])) {
                    $statsRecord = $statsModel->reset();
                    $statsRecord->getOrCreateTodayStats($websiteId, $accountId, $platform);
                    $statsRecord->updateStats($result['data']);
                    $syncedCount++;
                } else {
                    $errors[] = $website->getData('name') . ': ' . ($result['message'] ?? __('未知错误'));
                }
            }
            
            if ($syncedCount > 0) {
                $message = __('成功同步 %{1} 个站点的统计数据', $syncedCount);
                if (!empty($errors)) {
                    $message .= '，' . count($errors) . ' 个失败';
                }
                return $this->jsonResponse([
                    'success' => true,
                    'message' => $message,
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('同步失败：%{1}', implode('; ', $errors)),
                ]);
            }
            
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('同步失败：%{1}', $e->getMessage()),
            ]);
        }
    }
}

