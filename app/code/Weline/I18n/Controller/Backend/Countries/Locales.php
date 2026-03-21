<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/23 22:28:53
 */

namespace Weline\I18n\Controller\Backend\Countries;

use Symfony\Component\Intl\Countries;
use Weline\Framework\App\Debug;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Controller\Backend\BaseController;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\Framework\App\Env;
use Weline\I18n\Model\Locale\Name;
use Weline\I18n\Service\CountryLocaleLifecycleService;

class Locales extends BaseController
{
    /**
     * @var \Weline\I18n\Model\Locale\Name
     */
    private Name $localeName;
    private CountryLocaleLifecycleService $lifecycle;

    public function __construct(
        Locale $locale,
        I18n   $i18n,
        Name   $localeName
    )
    {
        parent::__construct($locale, $i18n);
        $this->localeName = $localeName;
        $this->lifecycle = ObjectManager::getInstance(CountryLocaleLifecycleService::class);
    }

    public function __init()
    {
        parent::__init();
        $country_code = $this->request->getParam('country_code');
        
        // 先设置基础条件
        $this->locale->where('main_table.' . $this->locale::schema_fields_COUNTRY_CODE, $country_code);
        
        // 先执行joinModel，然后再使用where条件引用join的表
        $this->locale->joinModel(
                \Weline\I18n\Model\Countries::class,
                'c',
                'main_table.' . $this->locale::schema_fields_COUNTRY_CODE . '=c.' . \Weline\I18n\Model\Countries::schema_fields_CODE,
                'left',
                'c.flag'
            )
            ->joinModel(
                \Weline\I18n\Model\Countries\Locale\Name::class,
                'cln',
                'c.' . \Weline\I18n\Model\Countries::schema_fields_CODE . '=cln.' . \Weline\I18n\Model\Countries\Locale\Name::schema_fields_COUNTRY_CODE,
                'left',
                'cln.' . \Weline\I18n\Model\Countries\Locale\Name::schema_fields_DISPLAY_NAME . ' as country_name'
            )->joinModel(
                Name::class,
                'lln',
                'main_table.' . $this->locale::schema_fields_CODE . '=lln.' . Name::schema_fields_LOCALE_CODE,
                'left',
                'lln.' . Name::schema_fields_DISPLAY_NAME . ' as locale_name'
            );
        
        // joinModel之后才能使用where条件引用join的表
        $this->locale->where('lln.' . Name::schema_fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal())
            ->where('cln.' . \Weline\I18n\Model\Countries\Locale\Name::schema_fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal())
            ->where('c.' . \Weline\I18n\Model\Countries::schema_fields_CODE, $country_code);

        // 搜索条件（在join之后才能使用country_name）
        if ($search = $this->request->getParam('search')) {
            $code = $this->locale::schema_fields_CODE;
            $country_code_field = $this->locale::schema_fields_COUNTRY_CODE;
            $this->locale->where("CONCAT(main_table.{$code},cln." . \Weline\I18n\Model\Countries\Locale\Name::schema_fields_DISPLAY_NAME . ",main_table.{$country_code_field})", "%{$search}%", 'LIKE');
        }
    }

    public function getIndex()
    {
        // 执行查询
        $query_copy = clone $this->locale;
        $locales_result = $this->locale
            ->fields('main_table.*')
            ->pagination()
            ->select()
            ->fetch();
            
        // 如果查不到数据就自动更新区域数据
        if ($locales_result->getTotal() == 0) {
            $this->autoUpdateLocaleData();
            // 重新查询
            $locales_result = $query_copy
                ->fields('main_table.*')
                ->pagination()
                ->select()
                ->fetch();
        }
        
        // 每次访问都自动更新区域名称数据（确保当前语言的显示名称存在）
        $this->autoUpdateMissingLocaleNames();
        
//        p($this->locale->getLastSql());
        $this->assign('locales', $locales_result->getItems());
        $this->assign('pagination', $locales_result->getPagination());
        return $this->fetch();
    }


