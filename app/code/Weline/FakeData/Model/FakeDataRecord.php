<?php

declare(strict_types=1);

namespace Weline\FakeData\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Fake data ledger records')]
#[Index(name: 'idx_provider_code', columns: ['provider_code'], comment: 'Provider code index')]
#[Index(name: 'idx_stable_key', columns: ['stable_key'], comment: 'Stable key index')]
#[Index(name: 'idx_entity', columns: ['entity_type', 'entity_id'], comment: 'Entity lookup index')]
class FakeDataRecord extends Model
{
    public const schema_table = 'weline_fake_data_record';
    public const schema_primary_key = 'record_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Record ID')]
    public const schema_fields_ID = 'record_id';
    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Entity type')]
    public const schema_fields_ENTITY_TYPE = 'entity_type';
    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Entity ID')]
    public const schema_fields_ENTITY_ID = 'entity_id';
    #[Col(type: 'varchar', length: 160, nullable: false, comment: 'Stable fake data key')]
    public const schema_fields_STABLE_KEY = 'stable_key';
    #[Col(type: 'text', nullable: true, comment: 'Metadata JSON')]
    public const schema_fields_META_JSON = 'meta_json';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_unit_unique_fields = [
        self::schema_fields_PROVIDER_CODE,
        self::schema_fields_STABLE_KEY,
    ];
    public array $_index_sort_keys = [
        self::schema_fields_PROVIDER_CODE,
        self::schema_fields_ENTITY_TYPE,
        self::schema_fields_ENTITY_ID,
    ];
}

