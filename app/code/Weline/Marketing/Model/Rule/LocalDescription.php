<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule;

use Weline\I18n\LocalModel;
use Weline\Marketing\Model\Rule\Rule;

/**
 * 营销规则多语言翻译模型
 * 
 * @package Weline_Marketing
 */
class LocalDescription extends LocalModel
{
    public const table = 'weline_marketing_rule_local_description';
    public const indexer = 'marketing_rule_local_description';
    
    // 关联主表ID
    public const fields_ID = Rule::fields_ID;
    
    // 多语言字段
    public const fields_NAME = 'name';           // 规则名称
    public const fields_DESCRIPTION = 'description'; // 规则描述
}

