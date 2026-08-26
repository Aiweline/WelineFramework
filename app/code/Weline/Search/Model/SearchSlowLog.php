<?php

declare(strict_types=1);

namespace Weline\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Search hub slow request log')]
#[Index(name: 'idx_search_slow_scope_created', columns: ['website_id', 'store_id', 'channel_id', 'created_at'])]
#[Index(name: 'idx_search_slow_scope_elapsed', columns: ['website_id', 'store_id', 'channel_id', 'elapsed_ms'])]
class SearchSlowLog extends Model
{
    public const schema_table = 'search_slow_log';
    public const schema_primary_key = 'slow_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'slow_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_CHANNEL_ID = 'channel_id';

    #[Col('varchar', 255, nullable: false, default: '')]
    public const schema_fields_Q = 'q';

    #[Col('varchar', 32, nullable: false, default: 'all')]
    public const schema_fields_TYPE = 'type';

    #[Col('varchar', 32, nullable: false, default: 'mysql')]
    public const schema_fields_ENGINE = 'engine';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_ELAPSED_MS = 'elapsed_ms';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_HIT_COUNT = 'hit_count';

    #[Col('int', 11, nullable: false, default: 200)]
    public const schema_fields_THRESHOLD_MS = 'threshold_ms';

    #[Col('varchar', 64, nullable: false, default: 'hub_elapsed')]
    public const schema_fields_REASON = 'reason';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP')]
    public const schema_fields_CREATED_AT = 'created_at';
}
