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

namespace Weline\I18n\Controller\Backend\Countries;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Controller\Backend\BaseController;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\Framework\App\Env;
use Weline\I18n\Model\I18n;

class InstallAll extends BaseController
{
    public function index()
    {
        try {
            /** @var I18n $i18n */
            $i18n = ObjectManager::getInstance(I18n::class);
            
            /** @var Countries $countries */
            $countries = ObjectManager::getInstance(Countries::class);
            
            /** @var Name $localeNames */
            $localeNames = ObjectManager::getInstance(Name::class);
            
            // 获取所有可用的国家信息
            $availableCountries = $i18n->getCountries('en');
            
            // 检查已存在的国家
            $existingCountries = $countries->clearQuery()
                ->select()
                ->fetch()
                ->getItems();
            
            $existingCodes = [];
            foreach ($existingCountries as $country) {
                $existingCodes[] = $country->getData(Countries::fields_CODE);
            }
            
            $insert_countries = [];
            $insert_countries_display = [];
            $update_countries = [];
            $update_countries_display = [];
            $skippedCount = 0;
            
            foreach ($availableCountries as $code => $country) {
                $countryData = [
                    Countries::fields_CODE => $code,
                    Countries::fields_FLAG => (string)$i18n->getCountryFlag($code),
                    Countries::fields_IS_ACTIVE => ($code === 'CN') ? 1 : 0, // 中国默认已激活，其他国家未激活
                    Countries::fields_IS_INSTALL => ($code === 'CN') ? 1 : 0, // 中国默认已安装，其他国家未安装
                ];
                
                $displayData = [
                    Name::fields_COUNTRY_CODE => $code,
                    Name::fields_DISPLAY_LOCALE_CODE => 'en',
                    Name::fields_DISPLAY_NAME => $country,
                ];
                
                if (in_array($code, $existingCodes)) {
                    // 国家已存在，检查是否需要更新
                    $existingCountry = null;
                    foreach ($existingCountries as $existing) {
                        if ($existing->getData(Countries::fields_CODE) === $code) {
                            $existingCountry = $existing;
                            break;
                        }
                    }
                    
                    if ($existingCountry) {
                        // 检查是否需要更新
                        $needsUpdate = false;
                        $updateData = [
                            Countries::fields_CODE => $code,
                            Countries::fields_FLAG => $countryData[Countries::fields_FLAG]
                        ];
                        
                        // 检查FLAG字段是否有变化
                        if ($existingCountry->getData(Countries::fields_FLAG) !== $countryData[Countries::fields_FLAG]) {
                            $needsUpdate = true;
                        }
                        
                        // 对于中国，确保状态正确
                        if ($code === 'CN') {
                            $currentInstall = $existingCountry->getData(Countries::fields_IS_INSTALL);
                            $currentActive = $existingCountry->getData(Countries::fields_IS_ACTIVE);
                            
                            if ($currentInstall != 1 || $currentActive != 1) {
                                $needsUpdate = true;
                                $updateData[Countries::fields_IS_INSTALL] = 1;
                                $updateData[Countries::fields_IS_ACTIVE] = 1;
                            }
                        }
                        
                        if ($needsUpdate) {
                            $update_countries[] = $updateData;
                            $update_countries_display[] = $displayData;
                        } else {
                            $skippedCount++;
                        }
                    }
                } else {
                    // 新国家，需要插入
                    $insert_countries[] = $countryData;
                    $insert_countries_display[] = $displayData;
                }
            }
            
            $totalProcessed = 0;
            
            // 调试信息
            $this->getMessageManager()->addSuccess(__('准备处理数据：插入%{1}个，更新%{2}个，跳过%{3}个', [
                count($insert_countries),
                count($update_countries),
                $skippedCount
            ]));
            
            // 插入新国家数据
            if (!empty($insert_countries)) {
                $countries->clearQuery()->insert($insert_countries, Countries::fields_CODE)->fetch();
                // 使用联合唯一索引字段作为冲突检测
                $localeNames->clearQuery()->insert($insert_countries_display, $localeNames::fields_COUNTRY_CODE . ',' . $localeNames::fields_DISPLAY_LOCALE_CODE)->fetch();
                $totalProcessed += count($insert_countries);
                $this->getMessageManager()->addSuccess(__('成功插入%{1}个国家数据！', [count($insert_countries)]));
            }
            
            // 更新现有国家数据
            if (!empty($update_countries)) {
                foreach ($update_countries as $updateData) {
                    $countries->clearQuery()
                        ->where(Countries::fields_CODE, $updateData[Countries::fields_CODE])
                        ->update($updateData)->fetch();
                }
                
                foreach ($update_countries_display as $displayData) {
                    $localeNames->clearQuery()
                        ->where(Name::fields_COUNTRY_CODE, $displayData[Name::fields_COUNTRY_CODE])
                        ->where(Name::fields_DISPLAY_LOCALE_CODE, $displayData[Name::fields_DISPLAY_LOCALE_CODE])
                        ->update([
                            Name::fields_DISPLAY_NAME => $displayData[Name::fields_DISPLAY_NAME]
                        ])->fetch();
                }
                $totalProcessed += count($update_countries);
                $this->getMessageManager()->addSuccess(__('成功更新%{1}个国家数据！', [count($update_countries)]));
            }
                
                // 为中国安装并激活zh_Hans_CN区域（在事务外执行）
                try {
                    $this->installChinaLocale();
                    $this->getMessageManager()->addSuccess(__('已为中国安装并激活zh_Hans_CN区域！'));
                } catch (\Exception $e) {
                    // 如果安装中国区域失败，不影响整体流程
                    w_log_error('Failed to install China locale: ' . $e->getMessage(), [], 'i18n');
                    // 不显示警告消息，避免影响用户体验
                }
                
                // 显示结果消息
                if ($totalProcessed > 0) {
                    $this->getMessageManager()->addSuccess(__('成功处理%{1}个国家信息！', [$totalProcessed]));
                }
                
                if ($skippedCount > 0) {
                    $this->getMessageManager()->addSuccess(__('跳过%{1}个已存在且无需更新的国家。', [$skippedCount]));
                }
                
                if ($totalProcessed === 0 && $skippedCount === 0) {
                    $this->getMessageManager()->addWarning(__('没有找到可安装的国家数据！'));
                }
                
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addException($e);
        }
        
        $this->redirect('*/backend/countries');
    }
    
