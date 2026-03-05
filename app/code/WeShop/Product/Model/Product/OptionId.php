<?php

declare(strict_types=1);

namespace WeShop\Product\Model\Product;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '产品选项ID表')]
#[Index(name: 'PRODUCT_PARENT_PRODUCT_ID', columns: ['parent_product_id'], type: 'KEY', comment: '父产品ID索引')]
#[Index(name: 'PRODUCT_ATTRIBUTE_ID', columns: ['attribute_id'], type: 'KEY', comment: '属性ID索引')]
#[Index(name: 'PRODUCT_OPTION_ID', columns: ['option_id'], type: 'KEY', comment: '选项ID索引')]
class OptionId extends Model
{
    public const schema_table = 'weshop_product_option_id';
    public const schema_primary_key = 'attribute_id';
    public const indexer = 'product_option_id_indexer';
    public array $_unit_primary_keys = ['attribute_id', 'option_id', 'product_id'];
    public array $_index_sort_keys = ['parent_product_id', 'attribute_id', 'option_id', 'product_id'];

    #[Col(type: 'int', length: 11, nullable: true, default: 0, comment: '父产品ID')]
    public const schema_fields_PARENT_PRODUCT_ID = 'parent_product_id';
    #[Col(type: 'int', length: 11, nullable: false, primaryKey: true, comment: '属性ID')]
    public const schema_fields_ATTRIBUTE_ID = 'attribute_id';
    #[Col(type: 'int', length: 11, nullable: false, primaryKey: true, comment: '选项ID')]
    public const schema_fields_OPTION_ID = 'option_id';
    #[Col(type: 'int', length: 11, nullable: false, primaryKey: true, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';

    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}
    public function install(ModelSetup $setup, Context $context): void {}

    function getParentProductId(): int { return (int) $this->getData(self::schema_fields_PARENT_PRODUCT_ID); }
    function setParentProductId(int $parent_product_id): static { $this->setData(self::schema_fields_PARENT_PRODUCT_ID, $parent_product_id); return $this; }
    function getAttributeId(): int { return (int) $this->getData(self::schema_fields_ATTRIBUTE_ID); }
    function setAttributeId(int $attribute_id): static { $this->setData(self::schema_fields_ATTRIBUTE_ID, $attribute_id); return $this; }
    function getOptionId(): int { return (int) $this->getData(self::schema_fields_OPTION_ID); }
    function setOptionId(int $option_id): static { $this->setData(self::schema_fields_OPTION_ID, $option_id); return $this; }
    function getProductId(): int { return (int) $this->getData(self::schema_fields_PRODUCT_ID); }
    function setProductId(int $product_id): static { $this->setData(self::schema_fields_PRODUCT_ID, $product_id); return $this; }
}
