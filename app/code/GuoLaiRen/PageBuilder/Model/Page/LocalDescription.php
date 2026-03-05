<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 页面多语言翻译模型
 */

namespace GuoLaiRen\PageBuilder\Model\Page;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\LocalModel;

#[Table(comment: '页面多语言翻译表')]
#[Index(name: 'idx_page_id', columns: ['page_id'], comment: '页面ID索引')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'guolairen_page_builder_page_local_description';
    /** 联合主键：(page_id, local_code) */
    public const schema_primary_keys = ['page_id', 'local_code'];
    public const indexer = 'page_local_description';

    /** 关联主表ID（须有 #[Col] 才能被 SchemaDiff 同步） */
    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '关联页面ID')]
    public const schema_fields_ID = Page::schema_fields_ID;

    /** 语言代码（与 LocalModel 接口 schema_fields_local_code 一致） */
    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_local_code = 'local_code';

    /** 配置 JSON（样式配置等） */
    #[Col(type: 'text', nullable: true, comment: '配置JSON')]
    public const schema_fields_config = 'config';

    // 多语言字段
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '页面名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '页面标题')]
    public const schema_fields_TITLE = 'title';
    #[Col(type: 'text', nullable: true, comment: '页面内容')]
    public const schema_fields_CONTENT = 'content';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'SEO标题')]
    public const schema_fields_META_TITLE = 'meta_title';
    #[Col(type: 'text', nullable: true, comment: 'SEO描述')]
    public const schema_fields_META_DESCRIPTION = 'meta_description';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'SEO关键词')]
    public const schema_fields_META_KEYWORDS = 'meta_keywords';
}

