<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class Price extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'price_id';
    public const schema_fields_ID = 'price_id';
    public const schema_fields_STORE_ID = 'store_id';
    public const schema_fields_OFFER_ID = 'offer_id';
    public const schema_fields_CURRENCY = 'currency';
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';
    public const schema_fields_CLEARED = 'cleared';

    public const WEBSITE_STORE_ID = 0;

    public static function entityCode(): string
    {
        return 'price';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
