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
    public const fields_ID = Page::fields_ID;
    
    // 多语言字段
    public const fields_NAME = 'name';
    public const fields_TITLE = 'title';
    public const fields_CONTENT = 'content';
    public const fields_META_TITLE = 'meta_title';
    public const fields_META_DESCRIPTION = 'meta_description';
    public const fields_META_KEYWORDS = 'meta_keywords';
}
