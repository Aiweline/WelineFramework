<?php

declare(strict_types=1);

namespace Weline\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Cross-worker Search degraded marker.
 */
#[Table(comment: 'Search degraded serving state by Website')]
#[Index(name: 'uk_search_degrade_website', columns: ['website_id'], type: 'UNIQUE')]
#[Index(name: 'idx_search_degrade_active', columns: ['active'])]
class SearchDegradeState extends Model
{
    public const schema_table = 'search_degrade_state';
    public const schema_primary_key = 'marker_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Marker ID')]
    public const schema_fields_ID = 'marker_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (0 is valid)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('tinyint', 1, nullable: false, default: 0, comment: 'Degraded marker active')]
    public const schema_fields_ACTIVE = 'active';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Stable degrade reason')]
    public const schema_fields_REASON = 'reason';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Required Product source watermark')]
    public const schema_fields_REQUIRED_SOURCE_WATERMARK = 'required_source_watermark';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Search incremental watermark when marked')]
    public const schema_fields_INDEX_WATERMARK_AT_MARK = 'index_watermark_at_mark';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'CAS marker version')]
    public const schema_fields_MARKER_VERSION = 'marker_version';

    #[Col('char', 64, nullable: false, default: '', comment: 'Writer-owned CAS token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Marked at')]
    public const schema_fields_MARKED_AT = 'marked_at';

    #[Col('datetime', nullable: true, comment: 'Cleared at')]
    public const schema_fields_CLEARED_AT = 'cleared_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
