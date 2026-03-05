<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/12/20
 * 描述：产品布局模型 - 存储产品与布局的关联关系
 */
namespace WeShop\Product\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '产品布局表')]
#[Index(name: 'idx_unique_product_layout', columns: ['product_id', 'layout_type'], type: 'UNIQUE', comment: '产品布局唯一索引')]
#[Index(name: 'idx_product_id', columns: ['product_id'], type: 'KEY', comment: '产品ID索引')]
#[Index(name: 'idx_layout_type', columns: ['layout_type'], type: 'KEY', comment: '布局类型索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], type: 'KEY', comment: '启用状态索引')]
class ProductLayout extends Model
{
    public const schema_table = 'weshop_product_layout';
    public const schema_primary_key = 'layout_id';
    public const indexer = 'weshop_product_layout';
    public array $_unit_primary_keys = ['layout_id'];
    public array $_index_sort_keys = ['product_id', 'layout_type', 'is_active'];
    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '布局ID')]
    public const schema_fields_ID = 'layout_id';
    #[Col('int', 11, nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col('varchar', 64, nullable: false, comment: '布局类型')]
    public const schema_fields_LAYOUT_TYPE = 'layout_type';
    #[Col('varchar', 64, nullable: false, comment: '布局代码')]
    public const schema_fields_LAYOUT_CODE = 'layout_code';
    #[Col('int', 1, nullable: true, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('text', 0, nullable: true, comment: '布局配置（JSON）')]
    public const schema_fields_CONFIG = 'config';
    #[Col('datetime', 0, nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', 0, nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    // ===== Getters and Setters =====
    public function getProductId(): int
    {
        return (int)$this->getData(self::schema_fields_PRODUCT_ID);
    }
    public function setProductId(int $productId): static
    {
        return $this->setData(self::schema_fields_PRODUCT_ID, $productId);
    }
    public function getLayoutType(): string
    {
        return (string)$this->getData(self::schema_fields_LAYOUT_TYPE);
    }
    public function setLayoutType(string $layoutType): static
    {
        return $this->setData(self::schema_fields_LAYOUT_TYPE, $layoutType);
    }
    public function getLayoutCode(): string
    {
        return (string)$this->getData(self::schema_fields_LAYOUT_CODE);
    }
    public function setLayoutCode(string $layoutCode): static
    {
        return $this->setData(self::schema_fields_LAYOUT_CODE, $layoutCode);
    }
    public function isActive(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_ACTIVE);
    }
    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
    }
    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
        if (empty($config)) {
            return [];
        }
        return is_string($config) ? json_decode($config, true) : $config;
    }
    public function setConfig(array $config): static
    {
        return $this->setData(self::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    public function getCreatedAt(): string
    {
        return (string)$this->getData(self::schema_fields_CREATED_AT);
    }
    public function setCreatedAt(string $createdAt): static
    {
        return $this->setData(self::schema_fields_CREATED_AT, $createdAt);
    }
    public function getUpdatedAt(): string
    {
        return (string)$this->getData(self::schema_fields_UPDATED_AT);
    }
    public function setUpdatedAt(string $updatedAt): static
    {
        return $this->setData(self::schema_fields_UPDATED_AT, $updatedAt);
    }
    /**
     * 保存前钩子 - 设置时间戳
     */
    public function save_before(): void
    {
        parent::save_before();
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setCreatedAt($now);
        }
        $this->setUpdatedAt($now);
    }
    /**
     * 根据产品ID和布局类型获取布局
     */
    public function getByProductAndType(int $productId, string $layoutType): ?static
    {
        $layout = $this->reset()
            ->where(self::schema_fields_PRODUCT_ID, $productId)
            ->where(self::schema_fields_LAYOUT_TYPE, $layoutType)
            ->where(self::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        return $layout->getId() ? $layout : null;
    }
    /**
     * 获取产品的所有布局
     */
    public function getByProduct(int $productId): array
    {
        return $this->reset()
            ->where(self::schema_fields_PRODUCT_ID, $productId)
            ->where(self::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();
    }
    /**
     * 获取关联的产品模型
     */
    public function getProduct(): ?Product
    {
        $productId = $this->getProductId();
        if ($productId <= 0) {
            return null;
        }
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);
        return $product->getId() ? $product : null;
    }
}