    public function getUpdate()
    {
        $this->request->checkParam();
        $country_code = $this->request->getGet('country_code');
        $this->request->checkParam(false);
        if (!Countries::exists($country_code)) {
            $this->getMessageManager()->addWarning(__('国家不存在！代码：%{1}', $country_code));
            $this->redirect('*/backend/countries/locales');
        }
        $country = $this->i18n->getCountry($country_code);
        $countryLocales = (array)($country['locales'] ?? []);
        $locales = [];
        $locales_display = [];
        $this->locale->clearQuery();
        foreach ($countryLocales as $localeCode) {
            $localeCodes = Locale::extractLocaleCodes($localeCode);

            $locales[] = [
                $this->locale::schema_fields_CODE => $localeCode,
                $this->locale::schema_fields_COUNTRY_CODE => $country_code,
                $this->locale::schema_fields_SHORT_CODE => $localeCodes['short_code'],
                $this->locale::schema_fields_ISO2 => $localeCodes['iso2'],
                $this->locale::schema_fields_ISO3 => $localeCodes['iso3'],
                $this->locale::schema_fields_IS_INSTALL => 0,
                $this->locale::schema_fields_IS_ACTIVE => 0,
                $this->locale::schema_fields_FLAG => '',
            ];
            $locales_display[] = [
                Name::schema_fields_DISPLAY_LOCALE_CODE => Cookie::getLangLocal(),
                Name::schema_fields_LOCALE_CODE => $localeCode,
                Name::schema_fields_DISPLAY_NAME => $this->i18n->getLocaleName($localeCode, Cookie::getLangLocal()),
            ];
        }
        $this->locale->beginTransaction();
        try {
            // 安装地区
            $result = $this->locale->reset()->insert($locales, $this->locale::schema_fields_CODE)->fetch();
            // 安装地区展示码
            $this->localeName->reset()->insert($locales_display, [
                $this->localeName::schema_fields_LOCALE_CODE,
                $this->localeName::schema_fields_DISPLAY_LOCALE_CODE
            ])->fetch();
            $this->locale->commit();
            $this->getMessageManager()->addSuccess(__('安装国家地区数据成功！'));
        } catch (\Exception $exception) {
            $this->locale->rollBack();
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/countries/locales', [], true);
    }

    public function postActive()
    {
        $code = $this->request->getPost('code');
        if ($this->i18n->localeExists($code)) {
            try {
                $this->lifecycle->activateLocale((string)$code);
                $this->getMessageManager()->addSuccess(__('激活成功！'));
            } catch (\Exception $exception) {
                $this->getMessageManager()->addException($exception);
            }
        } else {
            $this->getMessageManager()->addWarning(__('地区已经不存在！'));
        }
        $this->redirect('*/backend/countries/locales', $this->request->getParams());
    }

    public function postDisable()
    {
        $code = $this->request->getPost('code');
        if ($this->i18n->localeExists($code)) {
            try {
                $this->lifecycle->deactivateLocale((string)$code);
                $this->getMessageManager()->addSuccess(__('禁用成功！'));
            } catch (\Exception $exception) {
                $this->getMessageManager()->addException($exception);
            }
        } else {
            $this->getMessageManager()->addWarning(__('地区已经不存在！'));
        }
        $this->redirect('*/backend/countries/locales', $this->request->getParams());
    }

    public function install()
    {
        $code = $this->request->getPost('code');
        try {
            $this->lifecycle->installLocale((string)$code);
            $this->getMessageManager()->addSuccess(__('区域已安装！区域代码：%{1}', $code));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect($this->request->getReferer());
    }

    public function postUninstall()
    {
        $code = $this->request->getPost('code');
        try {
            $this->lifecycle->uninstallLocale((string)$code);
            $this->getMessageManager()->addSuccess(__('区域已卸载！区域代码：%{1}', $code));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect($this->request->getReferer());
    }

    /**
     * 自动更新区域数据
     */
    private function autoUpdateLocaleData(): void
    {
        try {
            $country_code = $this->request->getParam('country_code');
            if (!$country_code) {
                return;
            }
            
            // 检查国家是否存在
            if (!\Symfony\Component\Intl\Countries::exists($country_code)) {
                return;
            }
            
            // 获取该国家的所有区域
            $country = $this->i18n->getCountry($country_code);
            $locales = (array)($country['locales'] ?? []);
            
            if (empty($locales)) {
                return;
            }
            
            $locales_data = [];
            $locales_display = [];
            
            foreach ($locales as $locale) {
                // 提取简码、ISO2和ISO3
                $localeCodes = Locale::extractLocaleCodes($locale);
                
                $locales_data[] = [
                    $this->locale::schema_fields_CODE => $locale,
                    $this->locale::schema_fields_COUNTRY_CODE => $country_code,
                    $this->locale::schema_fields_SHORT_CODE => $localeCodes['short_code'],
                    $this->locale::schema_fields_ISO2 => $localeCodes['iso2'],
                    $this->locale::schema_fields_ISO3 => $localeCodes['iso3'],
                    $this->locale::schema_fields_IS_INSTALL => 0,
                    $this->locale::schema_fields_IS_ACTIVE => 0,
                    $this->locale::schema_fields_FLAG => ''
                ];
                
                $locales_display[] = [
                    Name::schema_fields_DISPLAY_LOCALE_CODE => Cookie::getLangLocal(),
                    Name::schema_fields_LOCALE_CODE => $locale,
                    Name::schema_fields_DISPLAY_NAME => $this->i18n->getLocaleName($locale, Cookie::getLangLocal()),
                ];
            }
            
            // 使用独立的模型实例，避免影响主查询对象
            $localeModel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\I18n\Model\Locale::class);
            $localeNameModel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\I18n\Model\Locale\Name::class);
            
            $localeModel->beginTransaction();
            
            // 插入区域数据
            $localeModel->reset()->insert($locales_data, $this->locale::schema_fields_CODE)->fetch();
            
            // 插入区域显示名称数据
            $localeNameModel->reset()->insert($locales_display, [
                $this->localeName::schema_fields_LOCALE_CODE,
                $this->localeName::schema_fields_DISPLAY_LOCALE_CODE
            ])->fetch();
            
            $localeModel->commit();
            
        } catch (\Exception $e) {
            // 使用独立的模型实例回滚
            try {
                $localeModel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\I18n\Model\Locale::class);
                $localeModel->rollBack();
            } catch (\Exception $rollbackException) {
                // 忽略回滚错误
            }
            // 静默处理异常，不影响页面显示
            w_log_error('Auto update locale data failed: ' . $e->getMessage(), [], 'i18n');
        }
    }

