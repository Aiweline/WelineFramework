<?php

declare(strict_types=1);

namespace WeShop\Product\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 产品-站点关联模型
 * 
 * 支持产品分配到多个站点，每个站点独立配置 handle（SEO URL）
 * 
 * 表结构：
 * - product_website_id: 主键
 * - product_id: 产品ID（外键关联 product 表）
 * - website_id: 站点ID（0 表示默认/全局）
 * - handle: 该站点下的 SEO 友好 URL 标识
 * - is_active: 是否启用
 * - sort_order: 排序（同一产品在多个站点的展示优先级）
 * - meta_title: 站点特定的 meta 标题（可选覆盖）
 * - meta_description: 站点特定的 meta 描述（可选覆盖）
 * - create_time: 创建时间
 * - update_time: 更新时间
 */
class ProductWebsite extends Model
{
    public const table = 'weshop_product_website';
    public const primary_key = 'product_website_id';
    public string $indexer = 'product_website_indexer';
    public array $_unit_primary_keys = ['product_website_id'];
    public array $_index_sort_keys = ['product_id', 'website_id', 'handle', 'is_active', 'sort_order'];
    
    public const fields_ID = 'product_website_id';
    public const fields_PRODUCT_ID = 'product_id';
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_HANDLE = 'handle';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_META_TITLE = 'meta_title';
    public const fields_META_DESCRIPTION = 'meta_description';

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
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
            return;
        }

        // 添加 meta_title 字段（如果不存在）
        if (!$setup->hasField(self::fields_META_TITLE)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_META_TITLE,
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'DEFAULT NULL',
                    '站点特定的 Meta 标题'
                )
                ->alter();
        }

        // 添加 meta_description 字段（如果不存在）
        if (!$setup->hasField(self::fields_META_DESCRIPTION)) {
            $setup->alterTable()
                ->addColumn(
                    self::fields_META_DESCRIPTION,
                    self::fields_META_TITLE,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '站点特定的 Meta 描述'
                )
                ->alter();
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('产品-站点关联表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key AUTO_INCREMENT',
                '关联ID'
            )
            ->addColumn(
                self::fields_PRODUCT_ID,
                TableInterface::column_type_INTEGER,
                11,
                'NOT NULL',
                '产品ID'
            )
            ->addColumn(
                self::fields_WEBSITE_ID,
                TableInterface::column_type_INTEGER,
                11,
                'NOT NULL DEFAULT 0',
                '站点ID（0表示默认/全局）'
            )
            ->addColumn(
                self::fields_HANDLE,
                TableInterface::column_type_VARCHAR,
                255,
                'NOT NULL',
                'SEO 友好 URL 标识'
            )
            ->addColumn(
                self::fields_IS_ACTIVE,
                TableInterface::column_type_SMALLINT,
                1,
                'NOT NULL DEFAULT 1',
                '是否启用'
            )
            ->addColumn(
                self::fields_SORT_ORDER,
                TableInterface::column_type_INTEGER,
                11,
                'NOT NULL DEFAULT 0',
                '排序'
            )
            ->addColumn(
                self::fields_META_TITLE,
                TableInterface::column_type_VARCHAR,
                255,
                'DEFAULT NULL',
                '站点特定的 Meta 标题'
            )
            ->addColumn(
                self::fields_META_DESCRIPTION,
                TableInterface::column_type_TEXT,
                null,
                '',
                '站点特定的 Meta 描述'
            )
            ->addColumn(
                self::fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                null,
                'NOT NULL DEFAULT CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATE_TIME,
                TableInterface::column_type_DATETIME,
                null,
                'NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                '更新时间'
            )
            // 唯一索引：同一产品在同一站点只能有一条记录
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'UNQ_PRODUCT_WEBSITE',
                [self::fields_PRODUCT_ID, self::fields_WEBSITE_ID],
                '产品-站点唯一'
            )
            // 唯一索引：同一站点下的 handle 必须唯一
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'UNQ_WEBSITE_HANDLE',
                [self::fields_WEBSITE_ID, self::fields_HANDLE],
                '站点-Handle唯一'
            )
            // 产品索引
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_product_id',
                self::fields_PRODUCT_ID,
                '产品ID索引'
            )
            // 站点索引
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_website_id',
                self::fields_WEBSITE_ID,
                '站点ID索引'
            )
            // Handle 索引（用于快速查找）
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_handle',
                self::fields_HANDLE,
                'Handle索引'
            )
            ->create();
    }

    // ==================== Getters & Setters ====================

    public function getProductWebsiteId(): int
    {
        return (int)$this->getData(self::fields_ID);
    }

    public function getProductId(): int
    {
        return (int)$this->getData(self::fields_PRODUCT_ID);
    }

    public function setProductId(int $productId): self
    {
        return $this->setData(self::fields_PRODUCT_ID, $productId);
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::fields_WEBSITE_ID);
    }

    public function setWebsiteId(int $websiteId): self
    {
        return $this->setData(self::fields_WEBSITE_ID, $websiteId);
    }

    public function getHandle(): string
    {
        return (string)$this->getData(self::fields_HANDLE);
    }

    public function setHandle(string $handle): self
    {
        return $this->setData(self::fields_HANDLE, $handle);
    }

    public function getIsActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    public function setIsActive(bool $isActive): self
    {
        return $this->setData(self::fields_IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function getSortOrder(): int
    {
        return (int)$this->getData(self::fields_SORT_ORDER);
    }

    public function setSortOrder(int $sortOrder): self
    {
        return $this->setData(self::fields_SORT_ORDER, $sortOrder);
    }

    public function getMetaTitle(): ?string
    {
        return $this->getData(self::fields_META_TITLE);
    }

    public function setMetaTitle(?string $metaTitle): self
    {
        return $this->setData(self::fields_META_TITLE, $metaTitle);
    }

    public function getMetaDescription(): ?string
    {
        return $this->getData(self::fields_META_DESCRIPTION);
    }

    public function setMetaDescription(?string $metaDescription): self
    {
        return $this->setData(self::fields_META_DESCRIPTION, $metaDescription);
    }

    // ==================== 业务方法 ====================

    /**
     * 根据站点ID和Handle获取产品ID
     *
     * @param int $websiteId 站点ID
     * @param string $handle Handle
     * @return int|null 产品ID，如果不存在返回 null
     */
    public function getProductIdByWebsiteAndHandle(int $websiteId, string $handle): ?int
    {
        $this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_HANDLE, $handle)
            ->where(self::fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        return $this->getProductId() ?: null;
    }

    /**
     * 获取产品在指定站点的配置
     *
     * @param int $productId 产品ID
     * @param int $websiteId 站点ID
     * @return self|null
     */
    public function getByProductAndWebsite(int $productId, int $websiteId): ?self
    {
        $this->reset()
            ->where(self::fields_PRODUCT_ID, $productId)
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();

        return $this->getProductWebsiteId() ? $this : null;
    }

    /**
     * 获取产品的所有站点配置
     *
     * @param int $productId 产品ID
     * @return array
     */
    public function getWebsitesByProduct(int $productId): array
    {
        $result = $this->reset()
            ->where(self::fields_PRODUCT_ID, $productId)
            ->order(self::fields_SORT_ORDER)
            ->select()
            ->fetch();

        return is_array($result) ? $result : [];
    }

    /**
     * 检查 Handle 在指定站点是否可用
     *
     * @param int $websiteId 站点ID
     * @param string $handle Handle
     * @param int|null $excludeProductId 排除的产品ID（用于更新时排除自身）
     * @return bool
     */
    public function isHandleAvailable(int $websiteId, string $handle, ?int $excludeProductId = null): bool
    {
        $query = $this->reset()
            ->where(self::fields_WEBSITE_ID, $websiteId)
            ->where(self::fields_HANDLE, $handle);

        if ($excludeProductId) {
            $query->where(self::fields_PRODUCT_ID, ['<>', $excludeProductId]);
        }

        $query->find()->fetch();

        return !$this->getProductWebsiteId();
    }

    /**
     * 保存产品-站点关联（如果存在则更新，不存在则创建）
     *
     * @param int $productId 产品ID
     * @param int $websiteId 站点ID
     * @param string $handle Handle
     * @param array $additionalData 额外数据
     * @return self
     */
    public function saveProductWebsite(int $productId, int $websiteId, string $handle, array $additionalData = []): self
    {
        // 检查是否已存在
        $existing = $this->getByProductAndWebsite($productId, $websiteId);

        if ($existing) {
            // 更新
            $this->reset()->load($existing->getProductWebsiteId());
        } else {
            // 新建
            $this->reset()
                ->setProductId($productId)
                ->setWebsiteId($websiteId);
        }

        $this->setHandle($handle);

        // 设置额外数据
        if (isset($additionalData['is_active'])) {
            $this->setIsActive((bool)$additionalData['is_active']);
        }
        if (isset($additionalData['sort_order'])) {
            $this->setSortOrder((int)$additionalData['sort_order']);
        }
        if (array_key_exists('meta_title', $additionalData)) {
            $this->setMetaTitle($additionalData['meta_title']);
        }
        if (array_key_exists('meta_description', $additionalData)) {
            $this->setMetaDescription($additionalData['meta_description']);
        }

        $this->save();

        return $this;
    }

    /**
     * 删除产品在指定站点的关联
     *
     * @param int $productId 产品ID
     * @param int $websiteId 站点ID
     * @return bool
     */
    public function deleteProductWebsite(int $productId, int $websiteId): bool
    {
        $existing = $this->getByProductAndWebsite($productId, $websiteId);

        if ($existing) {
            $this->reset()->load($existing->getProductWebsiteId());
            $this->delete();
            return true;
        }

        return false;
    }

    /**
     * 删除产品的所有站点关联
     *
     * @param int $productId 产品ID
     * @return int 删除的记录数
     */
    public function deleteAllByProduct(int $productId): int
    {
        $websites = $this->getWebsitesByProduct($productId);
        $count = 0;

        foreach ($websites as $website) {
            $this->reset()->load($website[self::fields_ID] ?? 0);
            if ($this->getProductWebsiteId()) {
                $this->delete();
                $count++;
            }
        }

        return $count;
    }
}
