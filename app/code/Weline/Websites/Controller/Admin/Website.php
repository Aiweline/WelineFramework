<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Admin;

use Weline\Currency\Model\Currency;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locals;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Model\DomainPool;

#[Acl('Weline_Websites::website', '网站管理', 'mdi mdi-web', '网站管理', 'Weline_Websites::website_service')]
class Website extends BackendController
{
    private \Weline\Websites\Model\Website $website;

    public function __construct(\Weline\Websites\Model\Website $website)
    {
        $this->website = $website;
    }

    #[Acl('Weline_Websites::website_list', '网站列表', 'mdi mdi-view-list', '网站管理')]
    public function index()
    {
        // 如果是 AJAX 请求，返回 JSON 数据
        if ($this->request->isAjax()) {
            return $this->searchAjax();
        }
        
        // 搜索功能
        $search = $this->request->getGet('search', '');
        if ($search) {
            $searchPattern = '%' . $search . '%';
            $this->website->where('name', $searchPattern, 'LIKE')
                ->where('code', $searchPattern, 'LIKE', 'OR')
                ->where('url', $searchPattern, 'LIKE', 'OR');
        }
        
        $websites = $this->website->order()->pagination()->select()->fetch();
        $items = $websites->getItems();
        
        // 获取每个网站的关联货币、语言、域名
        $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
        $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
        $websiteDomain = ObjectManager::getInstance(WebsiteDomain::class);
        
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
        
        $this->assign('websites', $items);
        $this->assign('pagination', $websites->getPagination());
        $this->assign('search', $search);
        return $this->fetch();
    }

