<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;

/**
 * 营销规则多语言翻译模型
 *
 * @package Weline_Marketing
 */
#[Table(comment: '营销规则多语言描述表')]
#[Index(name: 'uniq_marketing_rule_local_description', columns: ['id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_marketing_rule_local_description';
    public const schema_primary_key = Rule::schema_fields_ID;
    public const indexer = 'marketing_rule_local_description';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '规则ID')]
    public const schema_fields_ID = Rule::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '规则名称')]
    public const schema_fields_NAME = 'name';

    #[Col(type: 'text', nullable: true, comment: '规则描述')]
    public const schema_fields_DESCRIPTION = 'description';
}
