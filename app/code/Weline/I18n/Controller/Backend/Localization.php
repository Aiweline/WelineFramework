<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/01 00:00:00
 */

namespace Weline\I18n\Controller\Backend;

use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;

class Localization extends BaseController
{
    private \Weline\I18n\Model\Locale $locales;
    private \Weline\I18n\Model\Locale\Name $localeNames;
    private \Weline\I18n\Model\Countries $countries;

    public function __construct(
        Locale                       $locale,
        I18n                         $i18n,
        \Weline\I18n\Model\Locale    $locales,
        \Weline\I18n\Model\Locale\Name $localeName,
        \Weline\I18n\Model\Countries $countries
    )
    {
        parent::__construct($locale, $i18n);
        
        // 初始化模型
        $this->localeNames = $localeName;
        $this->locales = $locales;
        $this->countries = $countries;
    }

    public function __init()
    {
        parent::__init();
        $this->assign('title', __('区域管理'));
        $this->assign('description', __('管理系统的区域设置，包括区域激活、安装、词典管理等'));
    }

    /**
     * 区域管理主页
     */
    public function index()
    {
        // 获取筛选参数
        $filter = $this->request->getGet('filter', 'all'); // 默认显示全部区域
        $search = $this->request->getGet('search');
        $countryFilter = $this->request->getGet('country_filter'); // 国家码过滤
                
        
        // 自动更新区域数据和区域名称数据
        $this->autoUpdateLocaleData();
        $this->autoUpdateLocaleNames();
        
        // 检查语言切换并强制更新
        $this->checkLanguageSwitchAndUpdate();
        $this->locale->reset()->joinModel(\Weline\I18n\Model\Locale\Name::class, 'ln', 'main_table.code=ln.locale_code', 'left')
        ->where('ln.display_locale_code', Cookie::getLangLocal());
        // 处理搜索条件
        if ($search) {
            $search = addslashes($search);
            $localeCode = $this->locale::fields_CODE;
            $localeName = \Weline\I18n\Model\Locale\Name::fields_DISPLAY_NAME;
            $countryCode = $this->locale::fields_COUNTRY_CODE;
            $this->locale->concat_like("main_table.{$localeCode},ln.{$localeName},main_table.{$countryCode}", "%{$search}%");
        }
        
        // 国家码过滤
        if ($countryFilter) {
            $this->locale->where('main_table.' . $this->locale::fields_COUNTRY_CODE, $countryFilter);
        }
        
        // 构建查询条件
        $this->buildFilterConditions($filter, $this->locale);
        
        // 获取分页参数
        $page = (int)$this->request->getGet('page', 1);
        $pageSize = (int)$this->request->getGet('page_size', 20);
        
        // 获取数据
        $locales = $this->locale
        ->pagination($page, $pageSize)->select()->fetchArray();
        
        // 确保 locales 是数组
        if (!is_array($locales)) {
            $locales = [];
        }
        
        // 获取统计信息
        # 激活数
        $activeQuery = clone $this->locale;
        $active = $activeQuery->reset()->where($this->locale::fields_IS_ACTIVE, 1)->total();
        # 已安装数
        $installedQuery = clone $this->locale;
        $installed = $installedQuery->reset()->where($this->locale::fields_IS_INSTALL, 1)->total();
        # 总数
        $total = $this->locale->count();
        # 未安装数
        $uninstalled = $total - $installed;
        $stats = [
            'total' => $total,
            'active' => $active,
            'installed' => $installed,
            'uninstalled' => $uninstalled
        ];
        
        // 获取国家地图数据（只包含已安装和已激活的区域）
        $countryMapData = $this->getMapData();
        
        // 获取所有国家列表用于过滤
        $countries = $this->getCountriesList();
        
        // 获取未启用提醒数据（已安装国家但没有启用任何区域的）
        $unactivatedRegionsCountry = $this->getUnactivatedRegionsCountry();
        
        $this->assign('locales', $locales);
        $this->assign('countries', $countries); // 用于国家过滤下拉框
        $this->assign('countryMapData', $countryMapData); // 国家地图数据
        $this->assign('unactivatedRegions', $unactivatedRegionsCountry); // 未启用提醒数据
        $this->assign('total', (string)$total);
        $this->assign('page', (string)$page);
        $this->assign('page_size', (string)$pageSize);
        $this->assign('filter', $filter);
        $this->assign('search', $search);
        $this->assign('country_filter', $countryFilter);
        $this->assign('stats', $stats);
        
        return $this->fetch();
    }
    
