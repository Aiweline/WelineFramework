<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Verified security response-header policy LKG')]
#[Index(name: 'idx_security_policy_lkg_identity', columns: ['schema_version', 'scope_key'], type: 'UNIQUE')]
class SecurityPolicyLkg extends Model
{
    public const schema_table = 'system_config_security_policy_lkg';
    public const schema_primary_key = 'lkg_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'LKG ID')]
    public const schema_fields_ID = 'lkg_id';

    #[Col('varchar', 64, nullable: false, comment: 'Policy schema version')]
    public const schema_fields_SCHEMA_VERSION = 'schema_version';

    #[Col('varchar', 191, nullable: false, comment: 'SystemConfig storage Scope')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col('varchar', 64, nullable: false, comment: 'Canonical policy SHA-256')]
    public const schema_fields_DIGEST = 'digest';

    #[Col('datetime', nullable: false, comment: 'Verification timestamp')]
    public const schema_fields_VERIFIED_AT = 'verified_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
