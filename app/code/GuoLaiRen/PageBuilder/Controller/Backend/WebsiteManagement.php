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
        $this->assign('add_site_label', __('新建站点'));
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
    #[Acl('GuoLaiRen_PageBuilder::website_listing_add', '新建站点', 'mdi mdi-plus', '网站列表管理')]
    public function add(): string
    {
        // 使用空白布局（适用于 offcanvas/弹窗）
        $this->layoutType = 'default.blank';
        
        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            try {
                // PageBuilder 内 scope 固定为 page_builder，不允许修改
                $data['scope'] = 'page_builder';

                $poolIds = $data['pool_ids'] ?? '';
                $subPath = $this->normalizeSubPath((string) ($data['sub_path'] ?? ''));
                $addressList = $this->buildAddressListFromPoolSelection($poolIds, $subPath);
                if (empty($addressList)) {
                    throw new \Exception(__('请至少选择一个域名'));
                }

                /** @var WebsiteDomain $domainModel */
                $domainModel = $this->objectManager->getInstance(WebsiteDomain::class);
                foreach ($addressList as $item) {
                    $conflict = $domainModel->findConflict($item['domain'], $item['sub_path'], null);
                    if ($conflict !== null) {
                        $addr = $item['domain'] . $item['sub_path'];
                        if ($item['sub_path'] === '') {
                            throw new \Exception(
                                __('该域名根路径已被站点「%{1}」使用，请使用子路径（如 /shop）', [$conflict['website_name']])
                            );
                        }
                        throw new \Exception(
                            __('该地址 %{1} 已被站点「%{2}」使用', [$addr, $conflict['website_name']])
                        );
                    }
                }

                $addressList = $this->orderAddressListPreferredUrl($addressList);
                $firstDomain = (string) ($addressList[0]['domain'] ?? '');
                $firstSubPath = (string) ($addressList[0]['sub_path'] ?? '');
                if (empty(\trim((string) ($data['code'] ?? '')))) {
                    $data['code'] = $this->domainToCode($firstDomain);
                }
                $data['url'] = 'https://' . $firstDomain . $firstSubPath;
                
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
                unset($data['domain_values'], $data['pool_ids'], $data['pool_id'], $data['sub_path']);

                $newWebsite = $this->objectManager->getInstance(Website::class);
                $newWebsite->clearData()->setData($data)->save();
                $websiteId = $newWebsite->getId();
                
                // 检查是否成功保存
                if (empty($websiteId)) {
                    throw new \Exception(__('网站保存失败，未能获取网站ID'));
                }
                
                // 保存网站域名关联
                $this->saveWebsiteDomains((int) $websiteId, $addressList);
                
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
                // 跳转结果桥接页，显示 success 后再回网站管理（iframe 内也能看到 Toast）
                $url = $this->_url->getBackendUrl('component/backend/offcanvas/getSuccess', [
                    'msg' => __('网站添加成功'),
                    'url' => $this->_url->getBackendUrl('*/backend/websiteManagement'),
                    'reload' => '1',
                    'time' => '3',
                ]);
                $this->redirect($url);
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
                // 跳转结果桥接页，显示 error 后再回网站管理
                $url = $this->_url->getBackendUrl('component/backend/offcanvas/getError', [
                    'msg' => $msg,
                    'url' => $this->_url->getBackendUrl('*/backend/websiteManagement'),
                    'reload' => '0',
                    'time' => '5',
                ]);
                $this->redirect($url);
            }
        }
        
        // 初始化空网站数据，并设置页面标题为「新建站点」
        $this->assign('title', __('新建站点'));
        $this->assign('website', []);
        $this->assign('selected_currencies', []);
        $this->assign('selected_languages', []);
        $this->assign('sub_path', '');
        // 支持 URL 传入 pool_id 或 pool_ids，创建站点时预选域名并会在保存时自动绑定
        $poolIdParam = $this->request->getParam('pool_id');
        $poolIdsParam = $this->request->getParam('pool_ids');
        $selectedPoolIds = [];
        if ($poolIdParam !== null && $poolIdParam !== '') {
            $selectedPoolIds = $this->parsePoolIds((array) $poolIdParam);
        } elseif ($poolIdsParam !== null && $poolIdsParam !== '') {
            $selectedPoolIds = $this->parsePoolIds($poolIdsParam);
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
            $url = $this->_url->getBackendUrl('component/backend/offcanvas/getError', [
                'msg' => __('网站ID不能为空'),
                'reload' => '0',
                'time' => '3',
            ]);
            $this->redirect($url);
            return '';
        }
        
        $websiteId = (int)$websiteId;
        $this->website->load($websiteId);
        
        // 检查网站是否存在
        if (!$this->website->getWebsiteId()) {
            $url = $this->_url->getBackendUrl('component/backend/offcanvas/getError', [
                'msg' => __('网站不存在'),
                'reload' => '0',
                'time' => '3',
            ]);
            $this->redirect($url);
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
                $url = $this->_url->getBackendUrl('component/backend/offcanvas/getError', [
                    'msg' => __('网站ID不能为空'),
                    'reload' => '0',
                    'time' => '3',
                ]);
                $this->redirect($url);
                return '';
            }
            
            $postWebsiteId = (int)$postWebsiteId;
            
            try {
                $poolIds = $data['pool_ids'] ?? '';
                $subPath = $this->normalizeSubPath((string) ($data['sub_path'] ?? ''));
                $addressList = $this->buildAddressListFromPoolSelection($poolIds, $subPath);
                if (empty($addressList)) {
                    throw new \Exception(__('请至少选择一个域名'));
                }

                /** @var WebsiteDomain $domainModel */
                $domainModel = $this->objectManager->getInstance(WebsiteDomain::class);
                foreach ($addressList as $item) {
                    $conflict = $domainModel->findConflict($item['domain'], $item['sub_path'], $postWebsiteId);
                    // 编辑时：若冲突记录是当前网站自身，则不报错，允许更新地址
                    if ($conflict !== null && (int) ($conflict['website_id'] ?? 0) !== $postWebsiteId) {
                        $addr = $item['domain'] . $item['sub_path'];
                        if ($item['sub_path'] === '') {
                            throw new \Exception(
                                __('该域名根路径已被站点「%{1}」使用，请使用子路径（如 /shop）', [$conflict['website_name']])
                            );
                        }
                        throw new \Exception(
                            __('该地址 %{1} 已被站点「%{2}」使用', [$addr, $conflict['website_name']])
                        );
                    }
                }
                $addressList = $this->orderAddressListPreferredUrl($addressList);
                $firstDomain = (string) ($addressList[0]['domain'] ?? '');
                $firstSubPath = (string) ($addressList[0]['sub_path'] ?? '');
                if (empty(\trim((string) ($data['code'] ?? '')))) {
                    $data['code'] = $this->domainToCode($firstDomain);
                }
                $data['url'] = 'https://' . $firstDomain . $firstSubPath;

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
                unset($data['domain_values'], $data['pool_ids'], $data['pool_id'], $data['sub_path']);
                
                // 保存网站基本信息
                $this->website->addData($data)->save();
                
                // 保存网站域名关联
                $this->saveWebsiteDomains($postWebsiteId, $addressList);
                
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
                
                $url = $this->_url->getBackendUrl('component/backend/offcanvas/getSuccess', [
                    'msg' => __('网站更新成功'),
                    'url' => $this->_url->getBackendUrl('*/backend/websiteManagement'),
                    'reload' => '1',
                    'time' => '3',
                ]);
                $this->redirect($url);
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
                $url = $this->_url->getBackendUrl('component/backend/offcanvas/getError', [
                    'msg' => $msg,
                    'url' => $this->_url->getBackendUrl('*/backend/websiteManagement'),
                    'reload' => '0',
                    'time' => '5',
                ]);
                $this->redirect($url);
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
        $this->assign('sub_path', $this->getPrimarySubPathForWebsite($websiteId));
        
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
    
    private function parsePoolIds(array|string $input): array
    {
        if (\is_array($input)) {
            $flat = [];
            foreach ($input as $value) {
                if (\is_string($value) && \str_contains($value, ',')) {
                    $flat = \array_merge($flat, \array_filter(\explode(',', $value)));
                } else {
                    $flat[] = $value;
                }
            }
            $input = $flat;
        } else {
            $input = \array_filter(\explode(',', (string) $input));
        }
        return \array_values(\array_filter(\array_map('intval', $input), static fn (int $id): bool => $id > 0));
    }

    /**
     * 获取域名选项（用于多选，来自域名池）
     */
    private function getDomainOptions(): array
    {
        try {
            $pool = $this->objectManager->getInstance(DomainPool::class);
            return $pool->getSelectOptions();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 对地址列表排序：当同时存在根域与 www 时，www 排在前（作为主 URL）
     */
    private function orderAddressListPreferredUrl(array $addressList): array
    {
        $domains = \array_column($addressList, 'domain');
        $hasPair = false;
        foreach ($domains as $domain) {
            if (\str_starts_with($domain, 'www.') && \in_array(\substr($domain, 4), $domains, true)) {
                $hasPair = true;
                break;
            }
        }
        if (!$hasPair) {
            return $addressList;
        }
        \usort($addressList, static function (array $a, array $b): int {
            $domainA = (string) ($a['domain'] ?? '');
            $domainB = (string) ($b['domain'] ?? '');
            $rootA = \str_starts_with($domainA, 'www.') ? \substr($domainA, 4) : $domainA;
            $rootB = \str_starts_with($domainB, 'www.') ? \substr($domainB, 4) : $domainB;
            if ($rootA !== $rootB) {
                return 0;
            }
            return \str_starts_with($domainA, 'www.') ? -1 : 1;
        });
        return $addressList;
    }

    /**
     * 从域名池选择构建站点地址列表，所有选中域名共享同一个子路径。
     */
    private function buildAddressListFromPoolSelection(array|string $poolIds, string $subPath = ''): array
    {
        $list = [];
        $seen = [];
        $subPath = $this->normalizeSubPath($subPath);
        $poolIdArray = $this->parsePoolIds($poolIds);
        foreach ($poolIdArray as $poolId) {
            /** @var DomainPool $pool */
            $pool = $this->objectManager->getInstance(DomainPool::class, [], false);
            $pool->loadByPoolId($poolId);
            if (!$pool->getPoolId()) {
                continue;
            }
            $domain = \strtolower(\trim((string) $pool->getDomain()));
            if ($domain === '') {
                continue;
            }
            $key = $domain . '|' . $subPath;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $list[] = [
                'domain' => $domain,
                'sub_path' => $subPath,
                'pool_id' => $poolId,
            ];
        }
        return $list;
    }

    private function normalizeSubPath(string $subPath): string
    {
        $subPath = \trim($subPath);
        if ($subPath === '' || $subPath === '/') {
            return '';
        }
        $subPath = '/' . \trim($subPath, '/');
        return $subPath === '/' ? '' : $subPath;
    }

    private function domainToCode(string $domain): string
    {
        return \str_replace('.', '_', \strtolower(\trim($domain)));
    }

    /**
     * 保存站点的域名列表（先删后增，第一个为主域名）
     */
    private function saveWebsiteDomains(int $websiteId, array $addressList): void
    {
        /** @var WebsiteDomain $model */
        $model = $this->objectManager->getInstance(WebsiteDomain::class);
        $model->clearQuery()
            ->where(WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();

        $isFirst = true;
        foreach ($addressList as $item) {
            /** @var WebsiteDomain $newDomain */
            $newDomain = $this->objectManager->getInstance(WebsiteDomain::class, [], false);
            $newDomain->setWebsiteId($websiteId);
            $poolId = (int) ($item['pool_id'] ?? 0);
            if ($poolId > 0) {
                $newDomain->setPoolId($poolId);
                $newDomain->syncFromPool();
            } else {
                $newDomain->setDomain((string) ($item['domain'] ?? ''));
            }
            $newDomain->setSubPath((string) ($item['sub_path'] ?? ''));
            $newDomain->setIsPrimary($isFirst);
            $newDomain->setStatus(WebsiteDomain::STATUS_ACTIVE);
            $newDomain->save();
            $isFirst = false;
        }

        $pool = $this->objectManager->getInstance(DomainPool::class);
        $pool->syncSiteCreatedFromWebsiteDomainTable();
    }

    private function getPrimarySubPathForWebsite(int $websiteId): string
    {
        /** @var WebsiteDomain $model */
        $model = $this->objectManager->getInstance(WebsiteDomain::class);
        $rows = $model->getWebsiteDomains($websiteId);
        foreach ($rows as $row) {
            $isPrimary = (bool) ($row[WebsiteDomain::schema_fields_IS_PRIMARY] ?? false);
            if ($isPrimary) {
                return $this->normalizeSubPath((string) ($row[WebsiteDomain::schema_fields_SUB_PATH] ?? ''));
            }
        }
        $first = $rows[0][WebsiteDomain::schema_fields_SUB_PATH] ?? '';
        return $this->normalizeSubPath((string) $first);
    }
}
