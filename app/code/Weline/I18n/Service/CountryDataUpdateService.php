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

namespace Weline\I18n\Service;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\Framework\App\Env;
use Weline\I18n\Model\I18n;

class CountryDataUpdateService
{
    private Countries $countries;
    private Name $localeNames;
    private I18n $i18n;

    public function __construct()
    {
        $this->countries = ObjectManager::getInstance(Countries::class);
        $this->localeNames = ObjectManager::getInstance(Name::class);
        $this->i18n = ObjectManager::getInstance(I18n::class);
    }

    /**
     * 更新国家数据
     * 如果数据库中没有国家数据，则自动导入全球国家数据
     */
    public function updateCountryData(): bool
    {
        try {
            // 检查是否已有国家数据
            $existingCount = $this->countries->clearQuery()->count();
            if ($existingCount > 0) {
                // 已有数据，检查是否需要更新显示名称
                $this->updateMissingDisplayNames();
                return true;
            }

            // 没有数据，导入全球国家数据
            $this->importGlobalCountries();
            return true;

        } catch (\Exception $e) {
            w_log_error('Country data update failed: ' . $e->getMessage(), [], 'i18n');
            return false;
        }
    }

    /**
     * 导入全球国家数据
     */
    private function importGlobalCountries(): void
    {
        // 获取所有可用的国家信息
        $availableCountries = $this->i18n->getCountries(Cookie::getLangLocal());
        $insert_countries = [];
        $insert_countries_display = [];
        
        foreach ($availableCountries as $code => $country) {
            $countryData = [
                Countries::fields_CODE => $code,
                Countries::fields_FLAG => (string)$this->i18n->getCountryFlag($code),
                Countries::fields_IS_ACTIVE => ($code === 'CN') ? 1 : 0, // 中国默认已激活，其他国家未激活
                Countries::fields_IS_INSTALL => ($code === 'CN') ? 1 : 0, // 中国默认已安装，其他国家未安装
            ];
            
            $displayData = [
                Name::fields_COUNTRY_CODE => $code,
                Name::fields_DISPLAY_NAME => $country,
                Name::fields_DISPLAY_LOCALE_CODE => Cookie::getLangLocal(),
            ];
            
            $insert_countries[] = $countryData;
            $insert_countries_display[] = $displayData;
        }
        
        // 批量插入数据
        if (!empty($insert_countries)) {
            # 事务
            $this->countries->beginTransaction();
            try {
                // 分批插入，每批999条，数据库限制
                $insert_countries = array_chunk($insert_countries, 50);
                foreach ($insert_countries as $batch) {
                    $this->countries->clear()->insert($batch, Countries::fields_CODE)->fetch();
                }
                $insert_countries_display = array_chunk($insert_countries_display, 50);
                foreach ($insert_countries_display as $batch) {
                    // 使用联合唯一索引字段作为冲突检测
                    $this->localeNames->clear()->insert($batch, Name::fields_COUNTRY_CODE . ',' . Name::fields_DISPLAY_LOCALE_CODE)->fetch();
                }
                $this->countries->commit();
            } catch (\Exception $e) {
                $this->countries->rollBack();
                Message::exception($e);
            }
        }
    }

    /**
     * 更新缺失的显示名称
     */
    private function updateMissingDisplayNames(): void
    {
        // 获取所有国家数据
        $allCountries = $this->countries->clearQuery()
            ->select()
            ->fetch()
            ->getItems();
        
        if (empty($allCountries)) {
            return;
        }
        
        // 检查每个国家是否有对应的显示名称
        $missingData = [];
        foreach ($allCountries as $country) {
            $countryCode = $country->getData(Countries::fields_CODE);
            
            // 检查是否存在显示名称
            $existingName = $this->localeNames->clearQuery()
                ->where(Name::fields_COUNTRY_CODE, $countryCode)
                ->where(Name::fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal())
                ->find()
                ->fetch();
            if (!$existingName->getId()) {
                // 获取国家名称
                $countryNames = $this->i18n->getCountries(Cookie::getLangLocal());
                if (isset($countryNames[$countryCode])) {
                    $missingData[] = [
                        Name::fields_COUNTRY_CODE => $countryCode,
                        Name::fields_DISPLAY_NAME => $countryNames[$countryCode],
                        Name::fields_DISPLAY_LOCALE_CODE => Cookie::getLangLocal()
                    ];
                }
            }
        }
        
        // 批量插入缺失的显示名称数据
        if (!empty($missingData)) {
            # 事务
            $this->localeNames->beginTransaction();
            try {
            $this->localeNames->clearQuery()
                    ->insert($missingData, Name::fields_COUNTRY_CODE.',' . Name::fields_DISPLAY_LOCALE_CODE)
                    ->fetch();
                $this->localeNames->commit();
            } catch (\Exception $e) {
                $this->localeNames->rollBack();
                Message::exception($e);
            }
        }
    }

}
