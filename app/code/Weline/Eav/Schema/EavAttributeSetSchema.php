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
 * EAV属性集表结构定义
 * 
 * 对应表: m_eav_attribute_set
 * 关联Model: Weline\Eav\Model\EavAttribute\Set
 */
class EavAttributeSetSchema extends AbstractSchema
{
    public const TABLE_NAME = 'eav_attribute_set';
    
    // 字段常量
    public const FIELD_ID = 'set_id';
    public const FIELD_CODE = 'code';
    public const FIELD_NAME = 'name';
    public const FIELD_EAV_ENTITY_ID = 'eav_entity_id';

    public function getTableName(): string
    {
        return self::TABLE_NAME;
    }

    public function getTableComment(): string
    {
        return '属性集表';
    }

    public function getColumns(): array
    {
        return [
            self::FIELD_ID => $this->primaryKey('属性集ID'),
            self::FIELD_EAV_ENTITY_ID => $this->integer('实体ID', 'not null'),
            self::FIELD_CODE => $this->varchar('属性集代码', 255, 'not null'),
            self::FIELD_NAME => $this->varchar('属性集名', 255, 'not null'),
        ];
    }

    public function getIndexes(): array
    {
        return [
            'idx_unique_code_and_eav_entity_id' => $this->uniqueIndex(
                [self::FIELD_CODE, self::FIELD_EAV_ENTITY_ID],
                '实体和属性集code唯一索引'
            ),
            'idx_eav_entity_id' => $this->index(self::FIELD_EAV_ENTITY_ID, '实体索引'),
            'idx_code' => $this->index(self::FIELD_CODE, '属性集代码索引'),
        ];
    }

    public function getDependencies(): array
    {
        return [
            EavEntitySchema::class,
        ];
    }

    public function getUniqueKey(): string|array
    {
        return [self::FIELD_CODE, self::FIELD_EAV_ENTITY_ID];
    }
}