    /**
     * 自动更新缺失的区域名称数据（优化版本，只检查当前语言）
     */
    private function autoUpdateMissingLocaleNames(): void
    {
        try {
            $country_code = $this->request->getParam('country_code');
            $currentLang = Cookie::getLangLocal();
            
            if (!$country_code) {
                return;
            }
            
            // 首先检查该国家的当前语言是否已经有区域名称数据
            $existingNamesCount = $this->localeName->clearQuery()
                ->joinModel(
                    Locale::class,
                    'l',
                    'main_table.' . Name::schema_fields_LOCALE_CODE . '=l.' . Locale::schema_fields_CODE,
                    'inner'
                )
                ->where('l.' . Locale::schema_fields_COUNTRY_CODE, $country_code)
                ->where('main_table.' . Name::schema_fields_DISPLAY_LOCALE_CODE, $currentLang)
                ->count();
            
            if ($existingNamesCount > 0) {
                // 当前语言的区域名称已存在，无需更新
                return;
            }
            
            // 获取该国家下所有已存在的区域
            $existingLocales = $this->locale->clearQuery()
                ->where($this->locale::schema_fields_COUNTRY_CODE, $country_code)
                ->select($this->locale::schema_fields_CODE)
                ->fetch()
                ->getItems();
            
            if (empty($existingLocales)) {
                return;
            }
            
            // 批量准备当前语言的区域名称数据
            $missingNames = [];
            
            foreach ($existingLocales as $locale) {
                $localeCode = $locale->getData($this->locale::schema_fields_CODE);
                
                // 获取区域显示名称
                $displayName = $this->i18n->getLocaleName($localeCode, $currentLang);
                if ($displayName) {
                    $missingNames[] = [
                        Name::schema_fields_DISPLAY_LOCALE_CODE => $currentLang,
                        Name::schema_fields_LOCALE_CODE => $localeCode,
                        Name::schema_fields_DISPLAY_NAME => $displayName,
                    ];
                }
            }
            
            // 批量插入当前语言的区域名称数据
            if (!empty($missingNames)) {
                $this->localeName->clearQuery()
                    ->insert($missingNames, [
                        Name::schema_fields_LOCALE_CODE,
                        Name::schema_fields_DISPLAY_LOCALE_CODE
                    ])
                    ->fetch();
            }
            
        } catch (\Exception $e) {
            // 静默处理异常，不影响页面显示
            w_log_error('Auto update missing locale names failed: ' . $e->getMessage(), [], 'i18n');
        }
    }
}
