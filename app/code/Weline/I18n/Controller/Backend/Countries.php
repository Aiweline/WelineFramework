<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/22 14:37:13
 */

namespace Weline\I18n\Controller\Backend;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Cache\PhraseCache;
use Weline\I18n\Cache\I18nCache;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\I18n\Service\CountryUpdateService;

class Countries extends BaseController
{
    private \Weline\I18n\Model\Countries $countries;
    /**
     * @var \Weline\I18n\Model\Countries\Locale\Name
     */
    private Name $localeNames;

    public function __construct(
        Locale                       $locale,
        I18n                         $i18n,
        \Weline\I18n\Model\Countries $countries,
        Name                         $localeName
    )
    {
        parent::__construct($locale, $i18n);
        $this->countries = $countries;
        // 使用LEFT JOIN获取国家显示名称
        $this->countries->joinModel(Name::class, 'cln', 'main_table.code=cln.country_code', 'left');
        $this->localeNames = $localeName;
    }

    public function __init()
    {
        parent::__init();
        if ($search = $this->request->getGet('search')) {
            $search = addslashes($search);
            $code = $this->countries::fields_CODE;
            $name = Name::fields_DISPLAY_NAME;
            $this->countries->concat_like("main_table.{$code},cln.{$name}", "%{$search}%");
        }
        
        // 高级搜索条件
        if ($searchCode = $this->request->getGet('search_code')) {
            $searchCode = addslashes($searchCode);
            $this->countries->like($this->countries::fields_CODE, "%{$searchCode}%");
        }
        
        if ($searchName = $this->request->getGet('search_name')) {
            $searchName = addslashes($searchName);
            $this->countries->like('cln.' . Name::fields_DISPLAY_NAME, "%{$searchName}%");
        }
        
        if ($searchStatus = $this->request->getGet('search_status')) {
            switch ($searchStatus) {
                case 'active':
                    $this->countries->where($this->countries::fields_IS_INSTALL, 1)
                                   ->where($this->countries::fields_IS_ACTIVE, 1);
                    break;
                case 'inactive':
                    $this->countries->where($this->countries::fields_IS_INSTALL, 1)
                                   ->where($this->countries::fields_IS_ACTIVE, 0);
                    break;
                case 'installed':
                    $this->countries->where($this->countries::fields_IS_INSTALL, 1);
                    break;
                case 'uninstalled':
                    $this->countries->where($this->countries::fields_IS_INSTALL, 0);
                    break;
            }
        }
        
        // 暂时移除有问题的locale_code引用
        $this->countries->where('cln.' . $this->localeNames::fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal());
    }

    public function index()
    {
        // 获取筛选参数
        $filter = $this->request->getGet('filter', 'active'); // 默认显示已激活的
        $search = $this->request->getGet('search');
        
        // 构建查询条件
        $query = $this->countries;
        
        // 根据筛选条件添加查询
        switch ($filter) {
            case 'active':
                $query->where(\Weline\I18n\Model\Countries::fields_IS_INSTALL, 1)
                      ->where(\Weline\I18n\Model\Countries::fields_IS_ACTIVE, 1);
                break;
            case 'inactive':
                $query->where(\Weline\I18n\Model\Countries::fields_IS_INSTALL, 1)
                      ->where(\Weline\I18n\Model\Countries::fields_IS_ACTIVE, 0);
                break;
            case 'installed':
                // 显示已安装的（包括激活和未激活）
                $query->where(\Weline\I18n\Model\Countries::fields_IS_INSTALL, 1);
                break;
            case 'uninstalled':
                // 显示未安装的
                $query->where(\Weline\I18n\Model\Countries::fields_IS_INSTALL, 0);
                break;
            case 'all':
                // 显示所有国家（包括已安装和未安装的）
                // 不添加任何条件，显示所有记录
                break;
        }
        
        // 执行查询
        # 拷贝查询条件
        $query_copy = clone $query;
        $installed_countries = $query->pagination()->select()->fetch();
        # 查不到数据就更新
        if ($installed_countries->getTotal() == 0) {
            $updateService = ObjectManager::getInstance(\Weline\I18n\Service\CountryDataUpdateService::class);
            $updateService->updateCountryData();
            $installed_countries = $query_copy->pagination()->select()->fetch();
        }
        
        // 自动更新当前语言的显示数据（只有当前语言没有数据时才更新）
        $this->autoUpdateMissingLocaleData();
        
        $this->assign('countries', $installed_countries->getItems());
        $this->assign('countries_pagination', $installed_countries->getPagination());
        $this->assign('current_filter', $filter);
        $this->assign('search', $search);
        
        return $this->fetch();
    }

