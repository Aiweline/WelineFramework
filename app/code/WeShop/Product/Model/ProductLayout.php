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

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class ProductLayout extends Model
{
    public const indexer = 'weshop_product_layout';
    public const fields_ID = 'layout_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_LAYOUT_TYPE = 'layout_type';
    public const fields_LAYOUT_CODE = 'layout_code';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CONFIG = 'config';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['product_id', 'layout_type'];
    public array $_index_sort_keys = ['product_id', 'layout_type'];

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

        $setup->createTable('产品布局表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                '布局ID'
            )
            ->addColumn(
                self::fields_PRODUCT_ID,
                TableInterface::column_type_INTEGER,
                11,
                'not null',
                '产品ID'
            )
            ->addColumn(
                self::fields_LAYOUT_TYPE,
                TableInterface::column_type_VARCHAR,
                64,
                'not null',
                '布局类型'
            )
            ->addColumn(
                self::fields_LAYOUT_CODE,
                TableInterface::column_type_VARCHAR,
                64,
                'not null',
                '布局代码'
            )
            ->addColumn(
                self::fields_IS_ACTIVE,
                TableInterface::column_type_INTEGER,
                1,
                'default 1',
                '是否启用'
            )
            ->addColumn(
                self::fields_CONFIG,
                TableInterface::column_type_TEXT,
                0,
                '',
                '布局配置（JSON）'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                '',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                '',
                '更新时间'
            )
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'idx_unique_product_layout',
                [self::fields_PRODUCT_ID, self::fields_LAYOUT_TYPE],
                '产品布局唯一索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_product_id',
                self::fields_PRODUCT_ID,
                '产品ID索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_layout_type',
                self::fields_LAYOUT_TYPE,
                '布局类型索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_is_active',
                self::fields_IS_ACTIVE,
                '启用状态索引'
            )
            ->create();
    }

    // ===== Getters and Setters =====

    public function getProductId(): int
    {
        return (int)$this->getData(self::fields_PRODUCT_ID);
    }

    public function setProductId(int $productId): static
    {
        return $this->setData(self::fields_PRODUCT_ID, $productId);
    }

    public function getLayoutType(): string
    {
        return (string)$this->getData(self::fields_LAYOUT_TYPE);
    }

    public function setLayoutType(string $layoutType): static
    {
        return $this->setData(self::fields_LAYOUT_TYPE, $layoutType);
    }

    public function getLayoutCode(): string
    {
        return (string)$this->getData(self::fields_LAYOUT_CODE);
    }

    public function setLayoutCode(string $layoutCode): static
    {
        return $this->setData(self::fields_LAYOUT_CODE, $layoutCode);
    }

    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::fields_IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function getConfig(): array
    {
        $config = $this->getData(self::fields_CONFIG);
        if (empty($config)) {
            return [];
        }
        return is_string($config) ? json_decode($config, true) : $config;
    }

    public function setConfig(array $config): static
    {
        return $this->setData(self::fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    public function getCreatedAt(): string
    {
        return (string)$this->getData(self::fields_CREATED_AT);
    }

    public function setCreatedAt(string $createdAt): static
    {
        return $this->setData(self::fields_CREATED_AT, $createdAt);
    }

    public function getUpdatedAt(): string
    {
        return (string)$this->getData(self::fields_UPDATED_AT);
    }

    public function setUpdatedAt(string $updatedAt): static
    {
        return $this->setData(self::fields_UPDATED_AT, $updatedAt);
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
            ->where(self::fields_PRODUCT_ID, $productId)
            ->where(self::fields_LAYOUT_TYPE, $layoutType)
            ->where(self::fields_IS_ACTIVE, 1)
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
            ->where(self::fields_PRODUCT_ID, $productId)
            ->where(self::fields_IS_ACTIVE, 1)
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

