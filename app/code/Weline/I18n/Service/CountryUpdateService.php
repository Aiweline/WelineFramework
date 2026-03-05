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

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\I18n\Model\I18n;

class CountryUpdateService
{
    private I18n $i18n;
    private Countries $countries;
    private Name $localeNames;

    public function __construct()
    {
        $this->i18n = ObjectManager::getInstance(I18n::class);
        $this->countries = ObjectManager::getInstance(Countries::class);
        $this->localeNames = ObjectManager::getInstance(Name::class);
    }

    /**
     * 检测国家信息是否齐全
     * 
     * @return bool 返回true表示国家信息齐全，false表示需要更新
     */
    public function isCountryDataComplete(): bool
    {
        try {
            // 获取所有可用的国家代码
            $availableCountries = $this->i18n->getCountries(Cookie::getLangLocal());
            $availableCountryCodes = array_keys($availableCountries);
            
            // 检查数据库中已存在的国家数量
            $existingCountries = $this->countries
                ->clearQuery()
                ->select()
                ->fetch()
                ->getItems();
            
            $existingCountryCodes = [];
            foreach ($existingCountries as $country) {
                $existingCountryCodes[] = $country->getData(Countries::schema_fields_CODE);
            }
            
            // 比较可用国家数量和已存在国家数量
            $availableCount = count($availableCountryCodes);
            $existingCount = count($existingCountryCodes);
            
            // 如果数量不匹配，说明数据不完整
            if ($availableCount !== $existingCount) {
                return false;
            }
            
            // 检查是否所有可用国家都已存在于数据库中
            $missingCountries = array_diff($availableCountryCodes, $existingCountryCodes);
            if (!empty($missingCountries)) {
                return false;
            }
            
            // 检查显示名称数据是否完整
            $displayNames = $this->localeNames
                ->clearQuery()
                ->where(Name::schema_fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal())
                ->select()
                ->fetch()
                ->getItems();
            
            $displayNameCount = count($displayNames);
            if ($displayNameCount !== $availableCount) {
                return false;
            }
            
            return true;
            
        } catch (\Exception $e) {
            // 如果检测过程中出现异常，认为数据不完整
            w_log_error('Country data completeness check failed: ' . $e->getMessage(), [], 'i18n');
            return false;
        }
    }

    /**
     * 自动更新国家信息
     * 
     * @return array 返回更新结果
     */
    public function autoUpdateCountries(): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'updated_count' => 0,
            'errors' => []
        ];

        try {
            // 获取所有可用的国家信息
            $countries = $this->i18n->getCountries(Cookie::getLangLocal());
            $insert_countries = [];
            $insert_countries_display = [];
            
            foreach ($countries as $code => $country) {
                $insert_countries[] = [
                    Countries::schema_fields_CODE => $code,
                    Countries::schema_fields_FLAG => (string)$this->i18n->getCountryFlag($code),
                    Countries::schema_fields_IS_ACTIVE => 0,
                    Countries::schema_fields_IS_INSTALL => 0,
                ];
                $insert_countries_display[] = [
                    Name::schema_fields_COUNTRY_CODE => $code,
                    Name::schema_fields_DISPLAY_LOCALE_CODE => Cookie::getLangLocal(),
                    Name::schema_fields_DISPLAY_NAME => $country,
                ];
            }

            // 开始事务
            $this->countries->beginTransaction();
            
            try {
                // 插入国家数据（使用INSERT IGNORE或ON DUPLICATE KEY UPDATE）
                $this->countries->clearQuery()->insert($insert_countries, Countries::schema_fields_CODE)->fetch();
                
                // 插入显示名称数据
                $this->localeNames->insert($insert_countries_display, [
                    $this->localeNames::schema_fields_COUNTRY_CODE,
                    $this->localeNames::schema_fields_DISPLAY_LOCALE_CODE,
                    $this->localeNames::schema_fields_DISPLAY_NAME
                ])->fetch();
                
                $this->countries->commit();
                
                $result['success'] = true;
                $result['message'] = __('国家信息更新成功！共更新%{1}个国家。', [count($insert_countries)]);
                $result['updated_count'] = count($insert_countries);
                
            } catch (\Exception $e) {
                $this->countries->rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            $result['success'] = false;
            $result['message'] = __('国家信息更新失败：%{1}', [$e->getMessage()]);
            $result['errors'][] = $e->getMessage();
            w_log_error('Country auto-update failed: ' . $e->getMessage(), [], 'i18n');
        }

        return $result;
    }

    /**
     * 检查并自动更新国家信息（如果需要）
     * 
     * @return array 返回检查结果
     */
    public function checkAndUpdateCountries(): array
    {
        $result = [
            'checked' => false,
            'updated' => false,
            'message' => '',
            'updated_count' => 0
        ];

        try {
            // 检查国家信息是否完整
            if ($this->isCountryDataComplete()) {
                $result['checked'] = true;
                $result['message'] = __('国家信息已完整，无需更新。');
                return $result;
            }

            // 执行自动更新
            $updateResult = $this->autoUpdateCountries();
            
            $result['checked'] = true;
            $result['updated'] = $updateResult['success'];
            $result['message'] = $updateResult['message'];
            $result['updated_count'] = $updateResult['updated_count'];
            
            if (!$updateResult['success']) {
                $result['errors'] = $updateResult['errors'];
            }
            
        } catch (\Exception $e) {
            $result['checked'] = true;
            $result['updated'] = false;
            $result['message'] = __('国家信息检查失败：%{1}', [$e->getMessage()]);
            w_log_error('Country check and update failed: ' . $e->getMessage(), [], 'i18n');
        }

        return $result;
    }

    /**
     * 获取国家信息统计
     * 
     * @return array
     */
    public function getCountryStats(): array
    {
        try {
            $availableCountries = $this->i18n->getCountries(Cookie::getLangLocal());
            $availableCount = count($availableCountries);
            
            $existingCountries = $this->countries
                ->clearQuery()
                ->select()
                ->fetch()
                ->getItems();
            $existingCount = count($existingCountries);
            
            $activeCountries = $this->countries
                ->clearQuery()
                ->where(Countries::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();
            $activeCount = count($activeCountries);
            
            return [
                'available_count' => $availableCount,
                'existing_count' => $existingCount,
                'active_count' => $activeCount,
                'is_complete' => $this->isCountryDataComplete()
            ];
            
        } catch (\Exception $e) {
            return [
                'available_count' => 0,
                'existing_count' => 0,
                'active_count' => 0,
                'is_complete' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