    /**
     * 清理不属于已安装国家的区域数据
     * 删除那些国家未安装的区域数据，避免数据冗余
     */
    private function cleanupUninstalledCountryLocales()
    {
        try {
            // 获取已安装的国家列表
            $installedCountries = $this->getInstalledCountries();
            
            if (empty($installedCountries)) {
                // 如果没有已安装的国家，先统计要删除的数量
                $localeModel = clone $this->locale;
                $deleteCount = $localeModel->reset()->count();
                
                // 删除所有区域数据
                $localeModel->reset()->delete();
                if ($deleteCount > 0) {
                    Message::success(__('清理了 %{1} 个不属于已安装国家的区域数据', $deleteCount));
                }
                return;
            }
            
            // 先统计要删除的数量
            $localeModel = clone $this->locale;
            $deleteCount = $localeModel->reset()
                ->where($this->locale::fields_COUNTRY_CODE, $installedCountries, 'not in')
                ->count();
            
            if ($deleteCount > 0) {
                // 删除不属于已安装国家的区域数据
                $localeModel->reset()
                    ->where($this->locale::fields_COUNTRY_CODE, $installedCountries, 'not in')
                    ->delete();
                
                Message::success(__('清理了 %{1} 个不属于已安装国家的区域数据', $deleteCount));
                
            }
            
        } catch (\Exception $e) {
            Message::error(__('清理区域数据失败: %{1}', $e->getMessage()));
        }
    }
    
