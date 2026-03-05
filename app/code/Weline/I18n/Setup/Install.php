<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Setup;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\InstallInterface;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale\Name as LocaleName;
use Weline\I18n\Model\Locals;

class Install implements InstallInterface
{
    /**
     * 安装模块：地区语言名表 DDL + 全球国家、国家显示名、语言包数据
     */
    public function setup(Setup $setup, Context $context): void
    {
        $this->installLocaleNameTable($setup);
        $this->installAllGlobalCountries();
        $this->installAllGlobalLocales();
    }

    /**
     * 创建 i18n_locale_name 表（原 Model\Locale\Name install DDL）
     */
    private function installLocaleNameTable(Setup $setup): void
    {
        /** @var ModelSetup $modelSetup */
        $modelSetup = ObjectManager::getInstance(ModelSetup::class, ['printing' => $setup->getPrinter()]);
        $model = ObjectManager::getInstance(LocaleName::class);
        $modelSetup->putModel($model);
        if ($modelSetup->tableExist()) {
            return;
        }
        $modelSetup->createTable('地区语言名表')
            ->addColumn(LocaleName::schema_fields_ID, TableInterface::column_type_VARCHAR, 12, 'not null', '地区码')
            ->addColumn(LocaleName::schema_fields_DISPLAY_LOCALE_CODE, TableInterface::column_type_VARCHAR, 12, 'not null', '展示地区码')
            ->addColumn(LocaleName::schema_fields_DISPLAY_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '地区名')
            ->addIndex(TableInterface::index_type_KEY, 'idx_locale_code', LocaleName::schema_fields_LOCALE_CODE, '区码索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_display_locale_code', LocaleName::schema_fields_DISPLAY_LOCALE_CODE, '展示区码索引')
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_locale_display_locale', LocaleName::schema_fields_LOCALE_CODE . ',' . LocaleName::schema_fields_DISPLAY_LOCALE_CODE, '区域语言唯一索引')
            ->create();
    }

    /**
     * 安装全球所有国家信息（国家表 + 国家语言名表）
     */
    private function installAllGlobalCountries(): void
    {
        try {
            /** @var Countries $countries */
            $countries = ObjectManager::getInstance(Countries::class);
            $existingCount = $countries->clearQuery()->count()->fetch()->getFirst();
            if ($existingCount > 0) {
                w_log_info('I18n: Countries already installed, skipping. Count: ' . $existingCount, [], 'i18n');
                return;
            }

            w_log_info('I18n: Starting countries installation...', [], 'i18n');

            $i18n = ObjectManager::getInstance(I18n::class);
            $localeNames = ObjectManager::getInstance(Name::class);

            $countryList = $i18n->getCountries('en');
            w_log_info('I18n: Found ' . count($countryList) . ' available countries', [], 'i18n');

            $insert_countries = [];
            $insert_countries_display = [];

            foreach ($countryList as $code => $country) {
                $insert_countries[] = [
                    Countries::schema_fields_CODE => $code,
                    Countries::schema_fields_FLAG => (string) $i18n->getCountryFlag($code),
                    Countries::schema_fields_IS_ACTIVE => 0,
                    Countries::schema_fields_IS_INSTALL => 1,
                ];
                $insert_countries_display[] = [
                    Name::schema_fields_COUNTRY_CODE => $code,
                    Name::schema_fields_DISPLAY_LOCALE_CODE => 'en',
                    Name::schema_fields_DISPLAY_NAME => $country,
                ];
            }

            if (!empty($insert_countries)) {
                w_log_info('I18n: Inserting ' . count($insert_countries) . ' countries...', [], 'i18n');
                $countries->clearQuery();
                $countries->insert($insert_countries, Countries::schema_fields_CODE)->fetch();

                $localeNames->insert($insert_countries_display, [
                    $localeNames::schema_fields_COUNTRY_CODE,
                    $localeNames::schema_fields_DISPLAY_LOCALE_CODE,
                    $localeNames::schema_fields_DISPLAY_NAME,
                ])->fetch();

                w_log_info('I18n: Successfully installed ' . count($insert_countries) . ' countries', [], 'i18n');
            } else {
                w_log_info('I18n: No countries to insert', [], 'i18n');
            }
        } catch (\Throwable $e) {
            w_log_error('I18n global countries installation failed: ' . $e->getMessage(), [], 'i18n');
            w_log_error('I18n installation trace: ' . $e->getTraceAsString(), [], 'i18n');
        }
    }

    /**
     * 安装全球所有语言包（地区/语言包表）
     */
    private function installAllGlobalLocales(): void
    {
        try {
            /** @var Locals $locals */
            $locals = ObjectManager::getInstance(Locals::class);
            $existingCount = $locals->clearQuery()->count()->fetch()->getFirst();
            if ($existingCount > 0) {
                w_log_info('I18n: Locals already installed, skipping. Count: ' . $existingCount, [], 'i18n');
                return;
            }

            $allLocales = \Symfony\Component\Intl\Locales::getLocales();
            $insertData = [];
            $i18nModel = ObjectManager::getInstance(I18n::class);

            foreach ($allLocales as $locale) {
                try {
                    $localeName = \Symfony\Component\Intl\Locales::getName($locale, 'en');
                } catch (\Exception $e) {
                    if (defined('DEV') && DEV) {
                        w_log_warning("I18n: 无法获取locale '{$locale}' 的名称，跳过: " . $e->getMessage(), [], 'i18n');
                    }
                    continue;
                }

                $countryCode = '';
                $parts = explode('_', $locale);
                $lastPart = end($parts);
                if (strlen($lastPart) === 2 && strtoupper($lastPart) === $lastPart) {
                    $countryCode = $lastPart;
                }

                $flagSvg = '';
                if ($countryCode) {
                    try {
                        $flagSvg = $i18nModel->getCountryFlag($countryCode, 24, 18);
                    } catch (\Exception $e) {
                        $flagSvg = '';
                    }
                }

                $insertData[] = [
                    Locals::schema_fields_CODE => $locale,
                    Locals::schema_fields_TARGET_CODE => $locale,
                    Locals::schema_fields_NAME => $localeName,
                    Locals::schema_fields_IS_ACTIVE => 0,
                    Locals::schema_fields_IS_INSTALL => 1,
                    Locals::schema_fields_FLAG => $flagSvg,
                ];
            }

            if (!empty($insertData)) {
                $locals->clearQuery();
                $locals->insert($insertData, [Locals::schema_fields_CODE, Locals::schema_fields_TARGET_CODE])->fetch();
            }
        } catch (\Throwable $e) {
            w_log_error('I18n global locales installation failed: ' . $e->getMessage(), [], 'i18n');
        }
    }
}
