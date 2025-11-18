<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/12/19
 */

namespace Weline\I18n\Controller\Backend\Countries;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Controller\Backend\BaseController;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locale\Name as LocaleName;
use Weline\I18n\Model\I18n;

class TestUpdate extends BaseController
{
    public function index()
    {
        try {
            /** @var I18n $i18n */
            $i18n = ObjectManager::getInstance(I18n::class);
            /** @var Countries $countries */
            $countries = ObjectManager::getInstance(Countries::class);
            /** @var Locale $locale */
            $locale = ObjectManager::getInstance(Locale::class);
            /** @var LocaleName $localeName */
            $localeName = ObjectManager::getInstance(LocaleName::class);
            
            // 测试数据统计
            $stats = [];
            
            // 1. 统计国家数量
            $countryCount = $countries->clearQuery()->count();
            $stats['countries'] = $countryCount;
            
            // 2. 统计区域数量
            $localeCount = $locale->clearQuery()->count();
            $stats['locales'] = $localeCount;
            
            // 3. 统计区域名称数量
            $localeNameCount = $localeName->clearQuery()->count();
            $stats['locale_names'] = $localeNameCount;
            
            // 4. 测试获取一些国家的区域
            $testCountries = ['US', 'CN', 'JP', 'DE', 'FR'];
            $localesByCountry = [];
            
            foreach ($testCountries as $countryCode) {
                try {
                    $countryLocales = $i18n->getCountry($countryCode)->getLocales();
                    $localesByCountry[$countryCode] = count($countryLocales);
                } catch (\Exception $e) {
                    $localesByCountry[$countryCode] = 'Error: ' . $e->getMessage();
                }
            }
            
            $stats['test_countries'] = $localesByCountry;
            
            // 5. 测试获取一些区域名称
            $testLocales = ['en_US', 'zh_Hans_CN', 'ja_JP', 'de_DE', 'fr_FR'];
            $localeNames = [];
            
            foreach ($testLocales as $localeCode) {
                try {
                    $name_en = $i18n->getLocaleName($localeCode, 'en');
                    $name_zh = $i18n->getLocaleName($localeCode, 'zh_Hans_CN');
                    $localeNames[$localeCode] = [
                        'en' => $name_en,
                        'zh_Hans_CN' => $name_zh
                    ];
                } catch (\Exception $e) {
                    $localeNames[$localeCode] = 'Error: ' . $e->getMessage();
                }
            }
            
            $stats['test_locale_names'] = $localeNames;
            
            // 返回测试结果
            $this->request->getResponse()->setData([
                'success' => true,
                'stats' => $stats,
                'message' => '测试完成'
            ]);
            
        } catch (\Exception $e) {
            $this->request->getResponse()->setData([
                'success' => false,
                'message' => '测试失败：' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