    /**
     * 清理孤立的区域名称数据
     * 删除那些区域已被删除的区域名称数据
     */
    private function cleanupOrphanedLocaleNames()
    {
        try {
            // 获取现有的区域代码
            $localeModel = clone $this->locale;
            $existingLocales = $localeModel->reset()->select()->fetch()->getItems();
            $existingCodes = [];
            foreach ($existingLocales as $locale) {
                $existingCodes[] = $locale->getData($this->locale::fields_CODE);
            }
            
            if (empty($existingCodes)) {
                // 如果没有区域数据，先统计要删除的数量
                $localeNameModel = clone $this->localeNames;
                $deleteCount = $localeNameModel->reset()->count();
                
                // 删除所有区域名称数据
                $localeNameModel->reset()->delete();
                if ($deleteCount > 0) {
                    Message::success(__('清理了 %{1} 个孤立的区域名称数据', $deleteCount));
                }
                return;
            }
            
            // 先统计要删除的数量
            $localeNameModel = clone $this->localeNames;
            $deleteCount = $localeNameModel->reset()
                ->where($localeNameModel::fields_LOCALE_CODE, $existingCodes, 'not in')
                ->count();
            
            if ($deleteCount > 0) {
                // 删除不存在对应区域的名称数据
                $localeNameModel->reset()
                    ->where($localeNameModel::fields_LOCALE_CODE, $existingCodes, 'not in')
                    ->delete();
                
                Message::success(__('清理了 %{1} 个孤立的区域名称数据', $deleteCount));
            }
            
        } catch (\Exception $e) {
            Message::error(__('清理孤立区域名称数据失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * 自动更新区域数据
     * 检查区域数据是否完整，如果不完整则自动补充
     * 只为已安装的国家创建区域数据
     */
    private function autoUpdateLocaleData()
    {
        try {
            // 获取已安装的国家
            $installedCountries = $this->getInstalledCountries();
            
            if (empty($installedCountries)) {
                return;
            }
            
            // 计算已安装国家应该有的区域总数
            $expectedLocaleCount = $this->calculateExpectedLocaleCount($installedCountries);
            
            if ($expectedLocaleCount == 0) {
                return;
            }
            
            // 检查现有区域数据数量
            $localeModel = clone $this->locale;
            $existingCount = $localeModel->reset()->count();
            
            // 如果现有数据少于期望数量，则进行批量更新
            if ($existingCount < $expectedLocaleCount) {
                $missingCount = $expectedLocaleCount - $existingCount;
                Message::notes(__('检测到缺少 %{1} 个区域数据（基于已安装国家），开始自动同步', [$missingCount]));
                $this->batchUpdateLocaleDataForInstalledCountries($installedCountries);
            }
            
        } catch (\Exception $e) {
            // 静默处理错误，不影响主要功能
            Message::error(__('自动更新区域数据失败: %{1}', $e->getMessage()));
        }
    }
    
    /**
     * 批量更新区域数据
     */
    private function batchUpdateLocaleData($allLocales)
    {
        try {
            // 获取现有的区域代码
            $localeModel = clone $this->locale;
            $existingLocales = $localeModel->reset()->select()->fetch()->getItems();
            $existingCodes = [];
            foreach ($existingLocales as $locale) {
                $existingCodes[] = $locale->getData($this->locale::fields_CODE);
            }
            
            $insertData = [];
            $insertCount = 0;
            
            foreach ($allLocales as $localeCode) {
                // 如果区域不存在，则准备插入
                if (!in_array($localeCode, $existingCodes)) {
                    // 尝试获取国家代码
                    $countryCode = '';
                    if (strpos($localeCode, '_') !== false) {
                        $parts = explode('_', $localeCode);
                        $countryCode = end($parts);
                    }
                    
                    // 如果国家代码长度不是2，尝试其他方式
                    if (strlen($countryCode) !== 2) {
                        $countryCode = strtoupper(substr($localeCode, 0, 2));
                    }
                    
                    // 验证国家代码是否存在
                    if (!$this->isValidCountryCode($countryCode)) {
                        $countryCode = 'CN'; // 默认使用中国
                    }
                    
                    $insertData[] = [
                        $this->locale::fields_CODE => $localeCode,
                        $this->locale::fields_COUNTRY_CODE => $countryCode,
                        $this->locale::fields_IS_INSTALL => 0, // 默认未安装
                        $this->locale::fields_IS_ACTIVE => 0,  // 默认未激活
                        $this->locale::fields_FLAG => ''
                    ];
                    
                    $insertCount++;
                    
                    // 批量插入，每100条插入一次
                    if (count($insertData) >= 100) {
                        $this->insertLocaleDataBatch($insertData);
                        $insertData = [];
                    }
                }
            }
            
            // 插入剩余数据
            if (!empty($insertData)) {
                $this->insertLocaleDataBatch($insertData);
            }
            
            if ($insertCount > 0) {
                Message::success(__('已自动添加 %{1} 个区域数据', [$insertCount]));
            }
            
        } catch (\Exception $e) {
            Message::error(__('批量更新区域数据失败: %{1}', $e->getMessage()));
        }
    }
    
    /**
     * 批量插入区域数据
     */
    private function insertLocaleDataBatch($insertData)
    {
        try {
            $localeModel = clone $this->locale;
            $localeModel->reset()->insert($insertData, $this->locale::fields_CODE)->fetch();
        } catch (\Exception $e) {
            Message::error(__('批量插入区域数据失败: %{1}', $e->getMessage()));
        }
    }
    
    /**
     * 验证国家代码是否有效
     */
    private function isValidCountryCode($countryCode)
    {
        try {
            return \Symfony\Component\Intl\Countries::exists($countryCode);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取已安装的国家列表
     */
    private function getInstalledCountries()
    {
        try {
            $countriesModel = $this->countries;
            $installedCountries = $countriesModel->reset()
                ->where($countriesModel::fields_IS_INSTALL, 1)
                ->select()
                ->fetch()
                ->getItems();
            
            $countryCodes = [];
            foreach ($installedCountries as $country) {
                $countryCodes[] = $country->getData($countriesModel::fields_CODE);
            }
            
            return $countryCodes;
        } catch (\Exception $e) {
            Message::error(__('获取已安装国家失败: %{1}', $e->getMessage()));
            return [];
        }
    }
    
    /**
     * 计算已安装国家应该有的区域总数
     */
    private function calculateExpectedLocaleCount($installedCountries)
    {
        $expectedCount = 0;
        
        try {
            foreach ($installedCountries as $countryCode) {
                // 获取该国家的所有区域
                $country = $this->i18n->getCountry($countryCode);
                if ($country && method_exists($country, 'getLocales')) {
                    $locales = $country->getLocales();
                    $expectedCount += count($locales);
                }
            }
        } catch (\Exception $e) {
            Message::error(__('计算期望区域数量失败: %{1}', $e->getMessage()));
        }
        
        return $expectedCount;
    }
    
    /**
     * 为已安装国家批量更新区域数据
     */
    private function batchUpdateLocaleDataForInstalledCountries($installedCountries)
    {
        try {
            // 获取现有的区域代码
            $localeModel = clone $this->locale;
            $existingLocales = $localeModel->reset()->select()->fetch()->getItems();
            $existingCodes = [];
            foreach ($existingLocales as $locale) {
                $existingCodes[] = $locale->getData($this->locale::fields_CODE);
            }
            
            $insertData = [];
            $insertCount = 0;
            
            foreach ($installedCountries as $countryCode) {
                try {
                    // 获取该国家的所有区域
                    $country = $this->i18n->getCountry($countryCode);
                    if (!$country || !method_exists($country, 'getLocales')) {
                        continue;
                    }
                    
                    $locales = $country->getLocales();
                    
                    foreach ($locales as $localeCode) {
                        // 如果区域不存在，则准备插入
                        if (!in_array($localeCode, $existingCodes)) {
                            $insertData[] = [
                                $this->locale::fields_CODE => $localeCode,
                                $this->locale::fields_COUNTRY_CODE => $countryCode,
                                $this->locale::fields_IS_INSTALL => 0, // 默认未安装
                                $this->locale::fields_IS_ACTIVE => 0,  // 默认未激活
                                $this->locale::fields_FLAG => ''
                            ];
                            
                            $insertCount++;
                            
                            // 批量插入，每100条插入一次
                            if (count($insertData) >= 100) {
                                $this->insertLocaleDataBatch($insertData);
                                $insertData = [];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Message::error(__('处理国家 %{1} 的区域数据失败: %{2}', [$countryCode, $e->getMessage()]));
                }
            }
            
            // 插入剩余数据
            if (!empty($insertData)) {
                $this->insertLocaleDataBatch($insertData);
            }
            
            if ($insertCount > 0) {
                Message::success(__('已自动添加 %{1} 个区域数据（基于已安装国家）', [$insertCount]));
            }
            
        } catch (\Exception $e) {
            Message::error(__('批量更新已安装国家的区域数据失败: %{1}', $e->getMessage()));
        }
    }
    
    /**
     * 检查语言切换并强制更新
     * 当检测到用户可能切换了语言时，强制检查并更新区域名称数据
     */
    private function checkLanguageSwitchAndUpdate()
    {
        try {
            $currentLang = \Weline\Framework\Http\Cookie::getLangLocal();
            
            // 检查是否有强制更新的请求参数
            $forceUpdate = $this->request->getGet('force_update_locale_names', false);
            
            if ($forceUpdate) {
                Message::notes(__('检测到强制更新请求，开始更新当前语言(%{1})的区域名称数据', [$currentLang]));
                $this->syncLocaleNames($currentLang);
                return;
            }
            
            // 检查会话中记录的上次语言
            $session = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
            $lastLang = $session->getData('last_locale_lang');
            
            if ($lastLang && $lastLang !== $currentLang) {
                // 语言发生了切换，强制检查当前语言的数据完整性
                Message::notes(__('检测到语言切换：%{1} -> %{2}，开始检查数据完整性', [$lastLang, $currentLang]));
                
                // 获取已安装国家的区域总数
                $installedCountries = $this->getInstalledCountries();
                if (!empty($installedCountries)) {
                    $expectedLocaleCount = $this->calculateExpectedLocaleCount($installedCountries);
                    
                    if ($expectedLocaleCount > 0) {
                        // 检查当前语言的区域名称数据数量
                        $localeNameModel = clone $this->localeNames;
                        $currentLangCount = $localeNameModel->reset()
                            ->where($localeNameModel::fields_DISPLAY_LOCALE_CODE, $currentLang)
                            ->count();
                        
                        if ($currentLangCount < $expectedLocaleCount) {
                            $missingCount = $expectedLocaleCount - $currentLangCount;
                            Message::notes(__('语言切换后检测到缺少 %{1} 个区域名称数据（基于已安装国家），开始自动同步', [$missingCount]));
                            $this->syncLocaleNames($currentLang);
                        }
                    }
                }
            }
            
            // 更新会话中的语言记录
            $session->setData('last_locale_lang', $currentLang);
            
        } catch (\Exception $e) {
            Message::error(__('检查语言切换失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * 自动更新区域名称数据
     * 检查当前语言的区域名称数据是否存在，如果不存在则自动创建
     * 基于已安装国家的区域数量进行检测
     */
    private function autoUpdateLocaleNames()
    {
        try {
            // 获取当前显示语言
            $currentLang = \Weline\Framework\Http\Cookie::getLangLocal();
            Message::notes(__('开始检查当前语言(%{1})的区域名称数据', [$currentLang]));
            
            // 获取已安装国家的区域总数
            $installedCountries = $this->getInstalledCountries();
            if (empty($installedCountries)) {
                return;
            }
            
            $expectedLocaleCount = $this->calculateExpectedLocaleCount($installedCountries);
            if ($expectedLocaleCount == 0) {
                return;
            }
            
            // 检查当前语言的区域名称数据数量
            $localeNameModel = clone $this->localeNames;
            $currentLangCount = $localeNameModel->reset()
                ->where($localeNameModel::fields_DISPLAY_LOCALE_CODE, $currentLang)
                ->total();
            
            Message::notes(__('当前语言(%{1})已有 %{2} 个区域名称数据，期望 %{3} 个', [$currentLang, $currentLangCount, $expectedLocaleCount]));
            // 如果当前语言的区域名称数据数量与期望数量不匹配，则自动更新
            if ($currentLangCount < $expectedLocaleCount) {
                $missingCount = $expectedLocaleCount - $currentLangCount;
                Message::warning(__('检测到当前语言(%{1})缺少 %{2} 个区域名称数据（基于已安装国家），开始自动同步', [$currentLang, $missingCount]));
                $this->syncLocaleNames($currentLang);
            }
        } catch (\Exception $e) {
            // 静默处理错误，不影响主要功能
            Message::error(__('自动更新区域名称数据失败: %{1}', $e->getMessage()));
        }
    }
    
    /**
     * 同步区域名称数据
     * 为指定语言创建区域名称数据
     * 只同步已安装国家的区域数据
     */
    private function syncLocaleNames($displayLocaleCode)
    {
        try {
            Message::notes(__('开始同步语言(%{1})的区域名称数据', [$displayLocaleCode]));
            // 获取已安装国家的区域数据
            $installedCountries = $this->getInstalledCountries();
            if (empty($installedCountries)) {
                Message::warning(__('没有找到已安装的国家'));
                return;
            }
            
            // 获取已安装国家的区域数据
            $localeModel = clone $this->locale;
            $locales = $localeModel->reset()
                ->where($this->locale::fields_COUNTRY_CODE, $installedCountries, 'in')
                ->select()
                ->fetch()
                ->getItems();
            
            if (empty($locales)) {
                Message::warning(__('没有找到任何区域数据'));
                return;
            }
            
            // 获取现有的区域名称数据
            $localeNameModel = clone $this->localeNames;
            $existingNames = $localeNameModel->reset()
                ->where($localeNameModel::fields_DISPLAY_LOCALE_CODE, $displayLocaleCode)
                ->select()
                ->fetch()
                ->getItems();
            
            $existingCodes = [];
            foreach ($existingNames as $name) {
                $existingCodes[] = $name->getData($localeNameModel::fields_LOCALE_CODE);
            }
            
            // 准备批量插入数据
            $insertData = [];
            $updatedCount = 0;
            
            Message::notes(__('找到 %{1} 个区域数据，开始处理', [count($locales)]));
            
            foreach ($locales as $locale) {
                $localeCode = $locale->getData($this->locale::fields_CODE);
                
                if (empty($localeCode) || in_array($localeCode, $existingCodes)) {
                    continue;
                }
                
                // 获取区域名称
                $localeName = $this->getLocaleNameWithFallback($localeCode, $displayLocaleCode);
                
                $insertData[] = [
                    $localeNameModel::fields_LOCALE_CODE => $localeCode,
                    $localeNameModel::fields_DISPLAY_LOCALE_CODE => $displayLocaleCode,
                    $localeNameModel::fields_DISPLAY_NAME => $localeName
                ];
                
                $updatedCount++;
                
                // 批量插入，每100条插入一次
                if (count($insertData) >= 100) {
                    $this->insertLocaleNamesBatch($insertData);
                    $insertData = [];
                }
            }
            
            // 插入剩余数据
            if (!empty($insertData)) {
                $this->insertLocaleNamesBatch($insertData);
            }
            
            if ($updatedCount > 0) {
                Message::success(__('已自动更新 %{1} 个区域名称数据', [$updatedCount]));
            } else {
                Message::success(__('所有区域名称数据已是最新'));
            }
            
        } catch (\Exception $e) {
            Message::error(__('同步区域名称数据失败: %{1}', $e->getMessage()));
        }
    }
    
    /**
     * 获取区域名称（带备选方案）
     */
    private function getLocaleNameWithFallback($localeCode, $displayLocaleCode)
    {
        $localeName = '';
        
        // 方式1: 使用I18n模型的getLocaleName方法
        try {
            $localeName = $this->i18n->getLocaleName($localeCode, $displayLocaleCode);
        } catch (\Exception $e) {
            // 忽略错误，尝试其他方式
        }
        
        // 方式2: 如果第一种方式失败，直接使用Symfony Locales
        if (empty($localeName) || $localeName === $localeCode) {
            try {
                if (\Symfony\Component\Intl\Locales::exists($localeCode)) {
                    $localeName = \Symfony\Component\Intl\Locales::getName($localeCode, $displayLocaleCode);
                }
            } catch (\Exception $e) {
                // 忽略错误
            }
        }
        
        // 方式3: 如果还是获取不到，使用默认显示语言获取
        if (empty($localeName) || $localeName === $localeCode) {
            try {
                if (\Symfony\Component\Intl\Locales::exists($localeCode)) {
                    $localeName = \Symfony\Component\Intl\Locales::getName($localeCode, 'zh_CN');
                }
            } catch (\Exception $e) {
                // 忽略错误
            }
        }
        
        // 最后的备选方案：使用区域代码作为名称
        if (empty($localeName)) {
            $localeName = $localeCode;
        }
        
        return $localeName;
    }
    
    /**
     * 批量插入区域名称数据
     */
    private function insertLocaleNamesBatch($insertData)
    {
        try {
            $localeNameModel = clone $this->localeNames;
            $localeNameModel->reset()->insert($insertData, $localeNameModel::fields_LOCALE_CODE.','.$localeNameModel::fields_DISPLAY_LOCALE_CODE, $localeNameModel::fields_DISPLAY_NAME)->fetch();
            Message::success(__('成功插入 %{1} 条区域名称数据', [count($insertData)]));
        } catch (\Exception $e) {
            Message::error(__('批量插入区域名称数据失败: %{1}', $e->getMessage()));
        }
    }
    
    /**
     * 构建筛选条件
     */
    private function buildFilterConditions($filter, &$query)
    {
        switch ($filter) {
            case 'active':
                $query->where($query::fields_IS_INSTALL, 1)
                      ->where($query::fields_IS_ACTIVE, 1);
                break;
            case 'inactive':
                $query->where($query::fields_IS_INSTALL, 1)
                      ->where($query::fields_IS_ACTIVE, 0);
                break;
            case 'installed':
                $query->where($query::fields_IS_INSTALL, 1);
                break;
            case 'uninstalled':
                $query->where($query::fields_IS_INSTALL, 0);
                break;
            case 'all':
            default:
                // 显示所有
                break;
        }
    }
    
    /**
     * 获取国家列表用于过滤
     */
    private function getCountriesList()
    {
        $countriesModel = ObjectManager::getInstance(\Weline\I18n\Model\Countries::class);
        $countriesModel->joinModel(\Weline\I18n\Model\Countries\Locale\Name::class, 'cn', 'main_table.code=cn.country_code', 'left');
        $countriesModel->where('cn.display_locale_code', Cookie::getLangLocal());
        
        $countries = $countriesModel->select('main_table.code, cn.display_name')->fetch()->getItems();
        
        $result = [];
        foreach ($countries as $country) {
            $result[$country['code']] = $country['display_name'] ?: $country['code'];
        }
        
        return $result;
    }

    /**
     * 获取地图数据（国家级别的数据）
     */
    private function getMapData()
    {
        // 使用 Countries 模型获取国家数据
        $countriesModel = ObjectManager::make(\Weline\I18n\Model\Countries::class);        
        $mapCountries = $countriesModel->select('code,is_install,is_active')->fetchArray();
        
        return $mapCountries;
    }

    /**
     * 获取未启用区域的国家（已安装国家但没有启用任何区域的）
     */
    private function getUnactivatedRegionsCountry()
    {
        // 获取所有已安装的国家，直接 JOIN 国家名称表
        $countriesModel = ObjectManager::getInstance(\Weline\I18n\Model\Countries::class);
        $countriesModel->joinModel(\Weline\I18n\Model\Countries\Locale\Name::class, 'cn', 'main_table.code=cn.country_code', 'left');
        $countriesModel->where('main_table.' . $countriesModel::fields_IS_INSTALL, 1);
        $countriesModel->where('cn.' . \Weline\I18n\Model\Countries\Locale\Name::fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal());
        
        $installedCountries = $countriesModel->select('main_table.code, main_table.is_install, main_table.is_active, cn.display_name')
                                           ->fetch()
                                           ->getItems();
        
        $unactivatedCountries = [];
        
        foreach ($installedCountries as $country) {
            // 检查该国家下是否有任何激活的区域
            $localeModel = ObjectManager::getInstance(\Weline\I18n\Model\Locale::class);
            $activeLocalesCount = $localeModel->where($localeModel::fields_COUNTRY_CODE, $country['code'])
                                             ->where($localeModel::fields_IS_ACTIVE, 1)
                                             ->count();
            
            // 添加调试信息
            Message::notes(__('国家 %{1} 有 %{2} 个激活的区域', [$country['code'], $activeLocalesCount]));
            
            // 如果该国家没有任何激活的区域，将该国家添加到未启用列表
            if ($activeLocalesCount == 0) {
                // 获取国旗SVG
                $flagSvg = $this->i18n->getCountryFlag($country['code'], 20, 15);
                
                $unactivatedCountries[] = [
                    'code' => $country['code'],
                    'display_name' => $country['display_name'] ?: $country['code'],
                    'is_install' => $country['is_install'],
                    'is_active' => $country['is_active'],
                    'flag' => $flagSvg
                ];
            }
        }
        
        return $unactivatedCountries;
    }

    /**
     * 激活区域
     */
    public function activate()
    {
        $localeCode = $this->request->getParam('locale_code');
        
        if (empty($localeCode)) {
            Message::error(__('请选择要激活的区域'));
            $this->redirect('*/backend/localization');
        }
        
        try {
            $locale = $this->locale->where($this->locale::fields_CODE, $localeCode)->find()->fetch();
            
            if (!$locale->getId()) {
                Message::error(__('区域不存在'));
                $this->redirect('*/backend/localization');
            }
            
            // 检查是否已安装
            if (!$locale->getData($this->locale::fields_IS_INSTALL)) {
                Message::error(__('请先安装该区域'));
                $this->redirect('*/backend/localization');
            }
            
            // 激活区域
            $locale->setData($this->locale::fields_IS_ACTIVE, 1);
            $locale->save();
            
            Message::success(__('区域激活成功'));
        } catch (\Exception $e) {
            Message::error(__('激活失败：%{1}', $e->getMessage()));
        }
        
        $this->redirect('*/backend/localization');
    }
    
    /**
     * 停用区域
     */
    public function deactivate()
    {
        $localeCode = $this->request->getParam('locale_code');
        
        if (empty($localeCode)) {
            Message::error(__('请选择要停用的区域'));
            $this->redirect('*/backend/localization');
        }
        
        try {
            $locale = $this->locale->where($this->locale::fields_CODE, $localeCode)->find()->fetch();
            
            if (!$locale->getId()) {
                Message::error(__('区域不存在'));
                $this->redirect('*/backend/localization');
            }
            
            // 停用区域
            $locale->setData($this->locale::fields_IS_ACTIVE, 0);
            $locale->save();
            
            Message::success(__('区域停用成功'));
        } catch (\Exception $e) {
            Message::error(__('停用失败：%{1}', $e->getMessage()));
        }
        
        $this->redirect('*/backend/localization');
    }
    
    /**
     * 安装区域
     */
    public function install()
    {
        $localeCode = $this->request->getParam('locale_code');
        
        if (empty($localeCode)) {
            Message::error(__('请选择要安装的区域'));
            $this->redirect('*/backend/localization');
        }
        
        try {
            $locale = $this->locale->where($this->locale::fields_CODE, $localeCode)->find()->fetch();
            
            if (!$locale->getId()) {
                Message::error(__('区域不存在'));
                $this->redirect('*/backend/localization');
            }
            
            // 安装区域
            $locale->setData($this->locale::fields_IS_INSTALL, 1);
            $locale->save();
            
            Message::success(__('区域安装成功'));
        } catch (\Exception $e) {
            Message::error(__('安装失败：%{1}', $e->getMessage()));
        }
        
        $this->redirect('*/backend/localization');
    }
    
    /**
     * 卸载区域
     */
    public function uninstall()
    {
        $localeCode = $this->request->getParam('locale_code');
        
        if (empty($localeCode)) {
            Message::error(__('请选择要卸载的区域'));
            $this->redirect('*/backend/localization');
        }
        
        try {
            $locale = $this->locale->where($this->locale::fields_CODE, $localeCode)->find()->fetch();
            
            if (!$locale->getId()) {
                Message::error(__('区域不存在'));
                $this->redirect('*/backend/localization');
            }
            
            // 先停用再卸载
            $locale->setData($this->locale::fields_IS_ACTIVE, 0);
            $locale->setData($this->locale::fields_IS_INSTALL, 0);
            $locale->save();
            
            Message::success(__('区域卸载成功'));
        } catch (\Exception $e) {
            Message::error(__('卸载失败：%{1}', $e->getMessage()));
        }
        
        $this->redirect('*/backend/localization');
    }

    /**
     * 词典管理 - 重定向到现有的词典管理功能
     */
    public function dictionary()
    {
        $localeCode = $this->request->getParam('locale_code');
        if ($localeCode) {
            try {
                // 从locale_code中提取国家码
                $countryCode = $this->extractCountryCodeFromLocale($localeCode);
                
                // 构建完整的词典管理URL，包含locale_code和国家码
                $redirectUrl = '*/backend/dictionary?locale_code=' . $localeCode . '&country_code=' . $countryCode;
                
                Message::notes(__('正在跳转到 %{1} 的词典管理页面', [$localeCode]));
                $this->redirect($redirectUrl);
            } catch (\Exception $e) {
                Message::error(__('无法处理区域代码 %{1}: %{2}', [$localeCode, $e->getMessage()]));
                $this->redirect('*/backend/dictionary');
            }
        } else {
            $this->redirect('*/backend/dictionary');
        }
    }
    
    /**
     * 从locale代码中提取国家码
     * 例如：zh_CN -> CN, en_US -> US, fr_FR -> FR
     */
    private function extractCountryCodeFromLocale($localeCode)
    {
        // 如果locale_code包含下划线，提取后面的部分作为国家码
        if (strpos($localeCode, '_') !== false) {
            $parts = explode('_', $localeCode);
            return strtoupper(end($parts));
        }
        
        // 如果没有下划线，尝试从locale表中查找对应的国家码
        $locale = $this->locale->where($this->locale::fields_CODE, $localeCode)->find()->fetch();
        if ($locale->getId()) {
            return $locale->getData($this->locale::fields_COUNTRY_CODE);
        }
        
        // 如果都找不到，返回默认值
        return 'US';
    }
    
    /**
     * 手动同步区域名称数据
     */
    public function syncNames()
    {
        try {
            $currentLang = \Weline\Framework\Http\Cookie::getLangLocal();
            $force = $this->request->getParam('force', false);
            
            if ($force) {
                // 强制更新：先删除现有数据
                $localeNameModel = clone $this->localeNames;
                $localeNameModel->reset()
                    ->where($localeNameModel::fields_DISPLAY_LOCALE_CODE, $currentLang)
                    ->delete();
                Message::success('已清除现有区域名称数据，开始重新同步');
            }
            
            $this->syncLocaleNames($currentLang);
            Message::success('区域名称数据同步完成');
        } catch (\Exception $e) {
            Message::error('同步失败：' . $e->getMessage());
        }
        
        $this->redirect('*/backend/localization');
    }
    
    /**
     * 手动清理区域数据
     * 清理不属于已安装国家的区域数据
     */
    public function cleanupLocales()
    {
        try {
            // 获取清理前的统计
            $localeModel = clone $this->locale;
            $beforeCount = $localeModel->reset()->count();
            
            
            // 获取清理后的统计
            $afterCount = $localeModel->reset()->count();
            $cleanedCount = $beforeCount - $afterCount;
            
            if ($cleanedCount > 0) {
                Message::success("清理完成！删除了 {$cleanedCount} 个不属于已安装国家的区域数据");
            } else {
                Message::success('数据已是最新，无需清理');
            }
            
        } catch (\Exception $e) {
            Message::error('清理失败：' . $e->getMessage());
        }
        
        $this->redirect('*/backend/localization');
    }
    
    /**
     * 批量操作
     */
    public function batchAction()
    {
        $action = $this->request->getParam('action');
        $countryCodes = $this->request->getParam('country_codes', []);
        
        if (empty($action) || empty($countryCodes)) {
            Message::error('请选择操作和区域');
            $this->redirect('*/backend/localization');
        }
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($countryCodes as $countryCode) {
            try {
                $country = $this->countries->where($this->countries::fields_CODE, $countryCode)->find()->fetch();
                
                if (!$country->getId()) {
                    $errorCount++;
                    continue;
                }
                
                switch ($action) {
                    case 'activate':
                        if ($country->getData($this->countries::fields_IS_INSTALL)) {
                            $country->setData($this->countries::fields_IS_ACTIVE, 1);
                            $country->save();
                            $successCount++;
                        } else {
                            $errorCount++;
                        }
                        break;
                    case 'deactivate':
                        $country->setData($this->countries::fields_IS_ACTIVE, 0);
                        $country->save();
                        $successCount++;
                        break;
                    case 'install':
                        $country->setData($this->countries::fields_IS_INSTALL, 1);
                        $country->save();
                        $successCount++;
                        break;
                    case 'uninstall':
                        $country->setData($this->countries::fields_IS_ACTIVE, 0);
                        $country->setData($this->countries::fields_IS_INSTALL, 0);
                        $country->save();
                        $successCount++;
                        break;
                }
            } catch (\Exception $e) {
                $errorCount++;
            }
        }
        
        if ($successCount > 0) {
            Message::success("成功处理 {$successCount} 个区域");
        }
        if ($errorCount > 0) {
            Message::error("{$errorCount} 个区域处理失败");
        }
        
        $this->redirect('*/backend/localization');
    }
}
