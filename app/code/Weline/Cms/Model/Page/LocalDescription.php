<?php

declare(strict_types=1);

/*
 * Weline Cms Module
 * CMS内容管理系统页面多语言翻译模型
 */

namespace Weline\Cms\Model\Page;

use Weline\I18n\LocalModel;
use Weline\Cms\Model\Page;

class LocalDescription extends LocalModel
{
    public const schema_table = 'm_cms_page_local_description';
    public const indexer = 'cms_page_local_description';

    // 关联主表ID
    public const schema_fields_ID = Page::schema_fields_ID;

    // 多语言字段
    public const schema_fields_NAME = 'name';
    public const schema_fields_TITLE = 'title';
    public const schema_fields_CONTENT = 'content';
    public const schema_fields_META_TITLE = 'meta_title';
    public const schema_fields_META_DESCRIPTION = 'meta_description';
    public const schema_fields_META_KEYWORDS = 'meta_keywords';
}

