<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/21 22:05:23
 */

namespace Weline\I18n\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\I18n\Model\Locale\Name;
use Weline\TranslationService\Helper\LanguageCodeConverter;

class Locale extends \Weline\Framework\Database\Model
{
    public const table = "i18n_locale";
    public const fields_ID = 'code';
    public const fields_CODE = 'code';
    public const fields_COUNTRY_CODE = 'country_code';
    public const fields_SHORT_CODE = 'short_code';
    public const fields_ISO2 = 'iso2';
    public const fields_ISO3 = 'iso3';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_IS_INSTALL = 'is_install';
    public const fields_FLAG = 'flag';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 如果表已存在，尝试添加新字段
        if ($setup->tableExist()) {
            // 添加short_code字段
            if (!$setup->hasField(self::fields_SHORT_CODE)) {
                $setup->alterTable()
                    ->addColumn(self::fields_SHORT_CODE, self::fields_COUNTRY_CODE, TableInterface::column_type_VARCHAR, 4, '', '简码（如ZH、TW、EN）')
                    ->alter();
                // 添加索引
                try {
                    $setup->query("ALTER TABLE `{$setup->getTable()}` ADD INDEX `idx_short_code` (`" . self::fields_SHORT_CODE . "`)");
                } catch (\Exception $e) {
                    // 索引可能已存在，忽略错误
                }
            }
            
            // 添加iso2字段
            if (!$setup->hasField(self::fields_ISO2)) {
                $setup->alterTable()
                    ->addColumn(self::fields_ISO2, self::fields_SHORT_CODE, TableInterface::column_type_VARCHAR, 2, '', 'ISO 639-1代码（两字母）')
                    ->alter();
                // 添加索引
                try {
                    $setup->query("ALTER TABLE `{$setup->getTable()}` ADD INDEX `idx_iso2` (`" . self::fields_ISO2 . "`)");
                } catch (\Exception $e) {
                    // 索引可能已存在，忽略错误
                }
            }
            
            // 添加iso3字段
            if (!$setup->hasField(self::fields_ISO3)) {
                $setup->alterTable()
                    ->addColumn(self::fields_ISO3, self::fields_ISO2, TableInterface::column_type_VARCHAR, 3, '', 'ISO 639-2代码（三字母）')
                    ->alter();
                // 添加索引
                try {
                    $setup->query("ALTER TABLE `{$setup->getTable()}` ADD INDEX `idx_iso3` (`" . self::fields_ISO3 . "`)");
                } catch (\Exception $e) {
                    // 索引可能已存在，忽略错误
                }
            }
        }
    }
    
    /**
     * 从locale代码中提取简码、ISO2和ISO3
     * 
     * @param string $localeCode locale代码（如zh_Hans_CN、en_US）
     * @return array 包含short_code、iso2、iso3的数组
     */
    public static function extractLocaleCodes(string $localeCode): array
    {
        // 提取ISO2和ISO3
        $iso2 = strtoupper(LanguageCodeConverter::toIso6391($localeCode));
        $iso3 = strtoupper(LanguageCodeConverter::toIso6392($localeCode));
        
        // 提取简码（根据theme.js的逻辑）
        $shortCode = self::extractShortCode($localeCode);
        
        return [
            'short_code' => $shortCode,
            'iso2' => $iso2,
            'iso3' => $iso3,
        ];
    }
    
    /**
     * 从locale代码中提取简码
     * 逻辑与theme.js中的getLangDisplay函数保持一致
     * 
     * @param string $localeCode locale代码（如zh_Hans_CN、en_US）
     * @return string 简码（如ZH、TW、EN）
     */
    public static function extractShortCode(string $localeCode): string
    {
        if (empty($localeCode)) {
            return 'ZH';
        }
        
        // 提取语言代码的主要部分
        $parts = explode('_', $localeCode);
        if (count($parts) >= 2) {
            // 取前两个部分，如 zh_Hans -> ZH, en_US -> EN
            $lang = strtoupper($parts[0]);
            $region = strtoupper($parts[1]);
            
            // 如果是中文，显示 ZH
            if ($lang === 'ZH') {
                if ($region === 'HANT') {
                    return 'TW'; // 繁体中文显示 TW
                }
                return 'ZH';
            }
            
            // 其他语言显示前两个字母
            return substr($lang, 0, 2);
        }
        
        // 如果格式不对，返回前两个大写字母
        return strtoupper(substr($localeCode, 0, 2));
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_VARCHAR, 12, 'primary key', '地方代码')
                ->addColumn(self::fields_COUNTRY_CODE, TableInterface::column_type_VARCHAR, 2, 'not null', '国家码')
                ->addColumn(self::fields_SHORT_CODE, TableInterface::column_type_VARCHAR, 4, '', '简码（如ZH、TW、EN）')
                ->addColumn(self::fields_ISO2, TableInterface::column_type_VARCHAR, 2, '', 'ISO 639-1代码（两字母）')
                ->addColumn(self::fields_ISO3, TableInterface::column_type_VARCHAR, 3, '', 'ISO 639-2代码（三字母）')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '启用状态')
                ->addColumn(self::fields_IS_INSTALL, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '是否安装')
                ->addColumn(self::fields_FLAG, TableInterface::column_type_TEXT, 100000, '', '国旗')
                ->addIndex(TableInterface::index_type_KEY, 'idx_code', self::fields_COUNTRY_CODE, '国家码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_short_code', self::fields_SHORT_CODE, '简码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_iso2', self::fields_ISO2, 'ISO2索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_iso3', self::fields_ISO3, 'ISO3索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_install', self::fields_IS_INSTALL, '安装索引')
                ->create();
        }
    }
}
