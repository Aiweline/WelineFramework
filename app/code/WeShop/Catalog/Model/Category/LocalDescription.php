<?php

declare(strict_types=1);

namespace WeShop\Catalog\Model\Category;

use WeShop\Catalog\Model\Category;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\LocalModel;

/**
 * 分类本地化描述模型
 * 须通过 #[Table]/#[Col] 声明表结构，由 setup:upgrade 同步，否则缺少 category_id 等列会报错。
 */
#[Table(comment: '分类多语言翻译表')]
#[Index(name: 'idx_category_id', columns: ['category_id'], comment: '分类ID索引')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weshop_catalog_category_local_description';
    /** 联合主键：(category_id, local_code) */
    public const schema_primary_keys = ['category_id', 'local_code'];
    public const indexer = 'catalog_category_local_description';

    /** 关联主表ID（须有 #[Col] 才能被 SchemaDiff 建表/加列） */
    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '关联分类ID')]
    public const schema_fields_ID = Category::schema_fields_ID;

    /** 语言代码 */
    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_local_code = 'local_code';

    /** 多语言字段 */
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '分类名称')]
    public const schema_fields_name = 'name';
    #[Col(type: 'text', nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Meta标题')]
    public const schema_fields_META_TITLE = 'meta_title';
    #[Col(type: 'text', nullable: true, comment: 'Meta描述')]
    public const schema_fields_META_DESCRIPTION = 'meta_description';
    #[Col(type: 'text', nullable: true, comment: 'Meta关键词')]
    public const schema_fields_META_KEYWORDS = 'meta_keywords';
    
    /**
     * 获取本地化分类名称
     * @return string
     */
    public function getLocalName(): string
    {
        return (string)$this->getData(self::schema_fields_name);
    }
    
    /**
     * 获取本地化分类描述
     * @return string
     */
    public function getLocalDescription(): string
    {
        return (string)$this->getData(self::schema_fields_DESCRIPTION);
    }
    
    /**
     * 获取本地化Meta标题
     * @return string
     */
    public function getLocalMetaTitle(): string
    {
        return (string)$this->getData(self::schema_fields_META_TITLE);
    }
    
    /**
     * 获取本地化Meta描述
     * @return string
     */
    public function getLocalMetaDescription(): string
    {
        return (string)$this->getData(self::schema_fields_META_DESCRIPTION);
    }
    
    /**
     * 获取本地化Meta关键词
     * @return string
     */
    public function getLocalMetaKeywords(): string
    {
        return (string)$this->getData(self::schema_fields_META_KEYWORDS);
    }
}
