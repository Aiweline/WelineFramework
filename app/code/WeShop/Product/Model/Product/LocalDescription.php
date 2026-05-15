<?php

namespace WeShop\Product\Model\Product;

use WeShop\Product\Model\Product;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\LocalModel;

#[Table(comment: '产品多语言翻译表')]
#[Index(name: 'idx_product_id', columns: ['product_id'], comment: '产品ID索引')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'product_local_description';
    public const schema_primary_keys = ['product_id', 'local_code'];
    public const indexer = 'product_local_description';

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_local_code = 'local_code';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '产品ID')]
    public const schema_fields_ID = Product::schema_fields_ID;

    #[Col(type: 'varchar', length: 150, nullable: true, comment: '名称')]
    public const schema_fields_NAME = Product::schema_fields_name;

    #[Col(type: 'text', nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = Product::schema_fields_description;

    #[Col(type: 'text', nullable: true, comment: '简短描述')]
    public const schema_fields_SHORT_DESCRIPTION = Product::schema_fields_short_description;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Meta名称')]
    public const schema_fields_META_NAME = Product::schema_fields_meta_name;

    #[Col(type: 'text', nullable: true, comment: 'Meta描述')]
    public const schema_fields_META_DESCRIPTION = Product::schema_fields_meta_description;

    #[Col(type: 'text', nullable: true, comment: 'Meta关键词')]
    public const schema_fields_META_KEYWORDS = Product::schema_fields_meta_keywords;

    #[Col(type: 'text', nullable: true, comment: '本地化扩展配置')]
    public const schema_fields_config = 'config';

    public function getLocalName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }

    public function getLocalDescription(): string
    {
        return (string)$this->getData(self::schema_fields_DESCRIPTION);
    }

    public function getLocalShortDescription(): string
    {
        return (string)$this->getData(self::schema_fields_SHORT_DESCRIPTION);
    }

    public function getLocalMetaName(): string
    {
        return (string)$this->getData(self::schema_fields_META_NAME);
    }

    public function getLocalMetaDescription(): string
    {
        return (string)$this->getData(self::schema_fields_META_DESCRIPTION);
    }

    public function getLocalMetaKeywords(): string
    {
        return (string)$this->getData(self::schema_fields_META_KEYWORDS);
    }
}
