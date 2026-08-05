<?php

declare(strict_types=1);

namespace Weline\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Cross-worker Search serving alias by Website.
 */
#[Table(comment: 'Search serving alias by Website')]
#[Index(name: 'uk_search_serving_alias_website', columns: ['website_id'], type: 'UNIQUE')]
#[Index(name: 'idx_search_serving_alias_active', columns: ['active_alias'])]
class SearchServingAlias extends Model
{
    public const schema_table = 'search_serving_alias';
    public const schema_primary_key = 'alias_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Alias ID')]
    public const schema_fields_ID = 'alias_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (0 is valid)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 16, nullable: false, default: 'direct', comment: 'Serving alias')]
    public const schema_fields_ACTIVE_ALIAS = 'active_alias';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Verified Search generation')]
    public const schema_fields_ACTIVE_GENERATION = 'active_generation';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Alias CAS version')]
    public const schema_fields_ALIAS_VERSION = 'alias_version';

    #[Col('char', 64, nullable: false, default: '', comment: 'Writer-owned CAS token')]
    public const schema_fields_CAS_TOKEN = 'cas_token';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
