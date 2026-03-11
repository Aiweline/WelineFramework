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
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Websites\Model\Website;

/**
 * 站点-SEO账户关联管理控制器
 */
#[AclAttribute('Weline_Seo::website_account', '站点SEO账户关联', 'mdi-link-variant', '管理站点与SEO账户的关联关系', 'Weline_Backend::seo_group')]
class WebsiteAccount extends BackendController
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 站点-SEO账户绑定列表（入口页）
     *
     * @return string
     */
    #[AclAttribute('Weline_Seo::website_account_index', '查看站点账户绑定', 'mdi-view-list', '查看站点与SEO账户的绑定列表')]
    public function index(): string
    {
        try {
            /** @var Website $websiteModel */
            $websiteModel = $this->objectManager->getInstance(Website::class);
            $websites = $websiteModel->reset()->select()->fetchArray();

            /** @var SeoWebsiteAccount $websiteAccountModel */
            $websiteAccountModel = $this->objectManager->getInstance(SeoWebsiteAccount::class);

            $bindingCounts = [];
            foreach ($websites as $website) {
                $websiteId = (int)($website['website_id'] ?? $website['id'] ?? 0);
                if ($websiteId > 0) {
                    $bindings = $websiteAccountModel->reset()
                        ->where(SeoWebsiteAccount::schema_fields_WEBSITE_ID, $websiteId)
                        ->select()
                        ->fetchArray();
                    $bindingCounts[$websiteId] = count($bindings);
                }
            }

            $this->assign('websites', $websites);
            $this->assign('binding_counts', $bindingCounts);
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('加载失败：%{1}', $e->getMessage()));
            $this->assign('websites', []);
            $this->assign('binding_counts', []);
            return $this->fetch();
        }
    }

    /**
     * 管理指定站点的SEO账户绑定
     * 
     * @return string
     */
    #[AclAttribute('Weline_Seo::website_account_manage', '管理站点SEO账户绑定', 'mdi-cog', '配置站点与SEO账户的绑定关系')]
    public function manage(): string
    {
        $websiteId = (int)$this->request->getGet('website_id', 0);
        $scope = trim((string)$this->request->getGet('scope', ''));
        
        if ($websiteId <= 0) {
            Message::error(__('站点ID无效'));
            return $this->fetchJson([
                'success' => false,
                'message' => __('站点ID无效'),
            ]);
        }

        // 获取站点信息
        /** @var Website $websiteModel */
        $websiteModel = $this->objectManager->getInstance(Website::class);
        $websiteModel->load($websiteId);
        
        if (!$websiteModel->getId()) {
            Message::error(__('站点不存在'));
            return $this->fetchJson([
                'success' => false,
                'message' => __('站点不存在'),
            ]);
        }

        // 如果没有传入 scope 参数，尝试从站点数据中获取
        // 这样可以自动按站点的业务来源过滤 SEO 账户
        if ($scope === '' && $websiteModel->getId()) {
            $scope = $websiteModel->getData(Website::schema_fields_SCOPE) ?? '';
        }

        // 获取SEO账户列表（按scope过滤）
        /** @var SeoAccount $accountModel */
        $accountModel = $this->objectManager->getInstance(SeoAccount::class);
        
        // 如果指定了scope，则进行过滤
        if ($scope !== '') {
            // 方式1: 查询指定scope的账户
            $query1 = $accountModel->reset()->select();
            $query1->where(SeoAccount::schema_fields_SCOPE, $scope);
            $accounts1 = $query1->fetchArray();
            
            // 方式2: 查询空scope的通用账户
            $query2 = $accountModel->reset()->select();
            $query2->where(SeoAccount::schema_fields_SCOPE, '');
            $accounts2 = $query2->fetchArray();
            
            // 合并并去重
            $accountsMap = [];
            foreach (array_merge($accounts1, $accounts2) as $account) {
                $accountsMap[(int)$account['account_id']] = $account;
            }
            
            // 转换为普通数组并按创建时间排序
            $accounts = array_values($accountsMap);
            usort($accounts, function($a, $b) {
                return strtotime($b['created_at'] ?? '') <=> strtotime($a['created_at'] ?? '');
            });
        } else {
            // 未指定过滤条件，获取所有账户
            $accounts = $accountModel->reset()
                ->select()
                ->order(SeoAccount::schema_fields_CREATED_AT, 'DESC')
                ->fetchArray();
        }

        // 获取当前站点已绑定的SEO账户
        /** @var SeoWebsiteAccount $websiteAccountModel */
        $websiteAccountModel = $this->objectManager->getInstance(SeoWebsiteAccount::class);
        $bindings = $websiteAccountModel->reset()
            ->where(SeoWebsiteAccount::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetchArray();

        // 重组为 account_id => binding_data 映射
        $bindingsMap = [];
        foreach ($bindings as $binding) {
            $bindingsMap[(int)$binding['account_id']] = $binding;
        }

        // 检测是否是 AJAX 请求
        $isAjax = $this->request->isAjax() || 
                  $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest';

        $this->assign('website', $websiteModel->getData());
        $this->assign('accounts', $accounts);
        $this->assign('bindingsMap', $bindingsMap);
        $this->assign('scope', $scope);
        $this->assign('is_ajax', $isAjax);
        
        return $this->fetch();
    }

    /**
     * 保存站点-SEO账户绑定（AJAX）
     * 
     * @return string
     */
    #[AclAttribute('Weline_Seo::website_account_save', '保存站点SEO账户绑定', 'mdi-content-save', '保存站点与SEO账户的绑定配置')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $websiteId = (int)$this->request->getPost('website_id', 0);
        $accountIds = $this->request->getPost('account_ids', []);
        $configs = $this->request->getPost('configs', []);

        if ($websiteId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('站点ID无效'),
            ]);
        }

        try {
            /** @var SeoWebsiteAccount $websiteAccountModel */
            $websiteAccountModel = $this->objectManager->getInstance(SeoWebsiteAccount::class);

            // 先删除该站点的所有现有绑定
            $existingBindings = $websiteAccountModel->reset()
                ->where(SeoWebsiteAccount::schema_fields_WEBSITE_ID, $websiteId)
                ->select()
                ->fetch();
            
            while ($existingBindings->getId()) {
                $existingBindings->delete();
                $existingBindings = $websiteAccountModel->reset()
                    ->where(SeoWebsiteAccount::schema_fields_WEBSITE_ID, $websiteId)
                    ->find()
                    ->fetch();
            }

            // 创建新的绑定
            $savedCount = 0;
            if (!empty($accountIds) && is_array($accountIds)) {
                foreach ($accountIds as $accountId) {
                    $accountId = (int)$accountId;
                    if ($accountId <= 0) {
                        continue;
                    }

                    // 获取该账户的配置
                    $config = $configs[$accountId] ?? [];
                    $sitemapFrequency = $config['sitemap_frequency'] ?? SeoWebsiteAccount::DEFAULT_SITEMAP_FREQUENCY;
                    $crawlFrequency = $config['crawl_frequency'] ?? SeoWebsiteAccount::DEFAULT_CRAWL_FREQUENCY;
                    $priority = isset($config['priority']) ? (float)$config['priority'] : SeoWebsiteAccount::DEFAULT_PRIORITY;
                    $isAutoSubmit = isset($config['is_auto_submit']) ? (int)$config['is_auto_submit'] : 1;

                    // 创建绑定
                    $websiteAccountModel->bindWebsiteAccount($websiteId, $accountId, [
                        'sitemap_frequency' => $sitemapFrequency,
                        'crawl_frequency' => $crawlFrequency,
                        'priority' => $priority,
                        'is_auto_submit' => $isAutoSubmit,
                    ]);
                    
                    $savedCount++;
                }
            }

            return $this->fetchJson([
                'success' => true,
                'message' => __('绑定配置保存成功，共绑定 %{1} 个SEO账户', $savedCount),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    /**
     * 解除站点与SEO账户的绑定（AJAX）
     * 
     * @return string
     */
    #[AclAttribute('Weline_Seo::website_account_unbind', '解除站点SEO账户绑定', 'mdi-link-off', '解除站点与SEO账户的绑定关系')]
    public function unbind(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $websiteId = (int)$this->request->getPost('website_id', 0);
        $accountId = (int)$this->request->getPost('account_id', 0);

        if ($websiteId <= 0 || $accountId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数无效'),
            ]);
        }

        try {
            /** @var SeoWebsiteAccount $websiteAccountModel */
            $websiteAccountModel = $this->objectManager->getInstance(SeoWebsiteAccount::class);
            
            $binding = $websiteAccountModel->reset()
                ->where(SeoWebsiteAccount::schema_fields_WEBSITE_ID, $websiteId)
                ->where(SeoWebsiteAccount::schema_fields_ACCOUNT_ID, $accountId)
                ->find()
                ->fetch();

            if ($binding->getId()) {
                $binding->delete();
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('绑定已解除'),
                ]);
            } else {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('绑定关系不存在'),
                ]);
            }
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('解除失败：%{1}', $e->getMessage()),
            ]);
        }
    }
}
