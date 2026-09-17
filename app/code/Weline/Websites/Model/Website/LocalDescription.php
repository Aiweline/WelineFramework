<?php

declare(strict_types=1);

namespace Weline\Websites\Model\Website;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Websites\Model\Website;

/**
 * 网站基本信息多语言（名称 / 简介）。
 * 主表 {@see Website} 存默认文案；本表按 local_code 存翻译，供官方 &lt;local&gt; 与 LocalModelTranslation 扫描。
 */
#[Table(comment: '网站基本信息多语言')]
#[Index(name: 'uk_website_local', columns: ['website_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_websites_website_local';
    public const schema_primary_key = Website::schema_fields_ID;
    public const indexer = 'websites_website_local';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '网站ID')]
    public const schema_fields_ID = Website::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 128, nullable: true, comment: '网站名称')]
    public const schema_fields_NAME = Website::schema_fields_NAME;

    #[Col(type: 'varchar', length: 500, nullable: true, comment: '网站简介')]
    public const schema_fields_DESCRIPTION = Website::schema_fields_DESCRIPTION;

    /** TraitLocalModel::getName() 读此列 */
    public const schema_fields_name = Website::schema_fields_NAME;
}
