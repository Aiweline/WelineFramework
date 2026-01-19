<?php

declare(strict_types=1);

namespace WeShop\Catalog\Model\Category;

use WeShop\Catalog\Model\Category;
use Weline\I18n\LocalModel;

/**
 * 分类本地化描述模型
 */
class LocalDescription extends LocalModel
{
    public const indexer = 'catalog_category_local_description';
    
    // 关联主表ID字段（必须）
    public const fields_ID = Category::fields_ID;
    
    // 多语言字段定义
    public const fields_name = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_META_TITLE = 'meta_title';
    public const fields_META_DESCRIPTION = 'meta_description';
    public const fields_META_KEYWORDS = 'meta_keywords';
    
    /**
     * 获取本地化分类名称
     * @return string
     */
    public function getLocalName(): string
    {
        return (string)$this->getData(self::fields_name);
    }
    
    /**
     * 获取本地化分类描述
     * @return string
     */
    public function getLocalDescription(): string
    {
        return (string)$this->getData(self::fields_DESCRIPTION);
    }
    
    /**
     * 获取本地化Meta标题
     * @return string
     */
    public function getLocalMetaTitle(): string
    {
        return (string)$this->getData(self::fields_META_TITLE);
    }
    
    /**
     * 获取本地化Meta描述
     * @return string
     */
    public function getLocalMetaDescription(): string
    {
        return (string)$this->getData(self::fields_META_DESCRIPTION);
    }
    
    /**
     * 获取本地化Meta关键词
     * @return string
     */
    public function getLocalMetaKeywords(): string
    {
        return (string)$this->getData(self::fields_META_KEYWORDS);
    }
}
