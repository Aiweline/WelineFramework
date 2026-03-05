<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/22 14:42:04
 */
namespace Weline\I18n\Model\Countries\Locale;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '国家语言名')]
#[Index(name: 'idx_country_code', columns: ['country_code'], comment: '国码索引')]
#[Index(name: 'idx_display_locale_code', columns: ['display_locale_code'], comment: '展示区码索引')]
#[Index(name: 'idx_display_name', columns: ['display_name'], comment: '国名索引')]
#[Index(name: 'uk_country_display_locale', columns: ['country_code', 'display_locale_code'], type: 'UNIQUE', comment: '国家语言唯一索引')]
class Name extends Model
{
    public const schema_table = 'i18n_countries_locale_name';
    /** @var list<string> */
    public const schema_primary_keys = ['country_code', 'display_locale_code'];
    #[Col('varchar', 12, nullable: false, comment: '国家码')]
    public const schema_fields_ID = 'country_code';
    #[Col('varchar', 12, nullable: false, comment: '国家码')]
    public const schema_fields_COUNTRY_CODE = 'country_code';
    #[Col('varchar', 12, nullable: false, comment: '展示地区码')]
    public const schema_fields_DISPLAY_LOCALE_CODE = 'display_locale_code';
    #[Col('varchar', 255, nullable: false, comment: '国名')]
    public const schema_fields_DISPLAY_NAME = 'display_name';
}
