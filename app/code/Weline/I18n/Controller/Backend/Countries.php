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
        // 使用LEFT JOIN获取国家显示名称，明确选择 display_name 字段
        // 在 ON 条件中添加当前语言限制，优先显示当前语言的显示名称
        $currentLang = Cookie::getLangLocal();
        $joinCondition = "main_table.code=cln.country_code AND cln." . Name::schema_fields_DISPLAY_LOCALE_CODE . "='" . addslashes($currentLang) . "'";
        $this->countries->joinModel(
            Name::class, 
            'cln', 
            $joinCondition, 
            'left',
            'cln.' . Name::schema_fields_DISPLAY_NAME . ' as display_name, cln.' . Name::schema_fields_DISPLAY_LOCALE_CODE
        );
        $this->localeNames = $localeName;
    }

    public function __init()
    {
        parent::__init();
        if ($search = $this->request->getGet('search')) {
            $search = addslashes($search);
            $code = $this->countries::schema_fields_CODE;
            $name = Name::schema_fields_DISPLAY_NAME;
            $this->countries->concat_like("main_table.{$code},cln.{$name}", "%{$search}%");
        }
        
        // 高级搜索条件
        if ($searchCode = $this->request->getGet('search_code')) {
            $searchCode = addslashes($searchCode);
            $this->countries->like('main_table.' . $this->countries::schema_fields_CODE, "%{$searchCode}%");
        }
        
        if ($searchName = $this->request->getGet('search_name')) {
            $searchName = addslashes($searchName);
            $this->countries->like('cln.' . Name::schema_fields_DISPLAY_NAME, "%{$searchName}%");
        }
        
        if ($searchStatus = $this->request->getGet('search_status')) {
            // 注意：由于有 joinModel，需要使用 main_table 前缀
            switch ($searchStatus) {
                case 'active':
                    $this->countries->where('main_table.' . $this->countries::schema_fields_IS_INSTALL, 1)
                                   ->where('main_table.' . $this->countries::schema_fields_IS_ACTIVE, 1);
                    break;
                case 'inactive':
                    $this->countries->where('main_table.' . $this->countries::schema_fields_IS_INSTALL, 1)
                                   ->where('main_table.' . $this->countries::schema_fields_IS_ACTIVE, 0);
                    break;
                case 'installed':
                    $this->countries->where('main_table.' . $this->countries::schema_fields_IS_INSTALL, 1);
                    break;
                case 'uninstalled':
                    $this->countries->where('main_table.' . $this->countries::schema_fields_IS_INSTALL, 0);
                    break;
            }
        }
        
        // 注意：由于使用了 LEFT JOIN，即使没有显示名称也会显示国家记录
        // 我们不在查询时限制显示名称的语言，而是在数据处理时优先使用当前语言的显示名称
        // 这样可以避免复杂的 OR 条件导致的 SQL 错误
    }

    public function index()
    {
        // 获取筛选参数
        $filter = $this->request->getGet('filter', 'active'); // 默认显示已激活的
        $search = $this->request->getGet('search');
        
        // 构建查询条件
        $query = $this->countries;
        
        // 根据筛选条件添加查询
        // 注意：由于有 joinModel，需要使用 main_table 前缀来明确指定字段所属表
        switch ($filter) {
            case 'active':
                $query->where('main_table.' . \Weline\I18n\Model\Countries::schema_fields_IS_INSTALL, 1)
                      ->where('main_table.' . \Weline\I18n\Model\Countries::schema_fields_IS_ACTIVE, 1);
                break;
            case 'inactive':
                $query->where('main_table.' . \Weline\I18n\Model\Countries::schema_fields_IS_INSTALL, 1)
                      ->where('main_table.' . \Weline\I18n\Model\Countries::schema_fields_IS_ACTIVE, 0);
                break;
            case 'installed':
                // 显示已安装的（包括激活和未激活）
                $query->where('main_table.' . \Weline\I18n\Model\Countries::schema_fields_IS_INSTALL, 1);
                break;
            case 'uninstalled':
                // 显示未安装的
                $query->where('main_table.' . \Weline\I18n\Model\Countries::schema_fields_IS_INSTALL, 0);
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
        if (empty($installed_countries->getItems())) {
            $updateService = ObjectManager::getInstance(\Weline\I18n\Service\CountryDataUpdateService::class);
            $updateService->updateCountryData();
            $installed_countries = $query_copy->pagination()->select()->fetch();
        }
        
        // 自动更新当前语言的显示数据（只有当前语言没有数据时才更新）
        $this->autoUpdateMissingLocaleData();
        
        // 处理国家数据，确保 display_name 字段正确
        $countries = $installed_countries->getItems();
        $currentLang = Cookie::getLangLocal();
        
        foreach ($countries as $country) {
            // 如果 display_name 为空，使用国家代码或从 I18n 服务获取
            if (empty($country->getData('display_name'))) {
                $countryCode = $country->getData($this->countries::schema_fields_CODE);
                // 尝试从 I18n 服务获取国家名称
                try {
                    $countryNames = $this->i18n->getCountries($currentLang);
                    if (isset($countryNames[$countryCode])) {
                        $country->setData('display_name', $countryNames[$countryCode]);
                    } else {
                        // 如果还是没有，使用国家代码
                        $country->setData('display_name', $countryCode);
                    }
                } catch (\Exception $e) {
                    // 如果获取失败，使用国家代码
                    $country->setData('display_name', $countryCode);
                }
            }
            
            // 确保 flag 字段存在
            if (empty($country->getData('flag'))) {
                $countryCode = $country->getData($this->countries::schema_fields_CODE);
                try {
                    $flag = $this->i18n->getCountryFlag($countryCode, 32, 24);
                    $country->setData('flag', $flag ?: '');
                } catch (\Exception $e) {
                    $country->setData('flag', '');
                }
            }
        }
        
        // 检测是否为AJAX请求或请求JSON格式
        $isAjax = $this->request->isAjax();
        $acceptHeader = $this->request->getHeader('Accept') ?? '';
        $format = $this->request->getGet('format', '');
        $isJsonRequest = $isAjax 
            || strpos($acceptHeader, 'application/json') !== false 
            || $format === 'json';
        
        // 如果是JSON请求，返回JSON格式数据
        if ($isJsonRequest) {
            return $this->jsonResponse($countries, $installed_countries->getPagination(), $filter, $search);
        }
        
        // 否则返回HTML模板
        $this->assign('countries', $countries);
        $this->assign('countries_pagination', $installed_countries->getPagination());
        $this->assign('current_filter', $filter);
        $this->assign('search', $search);
        
        return $this->fetch();
    }
    
    /**
     * JSON响应方法
     * 
     * @param array $countries 国家列表
     * @param mixed $pagination 分页对象
     * @param string $filter 筛选条件
     * @param string $search 搜索关键词
     * @return string
     */
    private function jsonResponse($countries, $pagination, $filter, $search): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        
        // 格式化国家数据
        $countriesData = [];
        foreach ($countries as $country) {
            $countriesData[] = [
                'id' => $country->getId(),
                'code' => $country->getData($this->countries::schema_fields_CODE),
                'display_name' => $country->getData('display_name') ?: $country->getData($this->countries::schema_fields_CODE),
                'flag' => $country->getData('flag') ?: '',
                'is_install' => (bool)$country->getData($this->countries::schema_fields_IS_INSTALL),
                'is_active' => (bool)$country->getData($this->countries::schema_fields_IS_ACTIVE),
                'display_locale_code' => $country->getData('display_locale_code') ?: Cookie::getLangLocal(),
            ];
        }
        
        // 格式化分页数据
        $paginationData = null;
        if ($pagination) {
            $paginationData = [
                'current_page' => $pagination->getCurrentPage(),
                'page_size' => $pagination->getPageSize(),
                'total_pages' => $pagination->getTotalPages(),
                'total_records' => $pagination->getTotalRecords(),
            ];
        }
        
        // 返回JSON响应
        return json_encode([
            'success' => true,
            'message' => __('获取成功'),
            'data' => [
                'countries' => $countriesData,
                'pagination' => $paginationData,
                'filter' => $filter,
                'search' => $search,
                'current_locale' => Cookie::getLangLocal(),
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                \Weline\I18n\Model\Countries::schema_fields_CODE => $code,
                \Weline\I18n\Model\Countries::schema_fields_FLAG => (string)$this->i18n->getCountryFlag($code),
                \Weline\I18n\Model\Countries::schema_fields_IS_ACTIVE => 0,
                \Weline\I18n\Model\Countries::schema_fields_IS_INSTALL => 0,
            ];
            $insert_countries_display[] = [
                Name::schema_fields_COUNTRY_CODE => $code,
                Name::schema_fields_DISPLAY_LOCALE_CODE => Cookie::getLangLocal(),
                Name::schema_fields_DISPLAY_NAME => $country,
            ];
        }
        $this->countries->beginTransaction();
        try {
            // 安装国家数据
            $this->countries->clearQuery()->insert($insert_countries, \Weline\I18n\Model\Countries::schema_fields_CODE)->fetch();
            // 安装显示数据
            $this->localeNames->insert($insert_countries_display, [
                $this->localeNames::schema_fields_COUNTRY_CODE,
                $this->localeNames::schema_fields_DISPLAY_LOCALE_CODE,
                $this->localeNames::schema_fields_DISPLAY_NAME
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
                $this->countries->load($this->countries::schema_fields_CODE, $code);
                if ($this->countries->getId()) {
                    $this->countries->setData($this->countries::schema_fields_IS_INSTALL, 1)->save(true);
                    $this->getMessageManager()->addSuccess(__('成功安装!国家：%{1}(%{2})', [$this->countries->getData($this->localeNames::schema_fields_DISPLAY_NAME),
                        $this->countries->getData($this->countries::schema_fields_CODE)]));
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
        
        // 禁止删除中国（CN）
        if ($code === 'CN') {
            $this->getMessageManager()->addError(__('不允许卸载中国（CN），这是系统默认国家'));
            $this->redirect('*/backend/countries');
        }
        
        try {
            $this->countries->clearQuery()->where($this->countries::schema_fields_CODE, $code)
                ->find()
                ->fetch();
            if ($this->countries->getId()) {
                $this->countries->setData($this->countries::schema_fields_IS_INSTALL, 0)->save(true);
                // 使用 clearQuery() 清除之前可能存在的 join 条件，避免 PostgreSQL UPDATE 语句报错
                $this->countries->getLocaleModel()->clearQuery()->where(Locale::schema_fields_COUNTRY_CODE, $code)
                    ->update([Locale::schema_fields_IS_INSTALL => 0, Locale::schema_fields_IS_ACTIVE => 0])
                    ->fetch();
                $this->getMessageManager()->addSuccess(__('成功卸载!国家：%{1}(%{2})', [$this->countries->getData(Name::schema_fields_DISPLAY_NAME),
                    $this->countries->getData($this->countries::schema_fields_CODE)]));
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
            $this->countries->clearQuery()->load($this->countries::schema_fields_CODE, $code);
            if (!$this->countries->getId()) {
                $this->getMessageManager()->addWarning(__('国家不存在！国家代码：%{1}', $code));
                $this->redirect('*/backend/countries?filter=' . $filter);
            }
            $this->countries->setData($this->countries::schema_fields_IS_ACTIVE, 1)->save(true);
            $this->getMessageManager()->addSuccess(__('成功激活国家！国家：%{1}（%{2}）', [$this->countries->getData(Name::schema_fields_DISPLAY_NAME),
                $this->countries->getData($this->countries::schema_fields_CODE)]));
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
        
        // 禁止停用中国（CN）
        if ($code === 'CN') {
            $this->getMessageManager()->addError(__('不允许停用中国（CN），这是系统默认国家'));
            $this->redirect('*/backend/countries?filter=' . $filter);
        }
        
        try {
            $this->countries->clearQuery();
            $this->countries->load($this->countries::schema_fields_CODE, $code);
            if (!$this->countries->getId()) {
                $this->getMessageManager()->addWarning(__('国家不存在！国家代码：%{1}', $code));
                $this->redirect('*/backend/countries?filter=' . $filter);
            }
            $this->countries->setData($this->countries::schema_fields_IS_ACTIVE, 0)->save(true);
            $this->getMessageManager()->addSuccess(__('成功禁用国家！国家：%{1}（%{2}）', [$this->countries->getData(Name::schema_fields_DISPLAY_NAME),
                $this->countries->getData($this->countries::schema_fields_CODE)]));
            // FIXME 禁用应当删除对应语言的翻译包
            $country_locales = $this->locale->where($this->locale::schema_fields_COUNTRY_CODE, $code)->select()->fetch()->getItems();
            $pack_dir = Env::path_LANGUAGE_PACK;
            /**@var System $system */
            $system = ObjectManager::getInstance(System::class);
            /**@var */
            foreach ($country_locales as $country_locale) {
                $locale_dirs = glob($pack_dir . '*' . DS . $country_locale->getData($this->locale::schema_fields_ID), GLOB_ONLYDIR);
                foreach ($locale_dirs as $locale_dir) {
                    $result = $system->exec('rm -rf ' . $locale_dir);
                }
            }
            // 清理i18n缓存
            w_cache('i18n')->clear();
            w_cache('phrase')->clear();
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
                    $this->countries->clearQuery()->load($this->countries::schema_fields_CODE, $code);
                    if ($this->countries->getId()) {
                        $this->countries->setData($this->countries::schema_fields_IS_ACTIVE, 1)->save(true);
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
                    $this->countries->clearQuery()->load($this->countries::schema_fields_CODE, $code);
                    if ($this->countries->getId()) {
                        $this->countries->setData($this->countries::schema_fields_IS_ACTIVE, 0)->save(true);
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
                    $this->countries->clearQuery()->load($this->countries::schema_fields_CODE, $code);
                    if ($this->countries->getId()) {
                        $this->countries->setData($this->countries::schema_fields_IS_INSTALL, 1)->save(true);
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
            ->where(\Weline\I18n\Model\Locale::schema_fields_CODE, 'zh_Hans_CN')
            ->where(\Weline\I18n\Model\Locale::schema_fields_COUNTRY_CODE, 'CN')
            ->select()
            ->fetch()
            ->getFirst();
        
        if ($existingLocale && $existingLocale->getId()) {
            // 已存在，更新为已安装且已激活
            // 如果简码字段为空，自动计算并保存
            if (!$existingLocale->getData(\Weline\I18n\Model\Locale::schema_fields_SHORT_CODE)) {
                $localeCodes = \Weline\I18n\Model\Locale::extractLocaleCodes('zh_Hans_CN');
                $existingLocale->setData(\Weline\I18n\Model\Locale::schema_fields_SHORT_CODE, $localeCodes['short_code'])
                    ->setData(\Weline\I18n\Model\Locale::schema_fields_ISO2, $localeCodes['iso2'])
                    ->setData(\Weline\I18n\Model\Locale::schema_fields_ISO3, $localeCodes['iso3']);
            }
            
            $existingLocale->setData(\Weline\I18n\Model\Locale::schema_fields_IS_INSTALL, 1)
                          ->setData(\Weline\I18n\Model\Locale::schema_fields_IS_ACTIVE, 1)
                          ->save(true);
        } else {
            // 不存在，创建新的区域记录
            // 提取简码、ISO2和ISO3
            $localeCodes = \Weline\I18n\Model\Locale::extractLocaleCodes('zh_Hans_CN');
            
            $localeData = [
                \Weline\I18n\Model\Locale::schema_fields_CODE => 'zh_Hans_CN',
                \Weline\I18n\Model\Locale::schema_fields_COUNTRY_CODE => 'CN',
                \Weline\I18n\Model\Locale::schema_fields_SHORT_CODE => $localeCodes['short_code'],
                \Weline\I18n\Model\Locale::schema_fields_ISO2 => $localeCodes['iso2'],
                \Weline\I18n\Model\Locale::schema_fields_ISO3 => $localeCodes['iso3'],
                \Weline\I18n\Model\Locale::schema_fields_IS_INSTALL => 1,
                \Weline\I18n\Model\Locale::schema_fields_IS_ACTIVE => 1,
                \Weline\I18n\Model\Locale::schema_fields_FLAG => ''
            ];
            
            $locale->clearQuery()->insert($localeData, [\Weline\I18n\Model\Locale::schema_fields_CODE, \Weline\I18n\Model\Locale::schema_fields_COUNTRY_CODE])->fetch();
            
            // 创建区域名称记录
            $localeNameData = [
                \Weline\I18n\Model\Locale\Name::schema_fields_LOCALE_CODE => 'zh_Hans_CN',
                \Weline\I18n\Model\Locale\Name::schema_fields_DISPLAY_LOCALE_CODE => 'zh_Hans_CN',
                \Weline\I18n\Model\Locale\Name::schema_fields_DISPLAY_NAME => '简体中文（中国）'
            ];
            
            $localeName->clearQuery()->insert($localeNameData, [
                \Weline\I18n\Model\Locale\Name::schema_fields_LOCALE_CODE,
                \Weline\I18n\Model\Locale\Name::schema_fields_DISPLAY_LOCALE_CODE
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
                ->where(Name::schema_fields_DISPLAY_LOCALE_CODE, $currentLang)
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
                $countryCode = $country->getData(\Weline\I18n\Model\Countries::schema_fields_CODE);
                
                if (isset($countryNames[$countryCode])) {
                    $missingData[] = [
                        Name::schema_fields_COUNTRY_CODE => $countryCode,
                        Name::schema_fields_DISPLAY_LOCALE_CODE => $currentLang,
                        Name::schema_fields_DISPLAY_NAME => $countryNames[$countryCode]
                    ];
                }
            }
            
            // 批量插入当前语言的国家数据
            if (!empty($missingData)) {
                $this->localeNames->clearQuery()
                    ->insert($missingData, [
                        Name::schema_fields_COUNTRY_CODE,
                        Name::schema_fields_DISPLAY_LOCALE_CODE
                    ])
                    ->fetch();
            }
            
        } catch (\Exception $e) {
            // 静默处理异常，不影响页面显示
            w_log_error('Auto update missing locale data failed: ' . $e->getMessage(), [], 'i18n');
        }
    }

}
