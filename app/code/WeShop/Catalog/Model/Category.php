<?php

declare(strict_types=1);

namespace WeShop\Catalog\Model;

use Weline\Eav\EavModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 分类模型
 */
class Category extends EavModel
{
    public const table = 'weshop_category';
    public const primary_key = 'category_id';
    
    public const fields_ID = 'category_id';
    public const fields_PARENT_ID = 'parent_id';
    public const fields_NAME = 'name';
    public const fields_URL_KEY = 'url_key';
    public const fields_DESCRIPTION = 'description';
    public const fields_IMAGE = 'image';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // EAV 实体配置
    public const entity_code = 'catalog_category';
    public const entity_name = '分类实体';
    public const eav_entity_id_field_type = TableInterface::column_type_INTEGER;
    public const eav_entity_id_field_length = 11;
    
    public array $_unit_primary_keys = ['category_id'];
    public array $_index_sort_keys = ['parent_id', 'sort_order', 'is_active'];
    
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
        // 如果表不存在，执行安装
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('WeShop分类表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '分类ID')
                ->addColumn(self::fields_PARENT_ID, TableInterface::column_type_INTEGER, 0, 'not null default 0', '父分类ID')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '分类名称')
                ->addColumn(self::fields_URL_KEY, TableInterface::column_type_VARCHAR, 255, '', 'URL标识')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, 0, '', '描述')
                ->addColumn(self::fields_IMAGE, TableInterface::column_type_VARCHAR, 255, '', '图片')
                ->addColumn(self::fields_SORT_ORDER, TableInterface::column_type_INTEGER, 0, 'not null default 0', '排序')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'not null default 1', '是否启用')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_parent_id', self::fields_PARENT_ID, '父分类ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_url_key', self::fields_URL_KEY, 'URL标识索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '启用状态索引')
                ->create();
        }
    }
    
    /**
     * 获取父分类ID（兼容方法，用于兼容 Product 模块的接口）
     * @return int
     */
    public function getPid(): int
    {
        return (int)$this->getData(self::fields_PARENT_ID);
    }
    
    /**
     * 设置父分类ID（兼容方法）
     * @param int $pid
     * @return static
     */
    public function setPid(int $pid): static
    {
        return $this->setData(self::fields_PARENT_ID, $pid);
    }
    
    /**
     * 获取父分类ID
     * @return int
     */
    public function getParentId(): int
    {
        return (int)$this->getData(self::fields_PARENT_ID);
    }
    
    /**
     * 设置父分类ID
     * @param int $parentId
     * @return static
     */
    public function setParentId(int $parentId): static
    {
        return $this->setData(self::fields_PARENT_ID, $parentId);
    }
}
