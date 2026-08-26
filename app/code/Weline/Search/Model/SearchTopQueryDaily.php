<?php

declare(strict_types=1);

namespace Weline\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Search top keywords daily')]
#[Index(name: 'uk_search_top_scope_day_q_type', columns: ['website_id', 'store_id', 'channel_id', 'day', 'q_hash', 'type'], type: 'UNIQUE')]
#[Index(name: 'idx_search_top_scope_day_count', columns: ['website_id', 'store_id', 'channel_id', 'day', 'request_count'])]
class SearchTopQueryDaily extends Model
{
    public const schema_table = 'search_top_query_daily';
    public const schema_primary_key = 'top_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'top_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_CHANNEL_ID = 'channel_id';

    #[Col('date', nullable: false)]
    public const schema_fields_DAY = 'day';

    #[Col('varchar', 255, nullable: false, default: '')]
    public const schema_fields_Q = 'q';

    #[Col('char', 64, nullable: false, default: '')]
    public const schema_fields_Q_HASH = 'q_hash';

    #[Col('varchar', 32, nullable: false, default: 'all')]
    public const schema_fields_TYPE = 'type';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_REQUEST_COUNT = 'request_count';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_ZERO_RESULT_COUNT = 'zero_result_count';
}
