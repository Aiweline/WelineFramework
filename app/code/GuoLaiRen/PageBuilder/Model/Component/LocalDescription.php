<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 组件多语言翻译模型
 * 
 * 用于存储AI组件生成的历史信息，支持多语言
 */

namespace GuoLaiRen\PageBuilder\Model\Component;

use Weline\I18n\LocalModel;
use GuoLaiRen\PageBuilder\Model\Component;

class LocalDescription extends LocalModel
{
    public const table = 'guolairen_page_builder_component_local_description';
    public const indexer = 'component_local_description';
    
    // 关联主表ID
    public const fields_ID = Component::fields_ID;
    
    // 多语言字段
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    
    // AI组件生成历史信息字段（存储在config字段中）
    // config.ai_generation_history 存储历史生成参数
}