    /**
     * 为中国安装并激活zh_Hans_CN区域
     */
    private function installChinaLocale(): void
    {
        try {
            $locale = ObjectManager::getInstance(\Weline\I18n\Model\Locale::class);
            
            // 直接加载zh_Hans_CN区域
            $locale->clearQuery()->load(\Weline\I18n\Model\Locale::fields_CODE, 'zh_Hans_CN');
            if ($locale->getId()) {
                // 已存在，使用update方法直接更新（不涉及事务）
                $locale->clearQuery()
                    ->where(\Weline\I18n\Model\Locale::fields_CODE, 'zh_Hans_CN')
                    ->update([
                        \Weline\I18n\Model\Locale::fields_IS_INSTALL => 1,
                        \Weline\I18n\Model\Locale::fields_IS_ACTIVE => 1
                    ])->fetch();
            } else {
                // 不存在，创建新的区域记录
                $localeData = [
                    \Weline\I18n\Model\Locale::fields_CODE => 'zh_Hans_CN',
                    \Weline\I18n\Model\Locale::fields_COUNTRY_CODE => 'CN',
                    \Weline\I18n\Model\Locale::fields_IS_INSTALL => 1,
                    \Weline\I18n\Model\Locale::fields_IS_ACTIVE => 1,
                    \Weline\I18n\Model\Locale::fields_FLAG => ''
                ];
                
                $locale->clearQuery()->insert($localeData, [\Weline\I18n\Model\Locale::fields_CODE])->fetch();
            }
        } catch (\Exception $e) {
            // 静默处理异常，不影响主流程
            w_log_error('China locale installation failed: ' . $e->getMessage(), [], 'i18n');
        }
    }
}
