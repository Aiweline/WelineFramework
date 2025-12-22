<?php

namespace WeShop\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class ProductCategory extends Model
{
    public string $indexer = "product_category_indexer";
    public const fields_ID = "product_category_id";
    public const fields_product_id = "product_id";
    public const fields_category_id = "category_id";

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
        if ($setup->tableExist()) {
            return;
        }
        $setup->createTable('产品分类表')
            ->addColumn(
                self::fields_ID, 'int', 11,
                'primary key auto_increment', '产品分类ID')
            ->addColumn(
                self::fields_product_id, 'int', 11,
                'not null', '产品ID')
            ->addColumn(
                self::fields_category_id, 'int', 11,
                'not null', '分类ID')
            ->addIndex(
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY,
                'product_category_product_id_index',
                self::fields_product_id,
                '产品ID索引')
            ->addIndex(
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY,
                'product_category_category_id_index',
                self::fields_category_id,
                '分类ID索引')
            ->create();

    }

    public function joinProduct(): self
    {
        return $this->joinModel(Product::class,
            'product',
            'main_table.product_id=product.product_id'
        );
    }

    public function joinCategory(): self
    {
        return $this->joinModel(Category::class,
            'category',
            'main_table.category_id=category.category_id');
    }


    /**
     * 获取产品所属分类ID数组
     * @param int $product_id
     * @return array
     */
    public function getCategoryIdsByProductId(int $product_id): array
    {
        $rows = $this->where('product_id', $product_id)->select()->fetchArray();
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row['category_id'];
        }
        return $ids;
    }

    public function setCategoryIdsByProductId(int $product_id, array $category_ids): static
    {
        $this->where('product_id', $product_id)->delete()->fetch();
        foreach ($category_ids as $category_id) {
            $this->insert([
                'product_id' => $product_id,
                'category_id' => $category_id
            ])->fetch();
        }
        return $this;
    }

    /**
     * 设置产品分类
     * @param int $product_id
     * @param array $category_ids
     * @return $this
     */
    public function setProductCategories(int $product_id, array $category_ids): static
    {
        $this->where('product_id', $product_id)->delete()->fetch();
        foreach ($category_ids as $category_id) {
            $this->insert([
                'product_id' => $product_id,
                'category_id' => $category_id
            ])->fetch();
        }
        return $this;
    }
}