<?php

declare(strict_types=1);

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Durable signed maintenance preview token registry')]
#[Index(name: 'uk_maintenance_preview_hash', columns: ['token_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_maintenance_preview_scope', columns: ['scope_key', 'generation', 'revoked'])]
#[Index(name: 'idx_maintenance_preview_expiry', columns: ['expires_at'])]
class MaintenancePreviewToken extends Model
{
    public const use_main_db_master = true;
    public const schema_table = 'websites_maintenance_preview_token';
    public const schema_primary_key = 'token_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Token row ID')]
    public const schema_fields_ID = 'token_id';

    #[Col('char', 64, nullable: false, comment: 'SHA-256 token digest')]
    public const schema_fields_TOKEN_HASH = 'token_hash';

    #[Col('varchar', 512, nullable: false, comment: 'Canonical Scope key')]
    public const schema_fields_SCOPE_KEY = 'scope_key';

    #[Col('varchar', 64, nullable: false, comment: 'Signing key ID')]
    public const schema_fields_KID = 'kid';

    #[Col('bigint', 20, nullable: false, comment: 'Maintenance generation')]
    public const schema_fields_GENERATION = 'generation';

    #[Col('bigint', 20, nullable: false, comment: 'Issued UTC epoch')]
    public const schema_fields_ISSUED_AT = 'issued_at';

    #[Col('bigint', 20, nullable: false, comment: 'Expiry UTC epoch')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    #[Col('int', 1, nullable: false, default: 0, comment: 'Revoked')]
    public const schema_fields_REVOKED = 'revoked';

    #[Col('bigint', 20, nullable: true, comment: 'Revoked UTC epoch')]
    public const schema_fields_REVOKED_AT = 'revoked_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
