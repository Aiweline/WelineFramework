<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Eav\Schema;

/**
 * EAV属性表结构定义
 * 
 * 对应表: m_eav_attribute
 * 关联Model: Weline\Eav\Model\EavAttribute
 */
class EavAttributeSchema extends AbstractSchema
{
    public const TABLE_NAME = 'eav_attribute';
    
    // 字段常量
    public const FIELD_ID = 'attribute_id';
    public const FIELD_CODE = 'code';
    public const FIELD_NAME = 'name';
    public const FIELD_TYPE_ID = 'type_id';
    public const FIELD_SET_ID = 'set_id';
    public const FIELD_GROUP_ID = 'group_id';
    public const FIELD_EAV_ENTITY_ID = 'eav_entity_id';
    public const FIELD_IS_SYSTEM = 'is_system';
    public const FIELD_MODEL_CLASS = 'model_class';
    public const FIELD_DEFAULT_VALUE = 'default_value';
    public const FIELD_DEPENDENCE = 'dependence';
    
    // 基本设置组 (basic_)
    public const FIELD_BASIC_IS_ENABLE = 'basic_is_enable';
    
    // 前端显示组 (frontend_)
    public const FIELD_FRONTEND_IS_VISIBLE = 'frontend_is_visible';
    public const FIELD_FRONTEND_IS_FILTERABLE = 'frontend_is_filterable';
    
    // 数据配置组 (data_)
    public const FIELD_DATA_IS_MULTIPLE = 'data_is_multiple';
    public const FIELD_DATA_HAS_OPTION = 'data_has_option';
    
    // 兼容旧字段名（已废弃，建议使用新常量）
    /** @deprecated use FIELD_DATA_IS_MULTIPLE */
    public const FIELD_MULTIPLE_VALUED = 'data_is_multiple';
    /** @deprecated use FIELD_DATA_HAS_OPTION */
    public const FIELD_HAS_OPTION = 'data_has_option';
    /** @deprecated use FIELD_BASIC_IS_ENABLE */
    public const FIELD_IS_ENABLE = 'basic_is_enable';
    /** @deprecated use FIELD_FRONTEND_IS_FILTERABLE */
    public const FIELD_IS_FILTERABLE = 'frontend_is_filterable';
    /** @deprecated use FIELD_FRONTEND_IS_VISIBLE */
    public const FIELD_IS_VISIBLE_ON_FRONT = 'frontend_is_visible';

    public function getTableName(): string
    {
        return self::TABLE_NAME;
    }

    public function getTableComment(): string
    {
        return '属性表';
    }

    public function getColumns(): array
    {
        return [
            self::FIELD_ID => $this->primaryKey('属性ID'),
            self::FIELD_CODE => $this->varchar('属性代码', 255, 'not null'),
            self::FIELD_EAV_ENTITY_ID => $this->integer('所属EAV实体ID', 'not null'),
            self::FIELD_SET_ID => $this->integer('所属属性集ID', 'not null'),
            self::FIELD_GROUP_ID => $this->integer('所属属性组ID', 'not null'),
            self::FIELD_NAME => $this->varchar('属性名称', 255, 'not null'),
            self::FIELD_TYPE_ID => $this->integer('属性类型ID', 'not null'),
            self::FIELD_IS_SYSTEM => $this->boolean('是否系统生成', false),
            self::FIELD_MODEL_CLASS => $this->varchar('模型类', 255, "default ''"),
            self::FIELD_DEFAULT_VALUE => $this->text('默认值'),
            self::FIELD_DEPENDENCE => $this->varchar('依赖属性', 128, 'default null'),
            // 基本设置组 (basic_)
            self::FIELD_BASIC_IS_ENABLE => $this->boolean('是否启用', true),
            // 前端显示组 (frontend_)
            self::FIELD_FRONTEND_IS_VISIBLE => $this->boolean('是否在前端可见', false),
            self::FIELD_FRONTEND_IS_FILTERABLE => $this->boolean('是否可用于筛选', false),
            // 数据配置组 (data_)
            self::FIELD_DATA_IS_MULTIPLE => $this->boolean('是否多值', false),
            self::FIELD_DATA_HAS_OPTION => $this->boolean('是否有选项', false),
        ];
    }

    public function getIndexes(): array
    {
        return [
            'idx_unique_entity_and_code' => $this->uniqueIndex(
                [self::FIELD_CODE, self::FIELD_EAV_ENTITY_ID],
                '实体和属性code唯一索引'
            ),
            'idx_eav_entity_id' => $this->index(self::FIELD_EAV_ENTITY_ID, '实体索引'),
            'idx_set_id' => $this->index(self::FIELD_SET_ID, '属性集索引'),
            'idx_group_id' => $this->index(self::FIELD_GROUP_ID, '属性组索引'),
            'idx_name' => $this->index(self::FIELD_NAME, '属性名索引'),
            'idx_frontend_filterable' => $this->index(self::FIELD_FRONTEND_IS_FILTERABLE, '可筛选索引'),
        ];
    }

    public function getDependencies(): array
    {
        return [
            EavEntitySchema::class,
            EavAttributeTypeSchema::class,
            EavAttributeSetSchema::class,
            EavAttributeGroupSchema::class,
        ];
    }

    public function getUniqueKey(): string|array
    {
        return [self::FIELD_CODE, self::FIELD_EAV_ENTITY_ID];
    }
}
