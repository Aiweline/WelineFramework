<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Product V2 migration conflict worklist')]
#[Index(name: 'uk_product_migration_conflict', columns: ['source_kind', 'source_key', 'conflict_code'], type: 'UNIQUE')]
#[Index(name: 'idx_product_migration_resolution', columns: ['resolution_status'])]
final class ProductMigrationConflict extends Model
{
    public const schema_table = 'weline_product_migration_conflict';
    public const schema_primary_key = 'conflict_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Conflict ID')]
    public const schema_fields_ID = 'conflict_id';

    #[Col('varchar', 32, nullable: false, comment: 'Source kind')]
    public const schema_fields_SOURCE_KIND = 'source_kind';

    #[Col('varchar', 255, nullable: false, comment: 'Source stable key')]
    public const schema_fields_SOURCE_KEY = 'source_key';

    #[Col('varchar', 64, nullable: false, comment: 'Conflict code')]
    public const schema_fields_CONFLICT_CODE = 'conflict_code';

    #[Col('text', nullable: false, comment: 'Conflict details JSON')]
    public const schema_fields_DETAILS_JSON = 'details_json';

    #[Col('varchar', 32, nullable: false, default: 'open', comment: 'Resolution status')]
    public const schema_fields_RESOLUTION_STATUS = 'resolution_status';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';


    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
