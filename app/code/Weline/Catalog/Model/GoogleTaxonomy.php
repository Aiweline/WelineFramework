<?php

declare(strict_types=1);

namespace Weline\Catalog\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Read-only Google product taxonomy reference (structure immutable from admin UI).
 */
#[Table(comment: 'Google product taxonomy reference')]
#[Index(name: 'uk_catalog_google_taxonomy_id', columns: ['google_id'], type: 'UNIQUE')]
#[Index(name: 'idx_catalog_google_parent', columns: ['parent_google_id'])]
final class GoogleTaxonomy extends Model
{
    public const schema_table = 'catalog_google_taxonomy';
    public const schema_primary_key = 'row_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'row_id';

    #[Col('varchar', 32, nullable: false, default: '')]
    public const schema_fields_GOOGLE_ID = 'google_id';

    #[Col('varchar', 32, nullable: true, default: null)]
    public const schema_fields_PARENT_GOOGLE_ID = 'parent_google_id';

    #[Col('varchar', 512, nullable: false, default: '')]
    public const schema_fields_PATH = 'path';

    #[Col('varchar', 255, nullable: false, default: '')]
    public const schema_fields_NAME_EN = 'name_en';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_DEPTH = 'depth';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
