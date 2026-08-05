<?php

declare(strict_types=1);

namespace Weline\Framework\Model\Query;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Encrypted per-record frontend Worker credentials')]
#[Index(name: 'uk_worker_credential_identity', columns: ['credential_type', 'credential_hash'], type: 'UNIQUE')]
#[Index(name: 'idx_worker_credential_expiry', columns: ['expires_at', 'id'])]
#[Index(name: 'idx_worker_credential_type_expiry', columns: ['credential_type', 'expires_at', 'id'])]
#[Index(name: 'idx_worker_credential_scope_expiry', columns: ['credential_type', 'scope_hash', 'expires_at', 'id'])]
final class FrontendWorkerCredential extends Model
{
    public const use_main_db_master = true;
    public const schema_table = 'weline_frontend_worker_credential';
    public const schema_primary_key = 'id';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Internal ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'Credential type')]
    public const schema_fields_TYPE = 'credential_type';
    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Hashed credential scope')]
    public const schema_fields_SCOPE_HASH = 'scope_hash';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Hashed credential identity')]
    public const schema_fields_CREDENTIAL_HASH = 'credential_hash';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Encryption key ID')]
    public const schema_fields_KEY_ID = 'key_id';
    #[Col(type: 'longtext', nullable: false, comment: 'Authenticated encrypted payload')]
    public const schema_fields_CIPHERTEXT = 'ciphertext';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Encoded encrypted payload bytes')]
    public const schema_fields_PAYLOAD_BYTES = 'payload_bytes';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'active', comment: 'Credential lifecycle state')]
    public const schema_fields_STATE = 'state';
    #[Col(type: 'bigint', length: 20, nullable: false, default: 0, comment: 'Consumption Unix timestamp')]
    public const schema_fields_CONSUMED_AT = 'consumed_at';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Lifecycle lock version')]
    public const schema_fields_LOCK_VERSION = 'lock_version';
    #[Col(type: 'bigint', length: 20, nullable: false, comment: 'Creation Unix timestamp')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'bigint', length: 20, nullable: false, comment: 'Expiry Unix timestamp')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_TYPE,
        self::schema_fields_SCOPE_HASH,
        self::schema_fields_CREDENTIAL_HASH,
        self::schema_fields_STATE,
        self::schema_fields_EXPIRES_AT,
    ];
}
