<?php

declare(strict_types=1);

namespace Weline\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Search query append log')]
#[Index(name: 'idx_search_log_scope_day', columns: ['website_id', 'store_id', 'channel_id', 'created_at'])]
#[Index(name: 'idx_search_log_scope_type_day', columns: ['website_id', 'store_id', 'channel_id', 'type', 'created_at'])]
class SearchQueryLog extends Model
{
    public const schema_table = 'search_query_log';
    public const schema_primary_key = 'log_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'log_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_CHANNEL_ID = 'channel_id';

    #[Col('varchar', 255, nullable: false, default: '')]
    public const schema_fields_Q = 'q';

    #[Col('char', 64, nullable: false, default: '')]
    public const schema_fields_Q_HASH = 'q_hash';

    #[Col('varchar', 32, nullable: false, default: 'all')]
    public const schema_fields_TYPE = 'type';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_HIT_COUNT = 'hit_count';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_ELAPSED_MS = 'elapsed_ms';

    #[Col('varchar', 32, nullable: false, default: 'mysql')]
    public const schema_fields_ENGINE = 'engine';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP')]
    public const schema_fields_CREATED_AT = 'created_at';
}
