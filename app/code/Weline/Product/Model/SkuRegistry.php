<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Global SKU / Product / Offer identity source (non-shard).
 */
#[Table(comment: 'Global SKU identity registry')]
#[Index(name: 'uk_sku_registry_sku', columns: ['sku'], type: 'UNIQUE')]
#[Index(name: 'uk_sku_registry_product_uuid', columns: ['global_product_uuid'], type: 'UNIQUE')]
#[Index(name: 'uk_sku_registry_offer_uuid', columns: ['global_offer_uuid'], type: 'UNIQUE')]
#[Index(name: 'idx_sku_registry_status', columns: ['status'])]
class SkuRegistry extends Model
{
    public const schema_table = 'weline_sku_registry';
    public const schema_primary_key = 'registry_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_TOMBSTONED = 'tombstoned';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Registry ID')]
    public const schema_fields_ID = 'registry_id';

    #[Col('varchar', 128, nullable: false, unique: true, comment: 'Canonical SKU')]
    public const schema_fields_SKU = 'sku';

    #[Col('varchar', 36, nullable: false, unique: true, comment: 'Global product UUID')]
    public const schema_fields_GLOBAL_PRODUCT_UUID = 'global_product_uuid';

    #[Col('varchar', 36, nullable: false, unique: true, comment: 'Global offer UUID')]
    public const schema_fields_GLOBAL_OFFER_UUID = 'global_offer_uuid';

    #[Col('varchar', 128, nullable: false, comment: 'Claim request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Reference count')]
    public const schema_fields_REF_COUNT = 'ref_count';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Last successful CAS mutation token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('varchar', 32, nullable: false, default: self::STATUS_ACTIVE, comment: 'Status')]
    public const schema_fields_STATUS = 'status';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
