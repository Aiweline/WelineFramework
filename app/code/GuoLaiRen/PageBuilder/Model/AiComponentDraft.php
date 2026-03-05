<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 组件草稿模型 - 用于 component-stream 生成完成后暂存装配好的 phtml，供「按 id 取预览」使用
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: 'PageBuilder AI组件草稿表')]
#[Index(name: 'idx_session', columns: ['session_id'], comment: '会话索引')]
#[Index(name: 'idx_created', columns: ['created_at'], comment: '创建时间索引')]
class AiComponentDraft extends Model
{
    public const schema_table = 'guolairen_page_builder_ai_component_draft';
    public const schema_primary_key = 'draft_id';


    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '草稿ID')]
    public const schema_fields_ID = 'draft_id';
    #[Col(type: 'longtext', nullable: true, comment: '装配后的完整phtml')]
    public const schema_fields_TEMPLATE_CONTENT = 'template_content';
    #[Col(type: 'text', nullable: true, comment: '组件元信息JSON(name,code,region,style_code等)')]
    public const schema_fields_COMPONENT_META = 'component_meta';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '会话ID(可选,用于清理过期草稿)')]
    public const schema_fields_SESSION_ID = 'session_id';
    #[Col(type: 'int', nullable: true, comment: '创建时间戳')]
    public const schema_fields_CREATED_AT = 'created_at';

}

