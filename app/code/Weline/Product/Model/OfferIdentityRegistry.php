<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Global Offer and SKU identity V2')]
#[Index(name: 'uk_offer_v2_uuid', columns: ['global_offer_uuid'], type: 'UNIQUE')]
#[Index(name: 'uk_offer_v2_sku', columns: ['sku'], type: 'UNIQUE')]
#[Index(name: 'uk_offer_v2_request', columns: ['request_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_offer_v2_product_status', columns: ['global_product_uuid', 'status'])]
final class OfferIdentityRegistry extends Model
{
    public const schema_table = 'weline_offer_identity_v2';
    public const schema_primary_key = 'registry_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ARCHIVED = 'archived';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Registry ID')]
    public const schema_fields_ID = 'registry_id';

    #[Col('varchar', 36, nullable: false, unique: true, comment: 'Global Offer UUID')]
    public const schema_fields_UUID = 'global_offer_uuid';

    #[Col('varchar', 36, nullable: false, comment: 'Global Product UUID')]
    public const schema_fields_PRODUCT_UUID = 'global_product_uuid';

    #[Col('varchar', 128, nullable: false, unique: true, comment: 'Canonical global SKU')]
    public const schema_fields_SKU = 'sku';

    #[Col('varchar', 32, nullable: false, default: self::STATUS_ACTIVE, comment: 'Offer identity status')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 11, nullable: false, default: 1, comment: 'Optimistic identity version')]
    public const schema_fields_VERSION = 'version';

    #[Col('varchar', 128, nullable: false, unique: true, comment: 'Idempotent request hash')]
    public const schema_fields_REQUEST_HASH = 'request_hash';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';


    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
