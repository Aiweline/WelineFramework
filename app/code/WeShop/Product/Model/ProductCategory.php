<?php

declare(strict_types=1);

namespace WeShop\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '产品分类表')]
#[Index(name: 'product_category_product_id_index', columns: ['product_id'], type: 'KEY', comment: '产品ID索引')]
#[Index(name: 'product_category_category_id_index', columns: ['category_id'], type: 'KEY', comment: '分类ID索引')]
class ProductCategory extends Model
{
    public const schema_table = "weshop_product_category";
    public const schema_primary_key = "product_category_id";
    public string $indexer = "product_category_indexer";
    public const schema_fields_ID = "product_category_id";
    public const schema_fields_product_id = "product_id";
    public const schema_fields_category_id = "category_id";

    public array $_unit_primary_keys = ["product_category_id"];
    public array $_index_sort_keys = ["product_id", "category_id"];

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

