<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Flags whether a Store or Channel has an explicit language membership override.
 *
 * Store scope: channel_id = {@see self::CHANNEL_ID_STORE_SCOPE} (-1). Never use 0 as sentinel
 * (0 is SalesChannel::ID_DEFAULT). Channel scope: channel_id >= 0.
 * has_override=1 with zero child rows = intentional empty list (not inherit).
 */
#[Table(comment: '店铺/渠道语种覆盖旗标')]
#[Index(name: 'uk_scope_lang_override', columns: ['website_id', 'store_id', 'channel_id'], type: 'UNIQUE', comment: 'scope唯一')]
#[Index(name: 'idx_scope_lang_override_store', columns: ['store_id'], comment: '店铺索引')]
class ScopeLanguageOverride extends Model
{
    /** Sentinel channel_id for store-level override rows (not a real channel). */
    public const CHANNEL_ID_STORE_SCOPE = -1;

    public const schema_table = 'weline_websites_scope_language_override';
    public const schema_primary_key = 'override_id';

    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '主键')]
    public const schema_fields_ID = 'override_id';

    #[Col('int', 11, nullable: false, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('int', 11, nullable: false, comment: '店铺ID（含默认店0）')]
    public const schema_fields_STORE_ID = 'store_id';

    /** -1 = store-level; >=0 = channel-level (0 = default channel). */
    #[Col('int', 11, nullable: false, default: -1, comment: '渠道ID；-1=店铺层覆盖')]
    public const schema_fields_CHANNEL_ID = 'channel_id';

    #[Col('smallint', 1, nullable: false, default: 1, comment: '1=本层已覆盖（可空名单）')]
    public const schema_fields_HAS_OVERRIDE = 'has_override';
}
