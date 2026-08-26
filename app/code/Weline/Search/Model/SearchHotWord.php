<?php

declare(strict_types=1);

namespace Weline\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Channel-scoped search hot words')]
#[Index(name: 'uk_search_hot_scope_word', columns: ['website_id', 'store_id', 'channel_id', 'word'], type: 'UNIQUE')]
class SearchHotWord extends Model
{
    public const schema_table = 'search_hot_word';
    public const schema_primary_key = 'hot_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false)]
    public const schema_fields_ID = 'hot_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_STORE_ID = 'store_id';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_CHANNEL_ID = 'channel_id';

    #[Col('varchar', 64, nullable: false, default: '')]
    public const schema_fields_WORD = 'word';

    #[Col('int', 11, nullable: false, default: 0)]
    public const schema_fields_SORT_ORDER = 'sort_order';

    #[Col('tinyint', 1, nullable: false, default: 1)]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