    /**
     * AJAX 搜索接口
     */
    private function searchAjax()
    {
        header('Content-Type: application/json; charset=utf-8');
        
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
            $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
            $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
            $websiteDomain = ObjectManager::getInstance(WebsiteDomain::class);
            
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
            $tableHtml = $this->fetch('Weline_Websites::templates/Admin/Website/table.phtml');
            
            echo json_encode([
                'success' => true,
                'html' => $tableHtml,
                'count' => count($items)
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    #[Acl('Weline_Websites::website_add', '添加网站', 'mdi mdi-plus', '网站管理')]
    public function add()
    {
        // 使用空白布局（适用于 offcanvas/弹窗）
        $this->layoutType = 'default.blank';
        
        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            $postData = $data;
            try {
                $poolIds = $data['pool_ids'] ?? '';
                $subPath = $this->normalizeSubPath((string)($data['sub_path'] ?? ''));
                $addressList = $this->buildAddressListFromPoolSelection($poolIds, $subPath);
                if (empty($addressList)) {
                    throw new \Exception(__('请至少选择一个域名'));
                }
                /** @var WebsiteDomain $domainModel */
                $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
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
                // 排序：当同时存在根域与 www 时，www 优先作为主 URL
                $addressList = $this->orderAddressListPreferredUrl($addressList);
                // 用第一个地址的域名生成默认 code（若未填）
                $firstDomain = $addressList[0]['domain'];
                if (empty(trim((string)($data['code'] ?? '')))) {
                    $data['code'] = $this->domainToCode($firstDomain);
                }
                // 用第一个地址作为主 URL（不再自动加 www，站点可关联多域名）
                $firstSubPath = $addressList[0]['sub_path'];
                $data['url'] = 'https://' . $firstDomain . $firstSubPath;
                
                // 处理关联货币和语言
                $currencyCodes = $data['currency_codes'] ?? [];
                $languageCodes = $data['language_codes'] ?? [];
                
                if (empty($data['default_currency']) && !empty($currencyCodes)) {
                    $data['default_currency'] = $currencyCodes[0];
                }
                if (empty($data['default_language']) && !empty($languageCodes)) {
                    $data['default_language'] = $languageCodes[0];
                }
                
                if (isset($data['website_id'])) {
                    unset($data['website_id']);
                }
                unset($data['address_lines'], $data['domain_values'], $data['pool_ids'], $data['sub_path']);
                $this->stripExtensionPostData($data);
                $this->website->clearData()->setData($data)->save();
                $websiteId = $this->website->getId();
                
                if (empty($websiteId)) {
                    throw new \Exception(__('网站保存失败，未能获取网站ID'));
                }
                
                $this->saveWebsiteDomains((int)$websiteId, $addressList);
                
                $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
                $websiteCurrency->setWebsiteCurrencies((int)$websiteId, $currencyCodes);
                
                $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
                $websiteLanguage->setWebsiteLanguages((int)$websiteId, $languageCodes);

                $this->dispatchWebsiteSaveAfter((int)$websiteId, 'add', $this->website->getData(), $postData, $addressList);
                
                $this->redirect('/component/offcanvas/success', [
                    'msg' => __('网站添加成功'),
                    'url' => '*/admin/website',
                    'reload' => '1',
                    'time' => '3',
                ]);
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                // 开发环境显示完整堆栈
                if (DEV) {
                    $errorMsg .= "\n\n[File] " . $e->getFile() . ':' . $e->getLine();
                }
                $this->redirect('/component/offcanvas/error', [
                    'msg' => __('网站添加失败: %{1}', [$errorMsg]),
                    'url' => '/',
                    'reload' => '0',
                    'time' => '10',
                ]);
            }
        }
        
        // 初始化空网站数据，避免模板中访问未定义变量
        $this->assign('website', []);
        $this->assign('selected_currencies', []);
        $this->assign('selected_languages', []);
        $this->assign('selected_pool_ids', []);
        $this->assign('domain_options', $this->getDomainOptions());
        $this->assign('sub_path', '');
        
        // 获取所有货币
        $this->assign('currencies', $this->getAllCurrencies());
        
        // 获取所有语言
        $this->assign('locales', $this->getAllLocales());
        
        // 时区
        $timezones = \DateTimeZone::listIdentifiers();
        sort($timezones);
        $this->assign('timezones', $timezones);
        return $this->fetch('form');
    }

    #[Acl('Weline_Websites::website_edit', '编辑网站', 'mdi mdi-pencil', '网站管理')]
    public function edit()
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
            return;
        }
        
        // 转换为整数类型
        $websiteId = (int)$websiteId;
        
        $this->website->load($websiteId);
        
        // 检查网站是否存在
        if (!$this->website->getWebsiteId()) {
            $this->redirect('/component/offcanvas/error', [
                'msg' => __('网站不存在'),
                'reload' => '0',
                'time' => '3',
            ]);
            return;
        }

        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            $postData = $data;
            
            // 从 POST 数据中获取 website_id，如果没有则从 URL 参数中获取 id，最后使用已加载的 websiteId
            $postWebsiteId = $data['website_id'] ?? null;
            if (empty($postWebsiteId)) {
                $postWebsiteId = $this->request->getParam('id');
            }
            if (empty($postWebsiteId)) {
                $postWebsiteId = $websiteId;
            }
            
            // 如果还是没有，说明是新增，不是编辑
            if (empty($postWebsiteId)) {
                $this->redirect('/component/offcanvas/error', [
                    'msg' => __('网站ID不能为空'),
                    'reload' => '0',
                    'time' => '3',
                ]);
                return;
            }
            
            $postWebsiteId = (int)$postWebsiteId;
            
            try {
                $poolIds = $data['pool_ids'] ?? '';
                $subPath = $this->normalizeSubPath((string)($data['sub_path'] ?? ''));
                $addressList = $this->buildAddressListFromPoolSelection($poolIds, $subPath);
                if (empty($addressList)) {
                    throw new \Exception(__('请至少选择一个域名'));
                }
                /** @var WebsiteDomain $domainModel */
                $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
                foreach ($addressList as $item) {
                    $conflict = $domainModel->findConflict($item['domain'], $item['sub_path'], $postWebsiteId);
                    if ($conflict !== null) {
                        if ($item['sub_path'] === '') {
                            throw new \Exception(
                                __('该域名根路径已被站点「%{1}」使用，请使用子路径（如 /shop）', [$conflict['website_name']])
                            );
                        }
                        throw new \Exception(
                            __('该地址 %{1} 已被站点「%{2}」使用', [$item['domain'] . $item['sub_path'], $conflict['website_name']])
                        );
                    }
                }
                $addressList = $this->orderAddressListPreferredUrl($addressList);
                $firstDomain = $addressList[0]['domain'];
                $firstSubPath = $addressList[0]['sub_path'];
                $data['url'] = 'https://' . $firstDomain . $firstSubPath;
                
                // 处理关联货币和语言
                $currencyCodes = $data['currency_codes'] ?? [];
                $languageCodes = $data['language_codes'] ?? [];
                
                if (empty($data['default_currency']) && !empty($currencyCodes)) {
                    $data['default_currency'] = $currencyCodes[0];
                }
                if (empty($data['default_language']) && !empty($languageCodes)) {
                    $data['default_language'] = $languageCodes[0];
                }
                
                $data['website_id'] = $postWebsiteId;
                unset($data['address_lines'], $data['domain_values'], $data['pool_ids'], $data['sub_path']);
                $this->stripExtensionPostData($data);
                $this->website->addData($data)->save();
                
                $this->saveWebsiteDomains($postWebsiteId, $addressList);
                
                try {
                    $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
                    $websiteCurrency->setWebsiteCurrencies($postWebsiteId, $currencyCodes);
                } catch (\Exception $e) {
                    MessageManager::warning(__('保存关联货币失败: %{1}', [$e->getMessage()]));
                }
                
                try {
                    $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
                    $websiteLanguage->setWebsiteLanguages($postWebsiteId, $languageCodes);
                } catch (\Exception $e) {
                    MessageManager::warning(__('保存关联语言失败: %{1}', [$e->getMessage()]));
                }
                
                $this->dispatchWebsiteSaveAfter($postWebsiteId, 'edit', $this->website->getData(), $postData, $addressList);
                
                $this->redirect('/component/offcanvas/success', [
                    'msg' => __('网站更新成功'),
                    'url' => '*/admin/website',
                    'reload' => '1',
                    'time' => '3',
                ]);
            } catch (\Exception $e) {
                $this->redirect('/component/offcanvas/error', [
                    'msg' => $e->getMessage(),
                    'reload' => '0',
                    'time' => '5',
                ]);
            }
        }

