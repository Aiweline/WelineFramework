<?php

declare(strict_types=1);

namespace Weline\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Search slow request daily aggregates')]
#[Index(name: 'uk_search_slow_daily_scope_day', columns: ['website_id', 'store_id', 'channel_id', 'day'], type: 'UNIQUE')]
class SearchSlowDaily extends Model
{
    public const schema_table = 'search_slow_daily';
    public const schema_primary_key = 'slow_daily_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'slow_daily_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_CHANNEL_ID = 'channel_id';

    #[Col('date', nullable: false)]
    public const schema_fields_DAY = 'day';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_SLOW_COUNT = 'slow_count';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_P95_MS = 'p95_ms';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_MAX_MS = 'max_ms';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_BUCKET_0_100 = 'bucket_0_100';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_BUCKET_100_200 = 'bucket_100_200';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_BUCKET_200_500 = 'bucket_200_500';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_BUCKET_500_PLUS = 'bucket_500_plus';
}
