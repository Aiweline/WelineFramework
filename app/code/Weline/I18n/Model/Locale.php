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
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\TranslationService\Helper\LanguageCodeConverter;
#[Table(comment: '地区')]
#[Index(name: 'idx_code', columns: ['country_code'], comment: '国家码索引')]
#[Index(name: 'idx_short_code', columns: ['short_code'], comment: '简码索引')]
#[Index(name: 'idx_iso2', columns: ['iso2'], comment: 'ISO2索引')]
#[Index(name: 'idx_iso3', columns: ['iso3'], comment: 'ISO3索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '状态索引')]
#[Index(name: 'idx_is_install', columns: ['is_install'], comment: '安装索引')]
class Locale extends Model
{
    public const schema_table = 'i18n_locale';
    public const schema_primary_key = 'code';
    #[Col('varchar', 12, primaryKey: true, nullable: false, comment: '地方代码')]
    public const schema_fields_ID = 'code';
    #[Col('varchar', 12, primaryKey: true, nullable: false, comment: '地方代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 2, nullable: false, comment: '国家码')]
    public const schema_fields_COUNTRY_CODE = 'country_code';
    #[Col('varchar', 4, comment: '简码（如ZH、TW、EN）')]
    public const schema_fields_SHORT_CODE = 'short_code';
    #[Col('varchar', 2, comment: 'ISO 639-1代码（两字母）')]
    public const schema_fields_ISO2 = 'iso2';
    #[Col('varchar', 3, comment: 'ISO 639-2代码（三字母）')]
    public const schema_fields_ISO3 = 'iso3';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '启用状态')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否安装')]
    public const schema_fields_IS_INSTALL = 'is_install';
    #[Col('text', comment: '国旗')]
    public const schema_fields_FLAG = 'flag';
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
}
