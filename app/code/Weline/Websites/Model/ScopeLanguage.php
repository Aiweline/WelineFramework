<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Store/Channel language membership + display order when override is active.
 *
 * Store rows: channel_id = ScopeLanguageOverride::CHANNEL_ID_STORE_SCOPE (-1).
 * Channel rows: channel_id >= 0 (0 = default channel).
 */
#[Table(comment: '店铺/渠道语种覆盖明细')]
#[Index(name: 'uk_scope_language', columns: ['website_id', 'store_id', 'channel_id', 'language_code'], type: 'UNIQUE', comment: 'scope语种唯一')]
#[Index(name: 'idx_scope_language_store', columns: ['store_id', 'sort_order'], comment: '店铺序')]
class ScopeLanguage extends Model
{
    public const schema_table = 'weline_websites_scope_language';
    public const schema_primary_key = 'scope_language_id';

    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '主键')]
    public const schema_fields_ID = 'scope_language_id';

    #[Col('int', 11, nullable: false, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: '店铺ID')]
    public const schema_fields_STORE_ID = 'store_id';

    /** -1 = store-level; >=0 = channel-level. */
    #[Col('int', 11, nullable: false, default: -1, comment: '渠道ID；-1=店铺层')]
    public const schema_fields_CHANNEL_ID = 'channel_id';

    #[Col('varchar', 20, nullable: false, comment: '语言代码')]
    public const schema_fields_LANGUAGE_CODE = 'language_code';

    #[Col('int', 11, nullable: false, default: 0, comment: '展示序（越小越靠前）')]
    public const schema_fields_SORT_ORDER = 'sort_order';
}
