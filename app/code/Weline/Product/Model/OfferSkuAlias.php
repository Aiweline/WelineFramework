<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Permanent V2 SKU aliases')]
#[Index(name: 'uk_offer_alias_v2_sku', columns: ['sku'], type: 'UNIQUE')]
#[Index(name: 'idx_offer_alias_v2_uuid', columns: ['global_offer_uuid'])]
final class OfferSkuAlias extends Model
{
    public const schema_table = 'weline_offer_sku_alias_v2';
    public const schema_primary_key = 'alias_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Alias ID')]
    public const schema_fields_ID = 'alias_id';

    #[Col('varchar', 128, nullable: false, unique: true, comment: 'Reserved historical SKU')]
    public const schema_fields_SKU = 'sku';

    #[Col('varchar', 36, nullable: false, comment: 'Global Offer UUID')]
    public const schema_fields_OFFER_UUID = 'global_offer_uuid';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';


    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
