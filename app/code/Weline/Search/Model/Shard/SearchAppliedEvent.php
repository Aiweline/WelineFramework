<?php

declare(strict_types=1);

namespace Weline\Search\Model\Shard;

final class SearchAppliedEvent extends AbstractSearchWebsiteShardModel
{
    public const schema_primary_key = 'applied_event_id';
    public const schema_fields_ID = 'applied_event_id';
    public const schema_fields_GENERATION = 'generation';
    public const schema_fields_EVENT_SEQ = 'event_seq';
    public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    public const schema_fields_PAYLOAD_HASH = 'payload_hash';
    public const schema_fields_APPLIED_AT = 'applied_at';

    public static function entityCode(): string
    {
        return 'applied_event';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
