<?php

namespace WeShop\Product\Model\Product;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class OptionId extends Model
{
    public const fields_ID               = 'product_id';
    public const fields_ATTRIBUTE_ID     = 'attribute_id';
    public const fields_PRODUCT_ID       = 'product_id';
    public const fields_PRENT_PRODUCT_ID = 'parent_product_id';
    public const fields_OPTION_ID        = 'option_id';

    public array $_index_sort_keys = ['parent_product_id', 'attribute_id', 'option_id', 'product_id'];

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(
                    self::fields_PRENT_PRODUCT_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'default 0',
                    '父产品ID',
                )
                ->addColumn(
                    self::fields_ATTRIBUTE_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '选项ID',
                )
                ->addColumn(
                    self::fields_OPTION_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '选项ID',
                )
                ->addColumn(
                    self::fields_PRODUCT_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '产品ID',
                )
                ->addIndex(TableInterface::index_type_KEY, 'PRODUCT_PARENT_PRODUCT_ID', self::fields_OPTION_ID, '父产品ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'PRODUCT_ATTRIBUTE_ID', self::fields_ATTRIBUTE_ID, '属性ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'PRODUCT_OPTION_ID', self::fields_OPTION_ID, '选项ID索引')
                ->addConstraints('primary key (' . self::fields_ATTRIBUTE_ID . ',' . self::fields_OPTION_ID . ',' . self::fields_PRODUCT_ID . ')')
                ->addAdditional('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')
                ->create();
        }
    }

    function getParentProductId(): int
    {
        return $this->getData(self::fields_PRENT_PRODUCT_ID);
    }

    function setParentProductId(int $parent_product_id): static
    {
        $this->setData(self::fields_PRENT_PRODUCT_ID, $parent_product_id);
        return $this;
    }

    function getAttributeId(): int
    {
        return $this->getData(self::fields_ATTRIBUTE_ID);
    }

    function setAttributeId(int $attribute_id): static
    {
        $this->setData(self::fields_ATTRIBUTE_ID, $attribute_id);
        return $this;
    }

    function getOptionId(): int
    {
        return $this->getData(self::fields_OPTION_ID);
    }

    function setOptionId(int $option_id): static
    {
        $this->setData(self::fields_OPTION_ID, $option_id);
        return $this;
    }

    function getProductId(): int
    {
        return $this->getData(self::fields_PRODUCT_ID);
    }

    function setProductId(int $product_id): static
    {
        $this->setData(self::fields_PRODUCT_ID, $product_id);
        return $this;
    }
}