        // 获取网站的关联货币和语言
        $selectedCurrencies = [];
        $selectedLanguages = [];
        
        try {
            $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
            $selectedCurrencies = $websiteCurrency->getWebsiteCurrencyCodes($websiteId);
        } catch (\Exception $e) {
            // 如果关联表不存在，使用空数组
            $selectedCurrencies = [];
        }
        
        try {
            $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
            $selectedLanguages = $websiteLanguage->getWebsiteLanguageCodes($websiteId);
        } catch (\Exception $e) {
            // 如果关联表不存在，使用空数组
            $selectedLanguages = [];
        }
        
        $this->assign('website', $this->website->getData());
        $this->assign('selected_currencies', $selectedCurrencies);
        $this->assign('selected_languages', $selectedLanguages);
        $selectedPoolIds = [];
        try {
            $websiteDomain = ObjectManager::getInstance(WebsiteDomain::class);
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
        $this->assign('domain_options', $this->getDomainOptions());
        $this->assign('sub_path', $this->getPrimarySubPathForWebsite($websiteId));
        
        // 获取所有货币
        $this->assign('currencies', $this->getAllCurrencies());
        
        // 获取所有语言
        $this->assign('locales', $this->getAllLocales());
        
        // 时区
        $timezones = \DateTimeZone::listIdentifiers();
        sort($timezones);
        $this->assign('timezones', $timezones);
        return $this->fetch('form');
    }

    /**
     * 快速创建站点（AJAX接口）
     * 与 add 保持一致：支持 address_lines + pool_ids 多地址逻辑
     * 兼容旧参数：仅传 url 时转为单行 address_lines
     */
    #[Acl('Weline_Websites::website_quick_save', '快速创建站点', '', '快速创建站点')]
    public function quickSave()
    {
        try {
            $postData = $this->request->getPost();
            $name = trim((string) $this->request->getPost('name', ''));
            $code = trim((string) $this->request->getPost('code', ''));
            $addressLines = trim((string) $this->request->getPost('address_lines', ''));
            $poolIds = trim((string) $this->request->getPost('pool_ids', ''));
            $url = trim((string) $this->request->getPost('url', ''));
            $defaultTimezone = (string) $this->request->getPost('default_timezone', 'Asia/Shanghai');
            $scope = trim((string) $this->request->getPost('scope', ''));
            
            if (empty($name)) {
                return $this->fetchJson(['success' => false, 'message' => __('站点名称不能为空')]);
            }
            if (empty($code)) {
                return $this->fetchJson(['success' => false, 'message' => __('站点代码不能为空')]);
            }
            if (empty($addressLines) && empty($poolIds)) {
                if (empty($url)) {
                    return $this->fetchJson(['success' => false, 'message' => __('请填写网站地址或选择域名')]);
                }
                $url = preg_replace('#^https?://#i', '', rtrim($url, '/'));
                $addressLines = $url;
            }
            
            $addressList = $this->parseAddressLines($addressLines, $poolIds);
            if (empty($addressList)) {
                return $this->fetchJson(['success' => false, 'message' => __('请至少填写一个网站地址（域名或域名/子路径）')]);
            }
            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            foreach ($addressList as $item) {
                $conflict = $domainModel->findConflict($item['domain'], $item['sub_path'], null);
                if ($conflict !== null) {
                    $addr = $item['domain'] . $item['sub_path'];
                    throw new \Exception(
                        $item['sub_path'] === ''
                            ? __('该域名根路径已被站点「%{1}」使用，请使用子路径（如 /shop）', [$conflict['website_name']])
                            : __('该地址 %{1} 已被站点「%{2}」使用', [$addr, $conflict['website_name']])
                    );
                }
            }
            $addressList = $this->orderAddressListPreferredUrl($addressList);
            $firstDomain = $addressList[0]['domain'];
            $firstSubPath = $addressList[0]['sub_path'];
            
            $existingWebsite = clone $this->website;
            $existingWebsite->clear()
                ->where(\Weline\Websites\Model\Website::schema_fields_CODE, $code)
                ->find()
                ->fetch();
            if ($existingWebsite->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('站点代码已存在')]);
            }
            
