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
 * EAV属性选项表结构定义
 * 
 * 对应表: m_eav_attribute_option
 * 关联Model: Weline\Eav\Model\EavAttribute\Option
 * 
 * 注意: eav_entity_id 已修复为 INTEGER 类型（原来错误地定义为 VARCHAR）
 */
class EavAttributeOptionSchema extends AbstractSchema
{
    public const TABLE_NAME = 'eav_attribute_option';
    
    // 字段常量
    public const FIELD_ID = 'option_id';
    public const FIELD_CODE = 'code';
    public const FIELD_VALUE = 'value';
    public const FIELD_ATTRIBUTE_ID = 'attribute_id';
    public const FIELD_EAV_ENTITY_ID = 'eav_entity_id';
    public const FIELD_SWATCH_IMAGE = 'swatch_image';
    public const FIELD_SWATCH_COLOR = 'swatch_color';
    public const FIELD_SWATCH_TEXT = 'swatch_text';

    public function getTableName(): string
    {
        return self::TABLE_NAME;
    }

    public function getTableComment(): string
    {
        return '属性选项表';
    }

    public function getColumns(): array
    {
        return [
            self::FIELD_ID => $this->primaryKey('选项ID'),
            self::FIELD_CODE => $this->varchar('选项代码', 255, 'not null'),
            self::FIELD_VALUE => $this->varchar('选项值', 255, 'not null'),
            self::FIELD_ATTRIBUTE_ID => $this->integer('属性ID', 'not null'),
            // 修复: eav_entity_id 改为 INTEGER 类型，与 EavEntity.eav_entity_id 主键类型一致
            self::FIELD_EAV_ENTITY_ID => $this->integer('相关实体ID', 'not null'),
            self::FIELD_SWATCH_IMAGE => $this->text('图片'),
            self::FIELD_SWATCH_COLOR => $this->varchar('颜色', 60, ''),
            self::FIELD_SWATCH_TEXT => $this->varchar('文本', 128, ''),
        ];
    }

    public function getIndexes(): array
    {
        return [
            'idx_attribute_id' => $this->index(self::FIELD_ATTRIBUTE_ID, '属性索引'),
            'idx_eav_entity_id' => $this->index(self::FIELD_EAV_ENTITY_ID, '实体索引'),
            'idx_code' => $this->index(self::FIELD_CODE, '选项代码索引'),
        ];
    }

    public function getDependencies(): array
    {
        return [
            EavEntitySchema::class,
            EavAttributeSchema::class,
        ];
    }

    public function getUniqueKey(): string|array
    {
        return [self::FIELD_ATTRIBUTE_ID, self::FIELD_CODE];
    }
}
