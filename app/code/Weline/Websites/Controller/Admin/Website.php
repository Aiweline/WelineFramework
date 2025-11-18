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
use Weline\Websites\Model\WebsiteLanguage;

#[Acl('Weline_Websites::website', '网站管理', 'mdi mdi-web', '网站管理')]
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
        
        // 获取每个网站的关联货币和语言
        $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
        $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
        
        foreach ($items as &$website) {
            $websiteId = $website['website_id'];
            // 获取关联货币
            $currencyCodes = $websiteCurrency->getWebsiteCurrencyCodes($websiteId);
            $website['currency_codes'] = $currencyCodes;
            
            // 获取关联语言
            $languageCodes = $websiteLanguage->getWebsiteLanguageCodes($websiteId);
            $website['language_codes'] = $languageCodes;
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
            
            // 获取每个网站的关联货币和语言
            $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
            $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
            
            foreach ($items as &$website) {
                $websiteId = $website['website_id'];
                // 获取关联货币
                $currencyCodes = $websiteCurrency->getWebsiteCurrencyCodes($websiteId);
                $website['currency_codes'] = $currencyCodes;
                
                // 获取关联语言
                $languageCodes = $websiteLanguage->getWebsiteLanguageCodes($websiteId);
                $website['language_codes'] = $languageCodes;
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
        if ($this->request->isPost()) {
            $data = $this->request->getPost();
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
                
                // 保存网站基本信息
                $this->website->setData($data)->save();
                $websiteId = $this->website->getWebsiteId();
                
                // 保存关联货币
                $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
                $websiteCurrency->setWebsiteCurrencies($websiteId, $currencyCodes);
                
                // 保存关联语言
                $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
                $websiteLanguage->setWebsiteLanguages($websiteId, $languageCodes);
                
                $this->redirect('/component/offcanvas/success', [
                    'msg' => __('网站添加成功'),
                    'url' => '*/admin/website',
                    'reload' => '1',
                    'time' => '3',
                ]);
            } catch (\Exception $e) {
                $this->redirect('/component/offcanvas/error', [
                    'msg' => __('网站添加失败: %{1}', $e->getMessage()),
                    'url' => '*/admin/website/add',
                    'reload' => '1',
                    'time' => '3',
                ]);
            }
        }
        
        // 初始化空网站数据，避免模板中访问未定义变量
        $this->assign('website', []);
        $this->assign('selected_currencies', []);
        $this->assign('selected_languages', []);
        
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
        $websiteId = $this->request->getParam('id');
        
        if (empty($websiteId)) {
            MessageManager::error(__('网站ID不能为空'));
            $this->redirect('*/admin/website');
            return;
        }
        
        // 转换为整数类型
        $websiteId = (int)$websiteId;
        
        $this->website->load($websiteId);
        
        // 检查网站是否存在
        if (!$this->website->getWebsiteId()) {
            MessageManager::error(__('网站不存在'));
            $this->redirect('*/admin/website');
            return;
        }

        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            
            // 从 POST 数据中获取 website_id，如果没有则从 URL 参数中获取 id
            $postWebsiteId = $data['website_id'] ?? null;
            if (empty($postWebsiteId)) {
                $postWebsiteId = $this->request->getParam('id');
            }
            
            // 如果还是没有，说明是新增，不是编辑
            if (empty($postWebsiteId)) {
                MessageManager::error(__('网站ID不能为空'));
                $this->redirect('/component/offcanvas/error', [
                    'msg' => __('网站ID不能为空'),
                    'url' => '*/admin/website/edit',
                    'reload' => '0',
                    'time' => '3',
                ]);
                return;
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
                
                // 保存网站基本信息
                $this->website->addData($data)->save();
                
                // 保存关联货币
                try {
                    $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
                    $websiteCurrency->setWebsiteCurrencies($postWebsiteId, $currencyCodes);
                } catch (\Exception $e) {
                    // 如果关联表不存在，忽略错误
                    MessageManager::warning(__('保存关联货币失败: %{1}', $e->getMessage()));
                }
                
                // 保存关联语言
                try {
                    $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
                    $websiteLanguage->setWebsiteLanguages($postWebsiteId, $languageCodes);
                } catch (\Exception $e) {
                    // 如果关联表不存在，忽略错误
                    MessageManager::warning(__('保存关联语言失败: %{1}', $e->getMessage()));
                }
                
                $this->redirect('/component/offcanvas/success',
                    [
                        'msg' => __('网站更新成功'),
                        'url' => '*/admin/website',
                        'reload' => '1',
                        'time' => '3',
                    ]);
            } catch (\Exception $e) {
                MessageManager::exception($e);
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

    #[Acl('Weline_Websites::website_delete', '删除网站', 'mdi mdi-delete', '网站管理')]
    public function deleteDelete(): string
    {
        $websiteId = $this->request->getGet('id');
        try {
            $this->website->load($websiteId);
            
            // 检查是否是默认网站，默认网站不允许删除
            if ($this->website->getCode() === 'default') {
                return $this->fetchJson([
                    'success' => false,
                    'code' => 403,
                    'msg' => __('默认网站不允许删除'),
                ]);
            }
            
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
    private function getAllCurrencies(): array
    {
        try {
            $currencyModel = ObjectManager::getInstance(Currency::class);
            $currencies = $currencyModel->clearQuery()
                ->where(Currency::fields_STATUS, 1)
                ->order(Currency::fields_CODE, 'ASC')
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
            ->where(Locals::fields_TARGET_CODE, $targetCode)
            ->where(Locals::fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();
        
        $i18n = ObjectManager::getInstance(\Weline\I18n\Model\I18n::class);
        
        // 如果根据 target_code 查不到数据，则查询所有已安装的语言（不限制 target_code）
        if (!$locales) {
            $allLocales = $localsModel
                ->clearQuery()
                ->where(Locals::fields_IS_INSTALL, 1)
                ->where(Locals::fields_IS_ACTIVE, 1)
                ->order(Locals::fields_CODE, 'ASC')
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
}


