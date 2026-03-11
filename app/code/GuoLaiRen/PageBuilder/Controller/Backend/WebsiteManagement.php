<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 网站管理控制器 - 集成 Weline\Websites 模块功能到 PageBuilder 菜单
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Currency\Model\Currency;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\RedirectException;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locals;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\DomainPool;

/**
 * PageBuilder 网站管理控制器
 * 
 * 整合 Weline\Websites 模块，提供网站列表、添加、编辑、删除功能
 * 
 * @package GuoLaiRen_PageBuilder
 */
#[Acl('GuoLaiRen_PageBuilder::website_listing', '网站列表', 'mdi-format-list-bulleted', '网站列表管理', 'GuoLaiRen_PageBuilder::website_management')]
class WebsiteManagement extends BaseController
{
    private Website $website;
    private ObjectManager $objectManager;

    public function __construct(Website $website, ObjectManager $objectManager)
    {
        $this->website = $website;
        $this->objectManager = $objectManager;
    }

    /**
     * 网站管理首页 - 网站列表
     */
    #[Acl('GuoLaiRen_PageBuilder::website_listing_index', '查看网站列表', 'mdi-format-list-bulleted', '查看网站列表')]
    public function index(): string
    {
        // 如果是 AJAX 请求，返回 JSON 数据
        if ($this->request->isAjax()) {
            return $this->searchAjax();
        }
        
        // 搜索功能
        $search = $this->request->getGet('search', '');
        $websiteModel = clone $this->website;
        
        if ($search) {
            $searchPattern = '%' . $search . '%';
            $websiteModel->where('name', $searchPattern, 'LIKE')
                ->where('code', $searchPattern, 'LIKE', 'OR')
                ->where('url', $searchPattern, 'LIKE', 'OR');
        }
        
        $websites = $websiteModel->order()->pagination()->select()->fetch();
        $items = $websites->getItems();
        
        // 获取每个网站的关联货币、语言、域名
        $websiteCurrency = $this->objectManager->getInstance(WebsiteCurrency::class);
        $websiteLanguage = $this->objectManager->getInstance(WebsiteLanguage::class);
        $websiteDomain = $this->objectManager->getInstance(WebsiteDomain::class);
        
        foreach ($items as &$website) {
            $websiteId = (int)$website['website_id'];
            // 获取关联货币
            $currencyCodes = $websiteCurrency->getWebsiteCurrencyCodes($websiteId);
            $website['currency_codes'] = $currencyCodes;
            
            // 获取关联语言
            $languageCodes = $websiteLanguage->getWebsiteLanguageCodes($websiteId);
            $website['language_codes'] = $languageCodes;
            
            // 获取关联域名（多个）
            $website['domain_list'] = $websiteDomain->getDomainsWithStatus($websiteId);
        }
        
        $this->assign('title', __('PageBuilder网站管理'));
        $this->assign('websites', $items);
        $this->assign('pagination', $websites->getPagination());
        $this->assign('search', $search);
        
        return $this->fetch();
    }

