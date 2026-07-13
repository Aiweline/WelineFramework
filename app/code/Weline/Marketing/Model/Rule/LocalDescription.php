<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule;

use Weline\I18n\Api\Localization\LocalModel;
use Weline\Marketing\Model\Rule\Rule;

/**
 * 营销规则多语言翻译模型
 * 
 * @package Weline_Marketing
 */
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_marketing_rule_local_description';
    public const schema_primary_key = Rule::schema_fields_ID;
    public const indexer = 'marketing_rule_local_description';
    
    // 关联主表ID
    public const schema_fields_ID = Rule::schema_fields_ID;
    
    // 多语言字段
    public const schema_fields_NAME = 'name';           // 规则名称
    public const schema_fields_DESCRIPTION = 'description'; // 规则描述
}
