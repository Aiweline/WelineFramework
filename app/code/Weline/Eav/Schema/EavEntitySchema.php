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
 * EAV实体表结构定义
 * 
 * 对应表: m_eav_entity
 * 关联Model: Weline\Eav\Model\EavEntity
 */
class EavEntitySchema extends AbstractSchema
{
    public const TABLE_NAME = 'eav_entity';
    
    // 字段常量
    public const FIELD_ID = 'eav_entity_id';
    public const FIELD_CODE = 'code';
    public const FIELD_NAME = 'name';
    public const FIELD_CLASS = 'class';
    public const FIELD_IS_SYSTEM = 'is_system';
    public const FIELD_ID_FIELD_TYPE = 'eav_entity_id_field_type';
    public const FIELD_ID_FIELD_LENGTH = 'eav_entity_id_field_length';

    public function getTableName(): string
    {
        return self::TABLE_NAME;
    }

    public function getTableComment(): string
    {
        return 'EAV实体表';
    }

    public function getColumns(): array
    {
        return [
            self::FIELD_ID => $this->primaryKey('实体ID'),
            self::FIELD_CODE => $this->varchar('实体代码', 255, 'unique not null'),
            self::FIELD_NAME => $this->varchar('实体名', 255, 'not null'),
            self::FIELD_CLASS => $this->varchar('实体类', 255, 'not null'),
            self::FIELD_ID_FIELD_TYPE => $this->varchar('实体ID字段类型', 60, 'not null'),
            self::FIELD_ID_FIELD_LENGTH => $this->smallint('实体ID字段长度', 5, 'not null'),
            self::FIELD_IS_SYSTEM => $this->boolean('是否系统生成', false),
        ];
    }

    public function getIndexes(): array
    {
        return [
            'idx_code' => $this->uniqueIndex(self::FIELD_CODE, '实体编码唯一索引'),
            'idx_name' => $this->index(self::FIELD_NAME, '实体名索引'),
        ];
    }

    public function getUniqueKey(): string|array
    {
        return self::FIELD_CODE;
    }
}
