<?php

declare(strict_types=1);

/*
 * WeShop CMS Module
 * 页面多语言翻译模型 - 完全参照PageBuilder结构
 */

namespace WeShop\Cms\Model\Page;

use Weline\I18n\LocalModel;
use WeShop\Cms\Model\Page;

class LocalDescription extends LocalModel
{
    public const indexer = 'weshop_cms_page_local_description';
    
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
