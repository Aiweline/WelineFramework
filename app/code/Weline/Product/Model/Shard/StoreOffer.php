<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class StoreOffer extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'store_offer_id';
    public const schema_fields_ID = 'store_offer_id';
    public const schema_fields_STORE_ID = 'store_id';
    public const schema_fields_OFFER_ID = 'offer_id';
    public const schema_fields_SELECTED = 'selected';

    public static function entityCode(): string
    {
        return 'store_offer';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
