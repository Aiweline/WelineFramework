<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Historical / renamed SKU aliases pointing at a SkuRegistry identity.
 */
#[Table(comment: 'SKU aliases for renamed identities')]
#[Index(name: 'uk_sku_alias_sku', columns: ['sku'], type: 'UNIQUE')]
#[Index(name: 'idx_sku_alias_registry', columns: ['registry_id'])]
class SkuAlias extends Model
{
    public const schema_table = 'weline_sku_alias';
    public const schema_primary_key = 'alias_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Alias ID')]
    public const schema_fields_ID = 'alias_id';

    #[Col('varchar', 128, nullable: false, unique: true, comment: 'Alias SKU')]
    public const schema_fields_SKU = 'sku';

    #[Col('int', 11, nullable: false, comment: 'SkuRegistry registry_id')]
    public const schema_fields_REGISTRY_ID = 'registry_id';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
