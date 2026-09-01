<?php

declare(strict_types=1);

namespace Weline\Eav\Schema;

/**
 * 属性跨属性集归属（placement）表结构。
 */
class EavAttributePlacementSchema extends AbstractSchema
{
    public const TABLE_NAME = 'eav_attribute_placement';

    public const FIELD_ID = 'placement_id';
    public const FIELD_ATTRIBUTE_ID = 'attribute_id';
    public const FIELD_EAV_ENTITY_ID = 'eav_entity_id';
    public const FIELD_SET_ID = 'set_id';
    public const FIELD_GROUP_ID = 'group_id';

    public function getTableName(): string
    {
        return self::TABLE_NAME;
    }

    public function getTableComment(): string
    {
        return '属性跨属性集归属表';
    }

    public function getColumns(): array
    {
        return [
            self::FIELD_ID => $this->primaryKey('Placement ID'),
            self::FIELD_ATTRIBUTE_ID => $this->integer('Attribute ID', 'not null'),
            self::FIELD_EAV_ENTITY_ID => $this->integer('EAV Entity ID', 'not null'),
            self::FIELD_SET_ID => $this->integer('Attribute Set ID', 'not null'),
            self::FIELD_GROUP_ID => $this->integer('Attribute Group ID', 'not null'),
        ];
    }

    public function getIndexes(): array
    {
        return [
            'idx_unique_attribute_set' => $this->uniqueIndex(
                [self::FIELD_ATTRIBUTE_ID, self::FIELD_SET_ID],
                'Unique attribute per set placement',
            ),
            'idx_group_id' => $this->index(self::FIELD_GROUP_ID, 'Group Index'),
            'idx_set_id' => $this->index(self::FIELD_SET_ID, 'Set Index'),
        ];
    }

    public function getDependencies(): array
    {
        return [
            EavAttributeSchema::class,
            EavAttributeSetSchema::class,
            EavAttributeGroupSchema::class,
            EavEntitySchema::class,
        ];
    }
}