    /**
     * AJAX 搜索接口
     */
    private function searchAjax(): string
    {
        try {
            // 搜索功能
            $search = $this->request->getGet('search', '');
            $websiteModel = clone $this->website;
            
            if ($search) {
                $searchPattern = '%' . $search . '%';
                $websiteModel->where('name', $searchPattern, 'LIKE')
                    ->where('code', $searchPattern, 'LIKE', 'OR')
                    ->where('url', $searchPattern, 'LIKE', 'OR');
            }
            
            $websites = $websiteModel->order()->pagination()->select()->fetch();
            $items = $websites->getItems();
            
            // 获取每个网站的关联货币、语言、域名
            $websiteCurrency = $this->objectManager->getInstance(WebsiteCurrency::class);
            $websiteLanguage = $this->objectManager->getInstance(WebsiteLanguage::class);
            $websiteDomain = $this->objectManager->getInstance(WebsiteDomain::class);
            
            foreach ($items as &$website) {
                $websiteId = (int)$website['website_id'];
                // 获取关联货币
                $currencyCodes = $websiteCurrency->getWebsiteCurrencyCodes($websiteId);
                $website['currency_codes'] = $currencyCodes;
                
                // 获取关联语言
                $languageCodes = $websiteLanguage->getWebsiteLanguageCodes($websiteId);
                $website['language_codes'] = $languageCodes;
                
                // 获取关联域名（多个）
                $website['domain_list'] = $websiteDomain->getDomainsWithStatus($websiteId);
            }
            
            // 渲染表格 HTML
            $this->assign('websites', $items);
            $this->assign('pagination', $websites->getPagination());
            $this->assign('search', $search);
            $tableHtml = $this->fetch('GuoLaiRen_PageBuilder::templates/Backend/WebsiteManagement/table.phtml');
            
            return $this->fetchJson([
                'success' => true,
                'html' => $tableHtml,
                'count' => count($items)
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 添加网站（使用空白布局，适用于 offcanvas）
     */
    #[Acl('GuoLaiRen_PageBuilder::website_listing_add', '添加网站', 'mdi mdi-plus', '网站列表管理')]
    public function add(): string
    {
        // 使用空白布局（适用于 offcanvas/弹窗）
        $this->layoutType = 'default.blank';
        
        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            try {
                // PageBuilder 内 scope 固定为 page_builder，不允许修改
                $data['scope'] = 'page_builder';
                
                // 处理关联货币和语言
                $currencyCodes = $data['currency_codes'] ?? [];
                $languageCodes = $data['language_codes'] ?? [];
                
                // 如果默认货币为空，从关联货币中选择第一个
                if (empty($data['default_currency']) && !empty($currencyCodes)) {
                    $data['default_currency'] = $currencyCodes[0];
                }
                
                // 如果默认语言为空，从关联语言中选择第一个
                if (empty($data['default_language']) && !empty($languageCodes)) {
                    $data['default_language'] = $languageCodes[0];
                }
                
                // 保存网站基本信息
                if (isset($data['website_id'])) {
                    unset($data['website_id']);
                }

                $url = \trim((string) ($data['url'] ?? ''));
                if ($url !== '') {
                    $exist = $this->website->clearQuery()
                        ->where(Website::schema_fields_URL, $url)
                        ->select()
                        ->find();
                    if ($exist->getData(Website::schema_fields_ID)) {
                        throw new \Exception(__('该网站 URL 已存在，请勿重复添加：%{1}', [$url]));
                    }
                }

                $newWebsite = $this->objectManager->getInstance(Website::class);
                $newWebsite->clearData()->setData($data)->save();
                $websiteId = $newWebsite->getId();
                
                // 检查是否成功保存
                if (empty($websiteId)) {
                    throw new \Exception(__('网站保存失败，未能获取网站ID'));
                }
                
                // 处理域名池选择（pool_id[] 数组）
                $poolIds = $data['pool_id'] ?? [];
                if (!is_array($poolIds)) {
                    $poolIds = array_filter(explode(',', (string)$poolIds));
                }
                $poolIds = array_filter(array_map('intval', $poolIds));
                
                // 保存网站域名关联
                if (!empty($poolIds)) {
                    $this->saveWebsiteDomains((int)$websiteId, $poolIds);
                }
                
                // 保存关联货币
                $websiteCurrency = $this->objectManager->getInstance(WebsiteCurrency::class);
                $websiteCurrency->setWebsiteCurrencies((int)$websiteId, $currencyCodes);
                
                // 保存关联语言
                $websiteLanguage = $this->objectManager->getInstance(WebsiteLanguage::class);
                $websiteLanguage->setWebsiteLanguages((int)$websiteId, $languageCodes);
                
                // 绑定 SEO 账户
                $seoAccountId = (int)($data['seo_account_id'] ?? 0);
                if ($seoAccountId > 0) {
                    try {
                        $eventsManager = $this->objectManager->getInstance(\Weline\Framework\Event\EventsManager::class);
                        $eventData = [
                            'website_id' => (int)$websiteId,
                            'account_id' => $seoAccountId,
                            'is_auto_submit' => true,
                        ];
                        $eventsManager->dispatch('Weline_Seo::domain::website_account_bind', $eventData);
                    } catch (\Exception $e) {
                        // SEO 账户绑定失败不影响网站保存结果
                    }
                }
                
                // 使用后端 offcanvas 路由（Weline_Component Backend Offcanvas），同一套模板
                $this->redirect('/component/offcanvas/success', [
                    'msg' => __('网站添加成功'),
                    'url' => $this->_url->getBackendUrl('*/backend/websiteManagement'),
                    'reload' => '1',
                    'time' => '3',
                ]);
            } catch (\Exception $e) {
                if ($e instanceof RedirectException) {
                    throw $e;
                }
                $msg = $e->getMessage();
                if (\str_contains($msg, '23505') || \str_contains($msg, 'Unique violation') || \str_contains($msg, 'duplicate key') || \str_contains($msg, 'uk_url')) {
                    $msg = __('该网站 URL 已存在，请勿重复添加。若需修改请到网站列表中编辑对应站点。');
                } else {
                    $msg = __('网站添加失败: %{1}', [$msg]);
                }
                $this->redirect('/component/offcanvas/error', [
                    'msg' => $msg,
                    'url' => '/',
                    'reload' => '0',
                    'time' => '3',
                ]);
            }
        }
        
        // 初始化空网站数据
        $this->assign('website', []);
        $this->assign('selected_currencies', []);
        $this->assign('selected_languages', []);
        // 支持 URL 传入 pool_id 或 pool_ids，创建站点时预选域名并会在保存时自动绑定
        $poolIdParam = $this->request->getParam('pool_id');
        $poolIdsParam = $this->request->getParam('pool_ids');
        $selectedPoolIds = [];
        if ($poolIdParam !== null && $poolIdParam !== '') {
            $selectedPoolIds = array_filter(array_map('intval', (array) $poolIdParam));
        } elseif ($poolIdsParam !== null && $poolIdsParam !== '') {
            $selectedPoolIds = is_array($poolIdsParam)
                ? array_filter(array_map('intval', $poolIdsParam))
                : array_filter(array_map('intval', explode(',', (string) $poolIdsParam)));
        }
        $this->assign('selected_pool_ids', $selectedPoolIds);
        
        // 获取所有货币
        $this->assign('currencies', $this->getAllCurrencies());
        
        // 获取所有语言
        $this->assign('locales', $this->getAllLocales());
        
        // 时区
        $timezones = \DateTimeZone::listIdentifiers();
        sort($timezones);
        $this->assign('timezones', $timezones);
        
        // 获取域名池数据（按根域名分组）
        $domainPool = $this->objectManager->getInstance(\Weline\Websites\Model\DomainPool::class);
        $this->assign('domain_options', $domainPool->getSelectOptions());
        
        return $this->fetch('form');
    }

    /**
     * 编辑网站
     */
    #[Acl('GuoLaiRen_PageBuilder::website_listing_edit', '编辑网站', 'mdi mdi-pencil', '网站列表管理')]
    public function edit(): string
    {
        // 使用空白布局（适用于 offcanvas/弹窗）
        $this->layoutType = 'default.blank';
        
        $websiteId = $this->request->getParam('id');
        
        if (empty($websiteId)) {
            $this->redirect('/component/offcanvas/error', [
                'msg' => __('网站ID不能为空'),
                'reload' => '0',
                'time' => '3',
            ]);
            return '';
        }
        
        $websiteId = (int)$websiteId;
        $this->website->load($websiteId);
        
        // 检查网站是否存在
        if (!$this->website->getWebsiteId()) {
            $this->redirect('/component/offcanvas/error', [
                'msg' => __('网站不存在'),
                'reload' => '0',
                'time' => '3',
            ]);
            return '';
        }

        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            
            // PageBuilder 内 scope 固定为 page_builder，不允许修改
            $data['scope'] = 'page_builder';
            
            $postWebsiteId = $data['website_id'] ?? null;
            if (empty($postWebsiteId)) {
                $postWebsiteId = $this->request->getParam('id');
            }
            if (empty($postWebsiteId)) {
                $postWebsiteId = $websiteId;
            }
            
            if (empty($postWebsiteId)) {
                $this->redirect('/component/offcanvas/error', [
                    'msg' => __('网站ID不能为空'),
                    'reload' => '0',
                    'time' => '3',
                ]);
                return '';
            }
            
            $postWebsiteId = (int)$postWebsiteId;
            
            try {
                // 处理关联货币和语言
                $currencyCodes = $data['currency_codes'] ?? [];
                $languageCodes = $data['language_codes'] ?? [];
                
                // 如果默认货币为空，从关联货币中选择第一个
                if (empty($data['default_currency']) && !empty($currencyCodes)) {
                    $data['default_currency'] = $currencyCodes[0];
                }
                
                // 如果默认语言为空，从关联语言中选择第一个
                if (empty($data['default_language']) && !empty($languageCodes)) {
                    $data['default_language'] = $languageCodes[0];
                }
                
                // 确保 website_id 在数据中
                $data['website_id'] = $postWebsiteId;

                $url = \trim((string) ($data['url'] ?? ''));
                if ($url !== '') {
                    $exist = $this->website->clearQuery()
                        ->where(Website::schema_fields_URL, $url)
                        ->where(Website::schema_fields_ID, $postWebsiteId, '<>')
                        ->select()
                        ->find();
                    if ($exist->getData(Website::schema_fields_ID)) {
                        throw new \Exception(__('该网站 URL 已被其他站点使用，请勿重复：%{1}', [$url]));
                    }
                }
                
                // 保存网站基本信息
                $this->website->addData($data)->save();
                
                // 处理域名池选择（pool_id[] 数组）
                $poolIds = $data['pool_id'] ?? [];
                if (!is_array($poolIds)) {
                    $poolIds = array_filter(explode(',', (string)$poolIds));
                }
                $poolIds = array_filter(array_map('intval', $poolIds));
                
                // 保存网站域名关联
                $this->saveWebsiteDomains($postWebsiteId, $poolIds);
                
                // 保存关联货币
                try {
                    $websiteCurrency = $this->objectManager->getInstance(WebsiteCurrency::class);
                    $websiteCurrency->setWebsiteCurrencies($postWebsiteId, $currencyCodes);
                } catch (\Exception $e) {
                    MessageManager::warning(__('保存关联货币失败: %{1}', $e->getMessage()));
                }
                
                // 保存关联语言
                try {
                    $websiteLanguage = $this->objectManager->getInstance(WebsiteLanguage::class);
                    $websiteLanguage->setWebsiteLanguages($postWebsiteId, $languageCodes);
                } catch (\Exception $e) {
                    MessageManager::warning(__('保存关联语言失败: %{1}', $e->getMessage()));
                }
                
                // 绑定 SEO 账户
                $seoAccountId = (int)($data['seo_account_id'] ?? 0);
                if ($seoAccountId > 0) {
                    try {
                        $eventsManager = $this->objectManager->getInstance(\Weline\Framework\Event\EventsManager::class);
                        $eventData = [
                            'website_id' => $postWebsiteId,
                            'account_id' => $seoAccountId,
                            'is_auto_submit' => true,
                        ];
                        $eventsManager->dispatch('Weline_Seo::domain::website_account_bind', $eventData);
                    } catch (\Exception $e) {
                        // SEO 账户绑定失败不影响网站保存结果
                    }
                }
                
                // 使用后端 offcanvas 路由，同一套模板
                $this->redirect('/component/offcanvas/success', [
                    'msg' => __('网站更新成功'),
                    'url' => $this->_url->getBackendUrl('*/backend/websiteManagement'),
                    'reload' => '1',
                    'time' => '3',
                ]);
            } catch (\Exception $e) {
                if ($e instanceof RedirectException) {
                    throw $e;
                }
                $msg = $e->getMessage();
                if (\str_contains($msg, '23505') || \str_contains($msg, 'Unique violation') || \str_contains($msg, 'duplicate key') || \str_contains($msg, 'uk_url')) {
                    $msg = __('该网站 URL 已被其他站点使用，请勿重复。请更换为其他 URL。');
                } else {
                    $msg = __('网站更新失败: %{1}', [$msg]);
                }
                $this->redirect('/component/offcanvas/error', [
                    'msg' => $msg,
                    'url' => $this->_url->getBackendUrl('*/backend/websiteManagement'),
                    'reload' => '0',
                    'time' => '5',
                ]);
            }
        }

        // 获取网站的关联货币和语言
        $selectedCurrencies = [];
        $selectedLanguages = [];
        
        try {
            $websiteCurrency = $this->objectManager->getInstance(WebsiteCurrency::class);
            $selectedCurrencies = $websiteCurrency->getWebsiteCurrencyCodes($websiteId);
        } catch (\Exception $e) {
            $selectedCurrencies = [];
        }
        
        try {
            $websiteLanguage = $this->objectManager->getInstance(WebsiteLanguage::class);
            $selectedLanguages = $websiteLanguage->getWebsiteLanguageCodes($websiteId);
        } catch (\Exception $e) {
            $selectedLanguages = [];
        }
        
        $this->assign('website', $this->website->getData());
        $this->assign('selected_currencies', $selectedCurrencies);
        $this->assign('selected_languages', $selectedLanguages);
        
        // 获取网站已关联的域名（用于编辑时显示）
        $selectedPoolIds = [];
        try {
            $websiteDomain = $this->objectManager->getInstance(WebsiteDomain::class);
            $domains = $websiteDomain->getWebsiteDomains($websiteId);
            foreach ($domains as $domain) {
                $poolId = (int)($domain[WebsiteDomain::schema_fields_POOL_ID] ?? 0);
                if ($poolId > 0) {
                    $selectedPoolIds[] = $poolId;
                }
            }
        } catch (\Exception $e) {
            $selectedPoolIds = [];
        }
        $this->assign('selected_pool_ids', $selectedPoolIds);
        
        // 获取所有货币
        $this->assign('currencies', $this->getAllCurrencies());
        
        // 获取所有语言
        $this->assign('locales', $this->getAllLocales());
        
        // 时区
        $timezones = \DateTimeZone::listIdentifiers();
        sort($timezones);
        $this->assign('timezones', $timezones);
        
        // 获取域名池数据（按根域名分组）
        $domainPool = $this->objectManager->getInstance(\Weline\Websites\Model\DomainPool::class);
        $this->assign('domain_options', $domainPool->getSelectOptions());
        
        return $this->fetch('form');
    }

    /**
     * 删除网站
     */
    #[Acl('GuoLaiRen_PageBuilder::website_listing_delete', '删除网站', 'mdi mdi-delete', '网站列表管理')]
    public function deleteDelete(): string
    {
        $websiteId = $this->request->getGet('id');
        try {
            $websiteModel = clone $this->website;
            $websiteModel->load($websiteId);
            
            // 检查是否是默认网站，默认网站不允许删除
            if ($websiteModel->getCode() === 'default') {
                return $this->fetchJson([
                    'success' => false,
                    'code' => 403,
                    'msg' => __('默认网站不允许删除'),
                ]);
            }
            
            $websiteId = (int) $websiteModel->getData('website_id');
            // 删除站点前解绑域名：删除该站点下所有 website_domain 并同步域名池 site_created
            $websiteDomainModel = $this->objectManager->getInstance(WebsiteDomain::class);
            $websiteDomainModel->clearQuery()
                ->where(WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
                ->delete()
                ->fetch();
            $domainPool = $this->objectManager->getInstance(DomainPool::class);
            $domainPool->syncSiteCreatedFromWebsiteDomainTable();
            
            $websiteModel->delete()->fetch();
            return $this->fetchJson([
                'code' => 200,
                'success' => true,
                'msg' => __('网站删除成功'),
                'reload' => '1',
                'url' => $this->_url->getBackendUrl('*/backend/websiteManagement'),
                'time' => '3',
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'code' => 500,
                'msg' => __('网站删除失败: %{1}', $e->getMessage()),
            ]);
        }
    }

    /**
     * 获取所有启用的货币
     */
    private function getAllCurrencies(): array
    {
        try {
            $currencyModel = $this->objectManager->getInstance(Currency::class);
            $currencies = $currencyModel->clearQuery()
                ->where(Currency::schema_fields_STATUS, 1)
                ->order(Currency::schema_fields_CODE, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            
            $result = [];
            foreach ($currencies as $currency) {
                $result[] = [
                    'code' => $currency->getCode(),
                    'name' => $currency->getName(),
                ];
            }
            
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取所有i18n支持的语言
     */
    private function getAllLocales(): array
    {
        $targetCode = Cookie::getLangLocal();
        $localsModel = $this->objectManager->getInstance(Locals::class);
        $locales = $localsModel
            ->clearQuery()
            ->where(Locals::schema_fields_TARGET_CODE, $targetCode)
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();
        
        $i18n = $this->objectManager->getInstance(\Weline\I18n\Model\I18n::class);
        
        // 如果根据 target_code 查不到数据，则查询所有已安装的语言
        if (!$locales) {
            $allLocales = $localsModel
                ->clearQuery()
                ->where(Locals::schema_fields_IS_INSTALL, 1)
                ->where(Locals::schema_fields_IS_ACTIVE, 1)
                ->order(Locals::schema_fields_CODE, 'ASC')
                ->select()
                ->fetchArray();
            
            if (!$allLocales) {
                MessageManager::error(__('当前语言没有对应语言包翻译，请前往i18n模块对%{1}语言的地区语言进行更新', $targetCode));
                return [];
            } else {
                // 按 code 去重
                $uniqueCodes = [];
                foreach ($allLocales as $locale) {
                    $code = $locale['code'];
                    if (!in_array($code, $uniqueCodes)) {
                        $uniqueCodes[] = $code;
                    }
                }
                
                // 使用 Symfony Intl 获取当前界面语言下的语言名称
                $locales = [];
                foreach ($uniqueCodes as $code) {
                    $locales[] = [
                        'code' => $code,
                        'name' => $i18n->getLocaleName($code, $targetCode),
                        'target_code' => $targetCode,
                        'is_active' => 1,
                        'is_install' => 1
                    ];
                }
            }
        } else {
            // 即使查询成功，也确保名称是当前界面语言下的名称
            foreach ($locales as &$locale) {
                if ($locale['target_code'] !== $targetCode) {
                    $locale['name'] = $i18n->getLocaleName($locale['code'], $targetCode);
                }
            }
        }
        
        return $locales ?: [];
    }
    
    /**
     * 保存站点的域名关联（先删后增）
     * 
     * @param int $websiteId 网站 ID
     * @param array $poolIds 域名池 ID 数组
     */
    private function saveWebsiteDomains(int $websiteId, array $poolIds): void
    {
        /** @var WebsiteDomain $model */
        $model = $this->objectManager->getInstance(WebsiteDomain::class);
        $model->clearQuery()
            ->where(WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();
        
        $isFirst = true;
        foreach ($poolIds as $poolId) {
            $poolId = (int)$poolId;
            if ($poolId <= 0) {
                continue;
            }
            
            /** @var WebsiteDomain $newDomain */
            $newDomain = $this->objectManager->getInstance(WebsiteDomain::class, [], false);
            $newDomain->setWebsiteId($websiteId);
            $newDomain->setPoolId($poolId);
            $newDomain->syncFromPool();
            $newDomain->setIsPrimary($isFirst);
            $newDomain->setStatus(WebsiteDomain::STATUS_ACTIVE);
            $newDomain->save();
            $isFirst = false;
        }
        
        // 同步域名池 site_created 状态（已建站的域名创建站点时不再展示）
        $pool = $this->objectManager->getInstance(DomainPool::class);
        $pool->syncSiteCreatedFromWebsiteDomainTable();
    }
}