            $primaryUrl = 'https://' . $firstDomain . $firstSubPath;
            $newWebsite = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $newWebsite->clearData()  // 清除所有数据
                ->setData(\Weline\Websites\Model\Website::schema_fields_NAME, $name)
                ->setData(\Weline\Websites\Model\Website::schema_fields_CODE, $code)
                ->setData(\Weline\Websites\Model\Website::schema_fields_URL, $primaryUrl)
                ->setData(\Weline\Websites\Model\Website::schema_fields_DEFAULT_TIMEZONE, $defaultTimezone);
            
            // 设置业务范围标识
            if (!empty($scope)) {
                $newWebsite->setData(\Weline\Websites\Model\Website::schema_fields_SCOPE, $scope);
            }
            
            // 确保主键字段被清除（防止主键冲突）
            if ($newWebsite->hasData(\Weline\Websites\Model\Website::schema_fields_ID)) {
                $newWebsite->unsetData(\Weline\Websites\Model\Website::schema_fields_ID);
            }
            
            $newWebsite->save(true);
            $websiteId = (int) $newWebsite->getId();
            if ($websiteId <= 0) {
                throw new \Exception(__('网站保存失败，未能获取网站ID'));
            }
            $this->saveWebsiteDomains($websiteId, $addressList);
            $this->dispatchWebsiteSaveAfter($websiteId, 'quick_save', $newWebsite->getData(), $postData, $addressList);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('站点创建成功'),
                'website' => [
                    'website_id' => $websiteId,
                    'name' => $newWebsite->getData(\Weline\Websites\Model\Website::schema_fields_NAME),
                    'code' => $newWebsite->getData(\Weline\Websites\Model\Website::schema_fields_CODE),
                    'url' => $primaryUrl,
                    'scope' => $newWebsite->getData(\Weline\Websites\Model\Website::schema_fields_SCOPE) ?? '',
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('创建失败：') . $e->getMessage(),
            ]);
        }
    }

    #[Acl('Weline_Websites::website_delete', '删除网站', 'mdi mdi-delete', '网站管理')]
    public function deleteDelete(): string
    {
        $websiteId = $this->request->getGet('id');
        try {
            $this->website->load($websiteId);
            
            // 检查是否是默认网站，默认网站不允许删除（与 Model::delete_before 底层拦截一致）
            if ($this->website->getCode() === \Weline\Websites\Model\Website::CODE_DEFAULT) {
                return $this->fetchJson([
                    'success' => false,
                    'code' => 403,
                    'msg' => __('默认网站不允许删除'),
                ]);
            }
            
            $websiteId = (int) $this->website->getData('website_id');
            // 删除站点前解绑域名：删除该站点下所有 website_domain 并同步域名池 site_created
            $websiteDomainModel = ObjectManager::getInstance(WebsiteDomain::class);
            $websiteDomainModel->clearQuery()
                ->where(WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
                ->delete()
                ->fetch();
            $domainPool = ObjectManager::getInstance(DomainPool::class);
            $domainPool->syncSiteCreatedFromWebsiteDomainTable();
            
            $this->website->delete()->fetch();
            return $this->fetchJson([
                'code' => 200,
                'success' => true,
                'msg' => __('网站删除成功'),
                'reload' => '1',
                'url' => '*/admin/website',
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
     * 
     * @return array
     */
    private function stripExtensionPostData(array &$data): void
    {
        unset(
            $data['extensions']
        );
    }

    /**
     * @param array<string, mixed> $websiteData
     * @param array<string, mixed> $postData
     * @param array<int, array<string, string>> $addressList
     */
    private function dispatchWebsiteSaveAfter(
        int $websiteId,
        string $action,
        array $websiteData,
        array $postData,
        array $addressList = []
    ): void {
        if ($websiteId <= 0) {
            return;
        }

        try {
            $eventsManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $eventsManager->dispatch('Weline_Websites::website_save_after', [
                'website_id' => $websiteId,
                'website' => $websiteData,
                'post_data' => $postData,
                'address_list' => $addressList,
                'action' => $action,
            ]);
        } catch (\Throwable $e) {
            w_log_error(sprintf(
                '[Weline_Websites] website_save_after dispatch failed: website_id=%d, action=%s, error=%s',
                $websiteId,
                $action,
                $e->getMessage()
            ));
        }
    }

    private function getAllCurrencies(): array
    {
        try {
            $currencyModel = ObjectManager::getInstance(Currency::class);
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
     * 
     * @return array
     */
    private function getAllLocales(): array
    {
        $targetCode = Cookie::getLangLocal();
        $localsModel = ObjectManager::getInstance(Locals::class);
        $locales = $localsModel
            ->clearQuery()
            ->where(Locals::schema_fields_TARGET_CODE, $targetCode)
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();
        
        $i18n = ObjectManager::getInstance(\Weline\I18n\Model\I18n::class);
        
        // 如果根据 target_code 查不到数据，则查询所有已安装的语言（不限制 target_code）
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
                // 按 code 去重，获取唯一的语言代码列表
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
                // 如果 target_code 匹配，使用数据库中的名称；否则使用 Symfony Intl 获取
                if ($locale['target_code'] !== $targetCode) {
                    $locale['name'] = $i18n->getLocaleName($locale['code'], $targetCode);
                }
            }
        }
        
        return $locales ?: [];
    }

    /**
     * 获取域名选项（用于多选，来自域名池）
     */
    private function getDomainOptions(): array
    {
        try {
            $pool = ObjectManager::getInstance(DomainPool::class);
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
        $domains = array_column($addressList, 'domain');
        $hasWww = false;
        $hasRoot = false;
        foreach ($domains as $d) {
            if (str_starts_with($d, 'www.')) {
                $hasWww = true;
                $root = substr($d, 4);
                if (in_array($root, $domains, true)) {
                    $hasRoot = true;
                    break;
                }
            }
        }
        if (!$hasWww || !$hasRoot) {
            return $addressList;
        }
        usort($addressList, function ($a, $b) {
            $da = $a['domain'];
            $db = $b['domain'];
            $rootA = str_starts_with($da, 'www.') ? substr($da, 4) : $da;
            $rootB = str_starts_with($db, 'www.') ? substr($db, 4) : $db;
            if ($rootA !== $rootB) {
                return 0;
            }
            return str_starts_with($da, 'www.') ? -1 : 1;
        });
        return $addressList;
    }

    /**
     * 解析「网站地址」多行文本为 [['domain' => string, 'sub_path' => string, 'pool_id' => int], ...]
     * 每行：域名 或 域名/子路径（子路径自动加前导 /）
     * 自动去重：相同 domain + sub_path 组合只保留一个
     * 
     * v1.6.0: 支持 pool_ids 参数，从域名池关联域名
     * - 如果提供了 pool_ids，优先使用 pool_id 关联
     * - pool_ids 格式：逗号分隔的 pool_id 列表
     * 
     * @param string $text 多行地址文本
     * @param string $poolIds 逗号分隔的 pool_id 列表（可选）
     */
    private function parseAddressLines(string $text, string $poolIds = ''): array
    {
        $list = [];
        $seen = [];  // 用于去重
        
        // v1.6.0: 如果提供了 pool_ids，从域名池获取域名
        if (!empty($poolIds)) {
            $poolIdArray = array_filter(array_map('intval', explode(',', $poolIds)));
            if (!empty($poolIdArray)) {
                /** @var DomainPool $poolModel */
                $poolModel = ObjectManager::getInstance(DomainPool::class);
                foreach ($poolIdArray as $poolId) {
                    $pool = ObjectManager::getInstance(DomainPool::class, [], false);
                    $pool->loadByPoolId($poolId);
                    if ($pool->getPoolId()) {
                        $domain = strtolower($pool->getDomain());
                        $key = $domain . '|';
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $list[] = [
                                'domain' => $domain,
                                'sub_path' => '',
                                'pool_id' => $poolId,
                            ];
                        }
                    }
                }
            }
        }
        
        // 解析多行文本（传统方式）
        $lines = \preg_split('/\r\n|\r|\n/', $text, -1, \PREG_SPLIT_NO_EMPTY);
        foreach ($lines as $line) {
            $line = \trim($line);
            if ($line === '') {
                continue;
            }
            // 去掉协议前缀（http:// 或 https://）
            $line = \preg_replace('#^https?://#i', '', $line);
            $line = \trim($line, "/ \t");
            if ($line === '') {
                continue;
            }
            $pos = \strpos($line, '/');
            if ($pos === false) {
                $domain = \strtolower($line);
                $subPath = '';
            } else {
                $domain = \strtolower(\substr($line, 0, $pos));
                $subPath = '/' . \trim(\substr($line, $pos), '/');
                if ($subPath === '/') {
                    $subPath = '';
                }
            }
            if ($domain !== '') {
                // 去重：相同 domain + sub_path 只保留一个
                $key = $domain . '|' . $subPath;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $list[] = ['domain' => $domain, 'sub_path' => $subPath, 'pool_id' => 0];
                }
            }
        }
        return $list;
    }

    /**
     * 从域名池选择构建站点地址列表，所有选中域名共享同一个子路径。
     */
    private function buildAddressListFromPoolSelection(array|string $poolIds, string $subPath = ''): array
    {
        $list = [];
        $seen = [];
        $subPath = $this->normalizeSubPath($subPath);
        $poolIdArray = \is_array($poolIds)
            ? \array_values(\array_filter(\array_map('intval', $poolIds)))
            : \array_values(\array_filter(\array_map('intval', \explode(',', (string) $poolIds))));
        foreach ($poolIdArray as $poolId) {
            /** @var DomainPool $pool */
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            $pool->loadByPoolId((int) $poolId);
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
                'pool_id' => (int) $poolId,
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

    /**
     * 域名转网站 code：小写，点换下划线
     */
    private function domainToCode(string $domain): string
    {
        return \str_replace('.', '_', \strtolower(\trim($domain)));
    }

    /**
     * 保存站点的域名列表（先删后增，第一个为主域名）
     * 
     * v1.6.0: 支持 pool_id 关联方式
     * - 如果 item 包含 pool_id，优先使用 pool_id 关联并从 DomainPool 同步数据
     * - 否则使用传统的 domain 字符串方式
     */
    private function saveWebsiteDomains(int $websiteId, array $addressList): void
    {
        /** @var WebsiteDomain $model */
        $model = ObjectManager::getInstance(WebsiteDomain::class);
        $model->clearQuery()
            ->where(WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();
        $isFirst = true;
        foreach ($addressList as $item) {
            /** @var WebsiteDomain $newDomain */
            $newDomain = ObjectManager::getInstance(WebsiteDomain::class, [], false);
            $newDomain->setWebsiteId($websiteId);
            
            // v1.6.0: 支持 pool_id 关联
            $poolId = (int)($item['pool_id'] ?? 0);
            if ($poolId > 0) {
                $newDomain->setPoolId($poolId);
                $newDomain->syncFromPool();
            } else {
                $newDomain->setDomain($item['domain']);
            }
            
            $newDomain->setSubPath($item['sub_path']);
            $newDomain->setIsPrimary($isFirst);
            $newDomain->setStatus(WebsiteDomain::STATUS_ACTIVE);
            $newDomain->save();
            $isFirst = false;
        }
        // 同步域名池 site_created 状态（已建站的域名创建站点时不再展示）
        $pool = ObjectManager::getInstance(DomainPool::class);
        $pool->syncSiteCreatedFromWebsiteDomainTable();
    }

    /**
     * 将站点已有域名格式化为多行文本（用于编辑页 address_lines）
     */
    private function getPrimarySubPathForWebsite(int $websiteId): string
    {
        /** @var WebsiteDomain $model */
        $model = ObjectManager::getInstance(WebsiteDomain::class);
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


