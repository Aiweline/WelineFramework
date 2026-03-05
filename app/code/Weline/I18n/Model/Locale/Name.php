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

namespace Weline\I18n\Model\Locale;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '地区/语言展示名')]
#[Index(name: 'idx_locale_code', columns: ['locale_code'], comment: '区码索引')]
#[Index(name: 'idx_display_locale_code', columns: ['display_locale_code'], comment: '展示区码索引')]
#[Index(name: 'uk_locale_display_locale', columns: ['locale_code', 'display_locale_code'], type: 'UNIQUE', comment: '区域语言唯一索引')]
class Name extends \Weline\Framework\Database\Model
{
    public const schema_table = 'i18n_locale_name';
    /** @var list<string> */
    public const schema_primary_keys = ['locale_code', 'display_locale_code'];
    public const fields_LOCALE_CODE = 'locale_code';
    public const fields_DISPLAY_LOCALE_CODE = 'display_locale_code';
    public const fields_DISPLAY_NAME = 'display_name';

    #[Col('varchar', 12, nullable: false, comment: '地区码')]
    public const schema_fields_ID = 'locale_code';
    #[Col('varchar', 12, nullable: false, comment: '地区码')]
    public const schema_fields_LOCALE_CODE = 'locale_code';
    #[Col('varchar', 12, nullable: false, comment: '展示地区码')]
    public const schema_fields_DISPLAY_LOCALE_CODE = 'display_locale_code';
    #[Col('varchar', 255, nullable: false, comment: '地区名')]
    public const schema_fields_DISPLAY_NAME = 'display_name';

    public array $_unit_primary_keys = ['locale_code', 'display_locale_code'];

    /** 表结构由 SchemaDiffStage 负责 */
    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    public function install(ModelSetup $setup, Context $context): void {}
}
