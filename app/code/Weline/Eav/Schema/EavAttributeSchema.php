<?php

declare(strict_types=1);

namespace Weline\Eav\Schema;

/**
 * EAV attribute table schema.
 */
class EavAttributeSchema extends AbstractSchema
{
    public const TABLE_NAME = 'eav_attribute';

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

    public const FIELD_BASIC_IS_ENABLE = 'basic_is_enable';

    public const FIELD_FRONTEND_IS_VISIBLE = 'frontend_is_visible';
    public const FIELD_FRONTEND_IS_FILTERABLE = 'frontend_is_filterable';
    public const FIELD_FRONTEND_IS_SEARCHABLE = 'frontend_is_searchable';

    public const FIELD_DATA_IS_MULTIPLE = 'data_is_multiple';
    public const FIELD_DATA_HAS_OPTION = 'data_has_option';

    /** @deprecated use FIELD_DATA_IS_MULTIPLE */
    public const FIELD_MULTIPLE_VALUED = 'data_is_multiple';
    /** @deprecated use FIELD_DATA_HAS_OPTION */
    public const FIELD_HAS_OPTION = 'data_has_option';
    /** @deprecated use FIELD_BASIC_IS_ENABLE */
    public const FIELD_IS_ENABLE = 'basic_is_enable';
    /** @deprecated use FIELD_FRONTEND_IS_FILTERABLE */
    public const FIELD_IS_FILTERABLE = 'frontend_is_filterable';
    /** @deprecated use FIELD_FRONTEND_IS_SEARCHABLE */
    public const FIELD_IS_SEARCHABLE = 'frontend_is_searchable';
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
            self::FIELD_ID => $this->primaryKey('Attribute ID'),
            self::FIELD_CODE => $this->varchar('Attribute Code', 255, 'not null'),
            self::FIELD_EAV_ENTITY_ID => $this->integer('EAV Entity ID', 'not null'),
            self::FIELD_SET_ID => $this->integer('Attribute Set ID', 'not null'),
            self::FIELD_GROUP_ID => $this->integer('Attribute Group ID', 'not null'),
            self::FIELD_NAME => $this->varchar('Attribute Name', 255, 'not null'),
            self::FIELD_TYPE_ID => $this->integer('Attribute Type ID', 'not null'),
            self::FIELD_IS_SYSTEM => $this->boolean('Is System', false),
            self::FIELD_MODEL_CLASS => $this->varchar('Model Class', 255, "default ''"),
            self::FIELD_DEFAULT_VALUE => $this->text('Default Value'),
            self::FIELD_DEPENDENCE => $this->varchar('Dependence', 128, 'default null'),
            self::FIELD_BASIC_IS_ENABLE => $this->boolean('Is Enabled', true),
            self::FIELD_FRONTEND_IS_VISIBLE => $this->boolean('Is Visible On Frontend', false),
            self::FIELD_FRONTEND_IS_FILTERABLE => $this->boolean('Is Filterable', false),
            self::FIELD_FRONTEND_IS_SEARCHABLE => $this->boolean('Is Searchable', false),
            self::FIELD_DATA_IS_MULTIPLE => $this->boolean('Is Multiple', false),
            self::FIELD_DATA_HAS_OPTION => $this->boolean('Has Option', false),
        ];
    }

    public function getIndexes(): array
    {
        return [
            'idx_unique_entity_and_code' => $this->uniqueIndex(
                [self::FIELD_CODE, self::FIELD_EAV_ENTITY_ID],
                'Unique entity and code'
            ),
            'idx_eav_entity_id' => $this->index(self::FIELD_EAV_ENTITY_ID, 'Entity Index'),
            'idx_set_id' => $this->index(self::FIELD_SET_ID, 'Set Index'),
            'idx_group_id' => $this->index(self::FIELD_GROUP_ID, 'Group Index'),
            'idx_name' => $this->index(self::FIELD_NAME, 'Name Index'),
            'idx_frontend_filterable' => $this->index(self::FIELD_FRONTEND_IS_FILTERABLE, 'Filterable Index'),
            'idx_frontend_searchable' => $this->index(self::FIELD_FRONTEND_IS_SEARCHABLE, 'Searchable Index'),
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
