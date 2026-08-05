<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Durable Scope maintenance state')]
#[Index(name: 'uk_scope_maintenance_key', columns: ['scope_key'], type: 'UNIQUE')]
#[Index(name: 'idx_scope_maintenance_website', columns: ['website_id', 'enabled'])]
class ScopeMaintenanceState extends Model
{
    public const use_main_db_master = true;
    public const schema_table = 'websites_scope_maintenance';
    public const schema_primary_key = 'maintenance_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Maintenance state ID')]
    public const schema_fields_ID = 'maintenance_id';

    #[Col('varchar', 512, nullable: false, comment: 'Canonical Scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col('varchar', 16, nullable: false, comment: 'website|store|channel')]
    public const schema_fields_SCOPE_KIND = 'scope_kind';

    #[Col('int', 11, nullable: false, comment: 'Website ID (0=default site)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 255, nullable: false, comment: 'Website code')]
    public const schema_fields_WEBSITE_CODE = 'website_code';

    #[Col('varchar', 64, nullable: true, comment: 'Store code')]
    public const schema_fields_STORE_CODE = 'store_code';

    #[Col('varchar', 64, nullable: true, comment: 'Channel code')]
    public const schema_fields_CHANNEL_CODE = 'channel_code';

    #[Col('varchar', 16, nullable: true, comment: 'normal|dev|test')]
    public const schema_fields_STORE_MODE = 'store_mode';

    #[Col('varchar', 16, nullable: false, comment: 'Scope context version')]
    public const schema_fields_CONTEXT_VERSION = 'context_version';

    #[Col('int', 1, nullable: false, default: 0, comment: 'Maintenance enabled')]
    public const schema_fields_ENABLED = 'enabled';

    #[Col('varchar', 500, nullable: false, default: '', comment: 'Operator reason')]
    public const schema_fields_REASON = 'reason';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Token invalidation generation')]
    public const schema_fields_GENERATION = 'generation';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Enabled since UTC epoch')]
    public const schema_fields_SINCE = 'since_at';

    #[Col('datetime', nullable: false, comment: 'Last update UTC')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
