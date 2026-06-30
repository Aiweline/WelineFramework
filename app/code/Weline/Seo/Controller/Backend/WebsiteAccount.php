<?php

declare(strict_types=1);

namespace Weline\Seo\Controller\Backend;

use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Seo\Service\SeoWebsiteAccountBindingService;
use Weline\Seo\Service\SeoWebsiteDirectory;

#[AclAttribute('Weline_Seo::website_account', '站点SEO账户关联', 'mdi-link-variant', '管理站点与SEO账户的关联关系', 'Weline_Backend::seo_group')]
class WebsiteAccount extends BackendController
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    private function getWebsiteDirectory(): SeoWebsiteDirectory
    {
        return $this->objectManager->getInstance(SeoWebsiteDirectory::class);
    }

    private function getBindingService(): SeoWebsiteAccountBindingService
    {
        return $this->objectManager->getInstance(SeoWebsiteAccountBindingService::class);
    }

    #[AclAttribute('Weline_Seo::website_account_index', '查看站点账户绑定', 'mdi-view-list', '查看站点与SEO账户的绑定列表')]
    public function index(): string
    {
        try {
            $websites = $this->getWebsiteDirectory()->listWebsites();
            $bindingCounts = $this->getBindingService()->getBindingCounts($websites);

            $this->assign('websites', $websites);
            $this->assign('binding_counts', $bindingCounts);
            return $this->fetch();
        } catch (\Throwable $e) {
            Message::error(__('加载失败：%{1}', $e->getMessage()));
            $this->assign('websites', []);
            $this->assign('binding_counts', []);
            return $this->fetch();
        }
    }

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

        $website = $this->getWebsiteDirectory()->getWebsiteById($websiteId);
        if (!$website) {
            Message::error(__('站点不存在'));
            return $this->fetchJson([
                'success' => false,
                'message' => __('站点不存在'),
            ]);
        }

        if ($scope === '') {
            $scope = (string)($website['scope'] ?? '');
        }

        /** @var SeoAccount $accountModel */
        $accountModel = $this->objectManager->getInstance(SeoAccount::class);
        if ($scope !== '') {
            $accountsById = [];

            $scopeAccounts = $accountModel->reset()
                ->select()
                ->where(SeoAccount::schema_fields_SCOPE, $scope)
                ->fetchArray();
            $globalAccounts = $accountModel->reset()
                ->select()
                ->where(SeoAccount::schema_fields_SCOPE, '')
                ->fetchArray();

            foreach (array_merge($scopeAccounts, $globalAccounts) as $account) {
                $accountId = (int)($account[SeoAccount::schema_fields_ID] ?? $account['account_id'] ?? 0);
                if ($accountId > 0) {
                    $accountsById[$accountId] = $account;
                }
            }

            $accounts = array_values($accountsById);
            usort($accounts, static function (array $left, array $right): int {
                return strtotime((string)($right['created_at'] ?? '')) <=> strtotime((string)($left['created_at'] ?? ''));
            });
        } else {
            $accounts = $accountModel->reset()
                ->select()
                ->order(SeoAccount::schema_fields_CREATED_AT, 'DESC')
                ->fetchArray();
        }

        $isAjax = $this->request->isAjax()
            || $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest';

        $this->assign('website', $website);
        $this->assign('accounts', $accounts);
        $this->assign('bindingsMap', $this->getBindingService()->getBindingMapByWebsite($websiteId));
        $this->assign('scope', $scope);
        $this->assign('is_ajax', $isAjax);

        return $this->fetch();
    }

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

            foreach ($this->getBindingService()->getBindingsByWebsite($websiteId) as $binding) {
                $accountId = (int)($binding[SeoWebsiteAccount::schema_fields_ACCOUNT_ID] ?? 0);
                if ($accountId > 0) {
                    $websiteAccountModel->unbindWebsiteAccount($websiteId, $accountId);
                }
            }

            $savedCount = 0;
            if (is_array($accountIds)) {
                foreach ($accountIds as $accountId) {
                    $accountId = (int)$accountId;
                    if ($accountId <= 0) {
                        continue;
                    }

                    $config = is_array($configs) ? ($configs[$accountId] ?? []) : [];
                    if (!is_array($config)) {
                        $config = [];
                    }

                    $websiteAccountModel->bindWebsiteAccount($websiteId, $accountId, [
                        'sitemap_frequency' => $config['sitemap_frequency'] ?? SeoWebsiteAccount::DEFAULT_SITEMAP_FREQUENCY,
                        'crawl_frequency' => $config['crawl_frequency'] ?? SeoWebsiteAccount::DEFAULT_CRAWL_FREQUENCY,
                        'priority' => isset($config['priority']) ? (float)$config['priority'] : SeoWebsiteAccount::DEFAULT_PRIORITY,
                        'is_auto_submit' => isset($config['is_auto_submit']) ? (int)$config['is_auto_submit'] : 1,
                        'enable_url_push' => isset($config['enable_url_push']) ? (int)$config['enable_url_push'] : 1,
                    ]);

                    $savedCount++;
                }
            }

            return $this->fetchJson([
                'success' => true,
                'message' => __('绑定配置保存成功，共绑定 %{1} 个SEO账户', $savedCount),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage()),
            ]);
        }
    }

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
            }

            return $this->fetchJson([
                'success' => false,
                'message' => __('绑定关系不存在'),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('解除失败：%{1}', $e->getMessage()),
            ]);
        }
    }
}
