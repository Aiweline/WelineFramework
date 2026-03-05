<?php

declare(strict_types=1);

namespace WeShop\Catalog\Model;

use Weline\Eav\EavModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 分类模型
 */
#[Table(comment: 'WeShop分类表')]
#[Index(name: 'idx_parent_id', columns: ['parent_id'], comment: '父分类ID索引')]
#[Index(name: 'idx_handle', columns: ['handle'], comment: '分类 Handle 索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '启用状态索引')]
class Category extends EavModel
{
    public const schema_table = 'weshop_category';
    public const schema_primary_key = 'category_id';
    public string $indexer = 'category_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '分类ID')]
    public const schema_fields_ID = 'category_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '父分类ID')]
    public const schema_fields_PARENT_ID = 'parent_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '分类名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '分类 Handle（简短标识，用于友好URL）')]
    public const schema_fields_HANDLE = 'handle';
    #[Col(type: 'text', nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '图片')]
    public const schema_fields_IMAGE = 'image';
    #[Col(type: 'int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    // EAV 实体配置
    public const entity_code = 'catalog_category';
    public const entity_name = '分类实体';
    public const eav_entity_id_field_type = TableInterface::column_type_INTEGER;
    public const eav_entity_id_field_length = 11;


    public array $_unit_primary_keys = ['category_id'];
    public array $_index_sort_keys = ['parent_id', 'sort_order', 'is_active'];

    /**
     * 获取父分类ID（兼容方法，用于兼容 Product 模块的接口）
     */
    public function getPid(): int
    {
        return (int)$this->getData(self::schema_fields_PARENT_ID);
    }

    /**
     * 设置父分类ID（兼容方法）
     */
    public function setPid(int $pid): static
    {
        return $this->setData(self::schema_fields_PARENT_ID, $pid);
    }

    /**
     * 获取父分类ID
     */
    public function getParentId(): int
    {
        return (int)$this->getData(self::schema_fields_PARENT_ID);
    }

    /**
     * 设置父分类ID
     */
    public function setParentId(int $parentId): static
    {
        return $this->setData(self::schema_fields_PARENT_ID, $parentId);
    }
}

