<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/2/21 23:13:32
 */
namespace WeShop\Product\Model;
use Weline\Eav\EavModel;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '产品表')]
#[Index(name: 'idx_short_description', columns: ['short_description'], type: 'FULLTEXT', comment: '简短描述索引')]
#[Index(name: 'idx_description', columns: ['description'], type: 'FULLTEXT', comment: '描述索引')]
#[Index(name: 'idx_sku', columns: ['sku'], type: 'FULLTEXT', comment: 'SKU索引')]
#[Index(name: 'idx_spu', columns: ['spu'], type: 'FULLTEXT', comment: 'SPU索引')]
#[Index(name: 'idx_price', columns: ['price'], type: 'DEFAULT', comment: '价格索引')]
#[Index(name: 'idx_name', columns: ['name'], type: 'FULLTEXT', comment: '产品名索引')]
#[Index(name: 'idx_parent_id', columns: ['parent_id'], type: 'KEY', comment: '父级ID索引')]
#[Index(name: 'idx_handle', columns: ['handle'], type: 'KEY', comment: '产品 Handle 索引')]
class Product extends EavModel
{
    public const schema_table = "weshop_product";
    public const schema_primary_key = "product_id";
    public string $indexer = "product_indexer";
    public array $_unit_primary_keys = ["product_id"];
    public array $_index_sort_keys = ["product_id", "name", "sku", "stock", "cost", "price", "set_id"];
    // 字段定义
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '产品ID')]
    public const schema_fields_ID = 'product_id';
    #[Col(type: 'varchar', length: 150, nullable: false, comment: '名称')]
    public const schema_fields_name = 'name';
    #[Col(type: 'text', nullable: false, comment: '简短描述')]
    public const schema_fields_short_description = 'short_description';
    #[Col(type: 'text', nullable: false, comment: '描述')]
    public const schema_fields_description = 'description';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'meta名称')]
    public const schema_fields_meta_name = 'meta_name';
    #[Col(type: 'text', nullable: false, comment: 'meta描述')]
    public const schema_fields_meta_description = 'meta_description';
    #[Col(type: 'text', nullable: false, comment: 'meta关键词')]
    public const schema_fields_meta_keywords = 'meta_keywords';
    #[Col(type: 'varchar', length: 60, nullable: false, comment: 'SPU')]
    public const schema_fields_spu = 'spu';
    #[Col(type: 'varchar', length: 60, nullable: false, comment: '最小存货单位（SKU）')]
    public const schema_fields_sku = 'sku';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '产品 Handle（简短标识，用于友好URL）')]
    public const schema_fields_HANDLE = 'handle';
    #[Col(type: 'int', nullable: true, default: 99, comment: '库存')]
    public const schema_fields_stock = 'stock';
    #[Col(type: 'float', nullable: false, comment: '成本')]
    public const schema_fields_cost = 'cost';
    #[Col(type: 'float', nullable: false, comment: '价格')]
    public const schema_fields_price = 'price';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '图片')]
    public const schema_fields_image = 'image';
    #[Col(type: 'text', nullable: false, comment: '子图')]
    public const schema_fields_images = 'images';
    #[Col(type: 'int', nullable: true, default: 0, comment: '父级ID')]
    public const schema_fields_parent_id = 'parent_id';
    #[Col(type: 'int', nullable: false, comment: '状态')]
    public const schema_fields_status = 'status';
    #[Col(type: 'decimal', length: '4,0', nullable: false, comment: '重量')]
    public const schema_fields_weight = 'weight';
    #[Col(type: 'int', nullable: false, comment: '属性集ID')]
    public const schema_fields_set_id = 'set_id';
    public array $_validate_fields = [self::schema_fields_set_id, self::schema_fields_name, self::schema_fields_sku, self::schema_fields_stock, self::schema_fields_cost, self::schema_fields_price, self::schema_fields_set_id];
    public const entity_code = 'product';
    public const entity_name = '产品实体';
    public const eav_entity_id_field_type = TableInterface::column_type_INTEGER;
    public const eav_entity_id_field_length = 11;

    public function getName(): string
    {
        return $this->getData(self::schema_fields_name);
    }
    public function setName(string $name): static
    {
        $this->setData(self::schema_fields_name, $name);
        return $this;
    }
    public function getMetaKeywords(): string
    {
        return $this->getData(self::schema_fields_meta_keywords);
    }
    public function setMetaKeywords(string $meta_keywords): static
    {
        $this->setData(self::schema_fields_meta_keywords, $meta_keywords);
        return $this;
    }
    public function getParentId(): int
    {
        return $this->getData(self::schema_fields_parent_id);
    }
    public function setParentId(int $parent_id): static
    {
        $this->setData(self::schema_fields_parent_id, $parent_id);
        return $this;
    }
    public function getSku(): string
    {
        return $this->getData(self::schema_fields_sku);
    }
    public function setSku(string $sku): static
    {
        $this->setData(self::schema_fields_sku, $sku);
        return $this;
    }
    public function getStock(): int
    {
        return $this->getData(self::schema_fields_stock);
    }
    public function setStock(int $stock): static
    {
        $this->setData(self::schema_fields_stock, $stock);
        return $this;
    }
    public function getCost(): float
    {
        return (float)$this->getData(self::schema_fields_cost);
    }
    public function setCost(float $cost): static
    {
        $this->setData(self::schema_fields_cost, $cost);
        return $this;
    }
    public function getPrice(): float
    {
        return (float)$this->getData(self::schema_fields_price);
    }
    public function setPrice(float $price): static
    {
        $this->setData(self::schema_fields_price, $price);
        return $this;
    }
    public function getShortDescription(): string
    {
        return $this->getData(self::schema_fields_short_description);
    }
    public function setShortDescription(string $short_description): static
    {
        $this->setData(self::schema_fields_short_description, $short_description);
        return $this;
    }
    public function getDescription(): string
    {
        return $this->getData(self::schema_fields_description);
    }
    public function setDescription(string $description): static
    {
        $this->setData(self::schema_fields_description, $description);
        return $this;
    }
    public function getImage(): string
    {
        return $this->getData(self::schema_fields_image);
    }
    public function setImage(string $image): static
    {
        $this->setData(self::schema_fields_image, $image);
        return $this;
    }
    public function getImages(): string
    {
        return $this->getData(self::schema_fields_images);
    }
    public function setImages(string $images): static
    {
        $this->setData(self::schema_fields_images, $images);
        return $this;
    }
    public function getMetaName(): string
    {
        return $this->getData(self::schema_fields_meta_name);
    }
    public function setMetaName(string $meta_title): static
    {
        $this->setData(self::schema_fields_meta_name, $meta_title);
        return $this;
    }
    public function getMetaDescription(): string
    {
        return $this->getData(self::schema_fields_meta_description);
    }
    public function setMetaDescription(string $meta_description): static
    {
        $this->setData(self::schema_fields_meta_description, $meta_description);
        return $this;
    }
    public function getStatus(): int
    {
        return (int)$this->getData(self::schema_fields_status);
    }
    public function setStatus(int $status): static
    {
        $this->setData(self::schema_fields_status, $status);
        return $this;
    }
    public function getWeight(): float
    {
        return (float)$this->getData(self::schema_fields_weight);
    }
    public function setWeight(float $weight): static
    {
        $this->setData(self::schema_fields_weight, $weight);
        return $this;
    }
    public function productCategories(): array
    {
        return ObjectManager::getInstance(ProductCategory::class)
            ->where('product_id', $this->getId())->select()->fetchArray();
    }
    public function getCategoriesWithLocale(): array
    {
        /** @var ProductCategory $productCategory */
        $productCategory = ObjectManager::getInstance(ProductCategory::class);
        return $productCategory->joinCategory()
            ->where('product_id', $this->getId())
            ->joinModel(Category\LocalDescription::class, 'category_local', 'category.category_id=category_local.category_id')
            ->select()->fetchArray();
    }
    /**
     * 产品保存前钩子 - 触发事件
     */
    public function save_before(): void
    {
        parent::save_before();
        // 触发产品保存前事件
        $eventData = [
            'product' => $this,
            'is_new' => empty($this->getId())
        ];
        $this->getEventManager()->dispatch('WeShop_Product::product_save_before', $eventData);
    }
    /**
     * 产品保存后钩子 - 触发事件
     */
    public function save_after(): void
    {
        parent::save_after();
        // 触发产品保存后事件
        $eventData = [
            'product' => $this,
            'product_id' => $this->getId()
        ];
        $this->getEventManager()->dispatch('WeShop_Product::product_save_after', $eventData);
    }
    /**
     * 产品删除前钩子 - 触发事件
     */
    public function delete_before(): void
    {
        parent::delete_before();
        // 触发产品删除前事件
        $this->getEventManager()->dispatch('WeShop_Product::product_delete_before', [
            'product' => $this,
            'product_id' => $this->getId()
        ]);
    }
    /**
     * 产品删除后钩子 - 触发事件
     */
    public function delete_after(): void
    {
        parent::delete_after();
        // 触发产品删除后事件
        $this->getEventManager()->dispatch('WeShop_Product::product_delete_after', [
            'product_id' => $this->getOriginData(self::schema_fields_ID)
        ]);
    }
    /**
     * 获取事件管理器
     * @return \Weline\Framework\Event\EventsManager
     */
    public function getEventManager(): \Weline\Framework\Event\EventsManager
    {
        return ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
    }
    /**
     * 获取属性集ID
     * @return int
     */
    public function getSetId(): int
    {
        return (int)$this->getData(self::schema_fields_set_id);
    }
    /**
     * 设置属性集ID
     * @param int $setId
     * @return static
     */
    public function setSetId(int $setId): static
    {
        return $this->setData(self::schema_fields_set_id, $setId);
    }
    /**
     * 获取产品当前使用的属性集（通过 set_id）
     * @return Set|null
     */
    public function getCurrentAttributeSet(): ?Set
    {
        $setId = $this->getSetId();
        if ($setId <= 0) {
            return null;
        }
        /** @var Set $set */
        $set = ObjectManager::getInstance(Set::class);
        $set->load($setId);
        return $set->getId() ? $set : null;
    }
    /**
     * 获取SPU
     * @return string
     */
    public function getSpu(): string
    {
        return (string)$this->getData(self::schema_fields_spu);
    }
    /**
     * 设置SPU
     * @param string $spu
     * @return static
     */
    public function setSpu(string $spu): static
    {
        return $this->setData(self::schema_fields_spu, $spu);
    }
}