    /**
     * @DESC          # 更新国家
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/12/22 15:57
     * 参数区：
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function getUpdate()
    {
        $countries = $this->i18n->getCountries(Cookie::getLangLocal());
        $insert_countries = [];
        $insert_countries_display = [];
        foreach ($countries as $code => $country) {
            $insert_countries[] = [
                \Weline\I18n\Model\Countries::fields_CODE => $code,
                \Weline\I18n\Model\Countries::fields_FLAG => (string)$this->i18n->getCountryFlag($code),
                \Weline\I18n\Model\Countries::fields_IS_ACTIVE => 0,
                \Weline\I18n\Model\Countries::fields_IS_INSTALL => 0,
            ];
            $insert_countries_display[] = [
                Name::fields_COUNTRY_CODE => $code,
                Name::fields_DISPLAY_LOCALE_CODE => Cookie::getLangLocal(),
                Name::fields_DISPLAY_NAME => $country,
            ];
        }
        $this->countries->beginTransaction();
        try {
            // 安装国家数据
            $this->countries->clearQuery()->insert($insert_countries, \Weline\I18n\Model\Countries::fields_CODE)->fetch();
            // 安装显示数据
            $this->localeNames->insert($insert_countries_display, [
                $this->localeNames::fields_COUNTRY_CODE,
                $this->localeNames::fields_DISPLAY_LOCALE_CODE,
                $this->localeNames::fields_DISPLAY_NAME
            ])->fetch();
            $this->countries->commit();
            Message::success(__('操作成功！更新%{1}记录。', [count($insert_countries)]));
        } catch (\Exception $exception) {
            $this->countries->rollBack();
            Message::exception($exception);
        }
        $this->redirect($this->request->getUrlBuilder()->getBackendUrl('*/backend/countries'));
    }

    public function install()
    {
        if ($this->request->isGet()) {
            $this->countries
                ->pagination()
                ->select()
                ->fetch();
            $this->assign('countries', $this->countries->getItems());
            $this->assign('pagination', $this->countries->getPagination());
            return $this->fetch();
        }
        if ($this->request->isPost()) {
            $code = $this->request->getPost('code');
            try {
                $this->countries->clearQuery();
                $this->countries->load($this->countries::fields_CODE, $code);
                if ($this->countries->getId()) {
                    $this->countries->setData($this->countries::fields_IS_INSTALL, 1)->save(true);
                    $this->getMessageManager()->addSuccess(__('成功安装!国家：%{1}(%{2})', [$this->countries->getData($this->localeNames::fields_DISPLAY_NAME),
                        $this->countries->getData($this->countries::fields_CODE)]));
                } else {
                    $this->getMessageManager()->addWarning(__('国家不存在！国家代码：%{1}', $code));
                }
            } catch (\Exception $exception) {
                $this->getMessageManager()->addException($exception);
            }
            $this->redirect('*/backend/countries?filter=installed');
        } else {
            $this->getMessageManager()->addError(__('请求错误！'));
            $this->redirect(404);
        }
    }

    public function postUninstall()
    {
        $code = $this->request->getPost('code');
        try {
            $this->countries->clearQuery()->where($this->countries::fields_CODE, $code)
                ->find()
                ->fetch();
            if ($this->countries->getId()) {
                $this->countries->setData($this->countries::fields_IS_INSTALL, 0)->save(true);
                $this->countries->getLocaleModel()->where(Locale::fields_COUNTRY_CODE, $code)
                    ->update([Locale::fields_IS_INSTALL => 0, Locale::fields_IS_ACTIVE => 0])
                    ->fetch();
                $this->getMessageManager()->addSuccess(__('成功卸载!国家：%{1}(%{2})', [$this->countries->getData(Name::fields_DISPLAY_NAME),
                    $this->countries->getData($this->countries::fields_CODE)]));
            } else {
                $this->getMessageManager()->addWarning(__('国家不存在！国家代码：%{1}', $code));
            }
        } catch (\Exception $exception) {
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/countries');
    }

    public function postActive()
    {
        $code = $this->request->getPost('code');
        $filter = $this->request->getPost('filter', 'active');
        if (!$code) {
            $this->getMessageManager()->addWarning(__('请选择国家激活！'));
            $this->redirect('*/backend/countries?filter=' . $filter);
        }
        try {
            $this->countries->clearQuery()->load($this->countries::fields_CODE, $code);
            if (!$this->countries->getId()) {
                $this->getMessageManager()->addWarning(__('国家不存在！国家代码：%{1}', $code));
                $this->redirect('*/backend/countries?filter=' . $filter);
            }
            $this->countries->setData($this->countries::fields_IS_ACTIVE, 1)->save(true);
            $this->getMessageManager()->addSuccess(__('成功激活国家！国家：%{1}（%{2}）', [$this->countries->getData(Name::fields_DISPLAY_NAME),
                $this->countries->getData($this->countries::fields_CODE)]));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/countries?filter=active');
    }

    public function postDisable()
    {
        $code = $this->request->getPost('code');
        $filter = $this->request->getPost('filter', 'active');
        if (!$code) {
            $this->getMessageManager()->addWarning(__('请选择国家禁用！'));
            $this->redirect('*/backend/countries?filter=' . $filter);
        }
        try {
            $this->countries->clearQuery();
            $this->countries->load($this->countries::fields_CODE, $code);
            if (!$this->countries->getId()) {
                $this->getMessageManager()->addWarning(__('国家不存在！国家代码：%{1}', $code));
                $this->redirect('*/backend/countries?filter=' . $filter);
            }
            $this->countries->setData($this->countries::fields_IS_ACTIVE, 0)->save(true);
            $this->getMessageManager()->addSuccess(__('成功禁用国家！国家：%{1}（%{2}）', [$this->countries->getData(Name::fields_DISPLAY_NAME),
                $this->countries->getData($this->countries::fields_CODE)]));
            // FIXME 禁用应当删除对应语言的翻译包
            $country_locales = $this->locale->where($this->locale::fields_COUNTRY_CODE, $code)->select()->fetch()->getItems();
            $pack_dir = Env::path_LANGUAGE_PACK;
            /**@var System $system */
            $system = ObjectManager::getInstance(System::class);
            /**@var */
            foreach ($country_locales as $country_locale) {
                $locale_dirs = glob($pack_dir . '*' . DS . $country_locale->getData($this->locale::fields_ID), GLOB_ONLYDIR);
                foreach ($locale_dirs as $locale_dir) {
                    $result = $system->exec('rm -rf ' . $locale_dir);
                }
            }
            // 清理i18n缓存
            /**@var \Weline\Framework\Cache\CacheInterface $i18n */
            $i18n = ObjectManager::getInstance(I18nCache::class . 'Factory');
            $i18n->clear();
            /**@var \Weline\Framework\Cache\CacheInterface $phrase */
            $phrase = ObjectManager::getInstance(PhraseCache::class . 'Factory');
            $phrase->clear();
            $this->getMessageManager()->addWarning(__('该国家下的所有安装包已删除！'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/countries?filter=' . $filter);
    }




    /**
     * 批量激活国家
     */
    public function batchActive()
    {
        $codes = $this->request->getPost('codes', []);
        $filter = $this->request->getPost('filter', 'active');
        
        if (empty($codes)) {
            $this->getMessageManager()->addWarning(__('请选择要激活的国家！'));
            $this->redirect('*/backend/countries?filter=' . $filter);
        }
        
        $successCount = 0;
        $errorCount = 0;
        
        try {
            $this->countries->beginTransaction();
            
            foreach ($codes as $code) {
                try {
                    $this->countries->clearQuery()->load($this->countries::fields_CODE, $code);
                    if ($this->countries->getId()) {
                        $this->countries->setData($this->countries::fields_IS_ACTIVE, 1)->save(true);
                        $successCount++;
            } else {
                        $errorCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                }
            }
            
            $this->countries->commit();
            
            if ($successCount > 0) {
                $this->getMessageManager()->addSuccess(__('成功激活%{1}个国家！', [$successCount]));
            }
            if ($errorCount > 0) {
                $this->getMessageManager()->addWarning(__('%{1}个国家激活失败！', [$errorCount]));
            }
            
        } catch (\Exception $e) {
            $this->countries->rollBack();
            $this->getMessageManager()->addException($e);
        }
        
        $this->redirect('*/backend/countries?filter=' . $filter);
    }

    /**
     * 批量禁用国家
     */
    public function batchDisable()
    {
        $codes = $this->request->getPost('codes', []);
        $filter = $this->request->getPost('filter', 'active');
        
        if (empty($codes)) {
            $this->getMessageManager()->addWarning(__('请选择要禁用的国家！'));
            $this->redirect('*/backend/countries?filter=' . $filter);
        }
        
        $successCount = 0;
        $errorCount = 0;
        
        try {
            $this->countries->beginTransaction();
            
            foreach ($codes as $code) {
                try {
                    $this->countries->clearQuery()->load($this->countries::fields_CODE, $code);
                    if ($this->countries->getId()) {
                        $this->countries->setData($this->countries::fields_IS_ACTIVE, 0)->save(true);
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                }
            }
            
            $this->countries->commit();
            
            if ($successCount > 0) {
                $this->getMessageManager()->addSuccess(__('成功禁用%{1}个国家！', [$successCount]));
            }
            if ($errorCount > 0) {
                $this->getMessageManager()->addWarning(__('%{1}个国家禁用失败！', [$errorCount]));
            }
            
        } catch (\Exception $e) {
            $this->countries->rollBack();
            $this->getMessageManager()->addException($e);
        }
        
        $this->redirect('*/backend/countries?filter=' . $filter);
    }

    /**
     * 批量安装国家
     */
    public function batchInstall()
    {
        $codes = $this->request->getPost('codes', []);
        $filter = $this->request->getPost('filter', 'all');
        
        if (empty($codes)) {
            $this->getMessageManager()->addWarning(__('请选择要安装的国家！'));
            $this->redirect('*/backend/countries?filter=' . $filter);
        }
        
        $successCount = 0;
        $errorCount = 0;
        
        try {
            $this->countries->beginTransaction();
            
            foreach ($codes as $code) {
                try {
                    $this->countries->clearQuery()->load($this->countries::fields_CODE, $code);
                    if ($this->countries->getId()) {
                        $this->countries->setData($this->countries::fields_IS_INSTALL, 1)->save(true);
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                }
            }
            
            $this->countries->commit();
            
            if ($successCount > 0) {
                $this->getMessageManager()->addSuccess(__('成功安装%{1}个国家！', [$successCount]));
            }
            if ($errorCount > 0) {
                $this->getMessageManager()->addWarning(__('%{1}个国家安装失败！', [$errorCount]));
            }
            
        } catch (\Exception $e) {
            $this->countries->rollBack();
            $this->getMessageManager()->addException($e);
        }
        
        $this->redirect('*/backend/countries?filter=' . $filter);
    }

    
    /**
     * 为中国安装并激活zh_Hans_CN区域
     */
    private function installChinaLocale(): void
    {
        $locale = ObjectManager::getInstance(\Weline\I18n\Model\Locale::class);
        $localeName = ObjectManager::getInstance(\Weline\I18n\Model\Locale\Name::class);
        
        // 检查zh_Hans_CN是否已存在
        $existingLocale = $locale->clearQuery()
            ->where(\Weline\I18n\Model\Locale::fields_CODE, 'zh_Hans_CN')
            ->where(\Weline\I18n\Model\Locale::fields_COUNTRY_CODE, 'CN')
            ->select()
            ->fetch()
            ->getFirst();
        
        if ($existingLocale && $existingLocale->getId()) {
            // 已存在，更新为已安装且已激活
            $existingLocale->setData(\Weline\I18n\Model\Locale::fields_IS_INSTALL, 1)
                          ->setData(\Weline\I18n\Model\Locale::fields_IS_ACTIVE, 1)
                          ->save(true);
        } else {
            // 不存在，创建新的区域记录
            $localeData = [
                \Weline\I18n\Model\Locale::fields_CODE => 'zh_Hans_CN',
                \Weline\I18n\Model\Locale::fields_COUNTRY_CODE => 'CN',
                \Weline\I18n\Model\Locale::fields_IS_INSTALL => 1,
                \Weline\I18n\Model\Locale::fields_IS_ACTIVE => 1,
                \Weline\I18n\Model\Locale::fields_FLAG => ''
            ];
            
            $locale->clearQuery()->insert($localeData, [\Weline\I18n\Model\Locale::fields_CODE, \Weline\I18n\Model\Locale::fields_COUNTRY_CODE])->fetch();
            
            // 创建区域名称记录
            $localeNameData = [
                \Weline\I18n\Model\Locale\Name::fields_LOCALE_CODE => 'zh_Hans_CN',
                \Weline\I18n\Model\Locale\Name::fields_DISPLAY_LOCALE_CODE => 'zh_Hans_CN',
                \Weline\I18n\Model\Locale\Name::fields_DISPLAY_NAME => '简体中文（中国）'
            ];
            
            $localeName->clearQuery()->insert($localeNameData, [
                \Weline\I18n\Model\Locale\Name::fields_LOCALE_CODE,
                \Weline\I18n\Model\Locale\Name::fields_DISPLAY_LOCALE_CODE
            ])->fetch();
        }
    }

    
    /**
     * 自动更新缺失的当前语言数据（优化版本，只检查当前语言）
     */
    private function autoUpdateMissingLocaleData(): void
    {
        try {
            $currentLang = Cookie::getLangLocal();
            
            // 首先检查当前语言是否已经有数据，如果有就直接返回
            $existingCount = $this->localeNames->clearQuery()
                ->where(Name::fields_DISPLAY_LOCALE_CODE, $currentLang)
                ->count();
            
            if ($existingCount > 0) {
                // 当前语言已有数据，无需更新
                return;
            }
            
            // 获取所有国家数据
            $allCountries = $this->countries->clearQuery()
                ->select()
                ->fetch()
                ->getItems();
            
            if (empty($allCountries)) {
                return;
            }
            
            // 获取I18n服务
            $i18n = ObjectManager::getInstance(\Weline\I18n\Model\I18n::class);
            
            // 获取当前语言的所有国家名称
            $countryNames = $i18n->getCountries($currentLang);
            
            if (empty($countryNames)) {
                return;
            }
            
            // 批量准备当前语言的国家数据
            $missingData = [];
            foreach ($allCountries as $country) {
                $countryCode = $country->getData(\Weline\I18n\Model\Countries::fields_CODE);
                
                if (isset($countryNames[$countryCode])) {
                    $missingData[] = [
                        Name::fields_COUNTRY_CODE => $countryCode,
                        Name::fields_DISPLAY_LOCALE_CODE => $currentLang,
                        Name::fields_DISPLAY_NAME => $countryNames[$countryCode]
                    ];
                }
            }
            
            // 批量插入当前语言的国家数据
            if (!empty($missingData)) {
                $this->localeNames->clearQuery()
                    ->insert($missingData, [
                        Name::fields_COUNTRY_CODE,
                        Name::fields_DISPLAY_LOCALE_CODE
                    ])
                    ->fetch();
            }
            
        } catch (\Exception $e) {
            // 静默处理异常，不影响页面显示
            error_log('Auto update missing locale data failed: ' . $e->getMessage());
        }
    }

}
