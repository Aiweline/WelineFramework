<?php

declare(strict_types=1);

namespace Weline\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Search daily aggregates for reports')]
#[Index(name: 'uk_search_daily_scope_day_type', columns: ['website_id', 'store_id', 'channel_id', 'day', 'type'], type: 'UNIQUE')]
class SearchQueryDaily extends Model
{
    public const schema_table = 'search_query_daily';
    public const schema_primary_key = 'daily_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'daily_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_CHANNEL_ID = 'channel_id';

    #[Col('date', nullable: false)]
    public const schema_fields_DAY = 'day';

    #[Col('varchar', 32, nullable: false, default: 'all')]
    public const schema_fields_TYPE = 'type';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_REQUEST_COUNT = 'request_count';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_ZERO_RESULT_COUNT = 'zero_result_count';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_TOTAL_HIT_COUNT = 'total_hit_count';
}
