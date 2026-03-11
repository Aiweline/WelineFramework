<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 组件多语言翻译模型
 *
 * 用于存储AI组件生成的历史信息，支持多语言
 * 须通过 #[Table]/#[Col] 声明表结构，由 setup:upgrade 同步，否则缺少 component_id 等列会报错。
 */

namespace GuoLaiRen\PageBuilder\Model\Component;

use GuoLaiRen\PageBuilder\Model\Component;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\LocalModel;

#[Table(comment: '组件多语言翻译表')]
#[Index(name: 'idx_component_id', columns: ['component_id'], comment: '组件ID索引')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'guolairen_page_builder_component_local_description';
    /** 联合主键：(component_id, local_code) */
    public const schema_primary_keys = ['component_id', 'local_code'];
    public const indexer = 'component_local_description';

    /** 关联主表ID（须有 #[Col] 才能被 SchemaDiff 建表/加列） */
    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '关联组件ID')]
    public const schema_fields_ID = Component::schema_fields_ID;

    /** 语言代码（与 LocalModel 接口 schema_fields_local_code 一致） */
    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_local_code = 'local_code';

    /** 配置 JSON（AI 生成历史等） */
    #[Col(type: 'text', nullable: true, comment: '配置JSON')]
    public const schema_fields_config = 'config';

    /** 多语言字段 */
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'text', nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
}
