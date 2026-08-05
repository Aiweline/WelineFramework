<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class AttributeValue extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'value_id';
    public const schema_fields_ID = 'value_id';
    public const schema_fields_STORE_ID = 'store_id';
    public const schema_fields_ENTITY_TYPE = 'entity_type';
    public const schema_fields_ENTITY_ID = 'entity_id';
    public const schema_fields_ATTRIBUTE_CODE = 'attribute_code';
    public const schema_fields_LOCALE = 'locale';
    public const schema_fields_VALUE_TEXT = 'value_text';
    public const schema_fields_CLEARED = 'cleared';
    public const schema_fields_IS_REQUIRED = 'is_required';

    public const WEBSITE_STORE_ID = 0;

    public static function entityCode(): string
    {
        return 'attribute_value';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
