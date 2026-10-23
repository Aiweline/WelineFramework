<?php

declare(strict_types=1);

namespace Weline\Database\Model\Release;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Immutable release row: one DB-affecting release owns exactly one schema epoch.
 */
#[Table(comment: 'Schema release manifest, checksum and signature')]
#[Index(name: 'uk_schema_release_release_id', columns: ['release_id'], type: 'UNIQUE', comment: 'Release ID is globally unique')]
#[Index(name: 'uk_schema_release_schema_epoch', columns: ['schema_epoch'], type: 'UNIQUE', comment: 'Schema epoch is monotonic and unique')]
class SchemaRelease extends Model
{
    public const schema_table = 'weline_schema_release';
    public const schema_primary_key = 'id';

    public const ROLLBACK_POLICY_L0 = 'L0';
    public const ROLLBACK_POLICY_L1 = 'L1';
    public const ROLLBACK_POLICY_L2 = 'L2';
    public const ROLLBACK_POLICY_L3 = 'L3';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLYING = 'applying';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Release row ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'varchar', length: 128, nullable: false, comment: 'Release ID')]
    public const schema_fields_RELEASE_ID = 'release_id';

    #[Col(type: 'bigint', nullable: false, default: 0, comment: 'Monotonic schema epoch')]
    public const schema_fields_SCHEMA_EPOCH = 'schema_epoch';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Git commit SHA')]
    public const schema_fields_GIT_COMMIT = 'git_commit';

    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Git tag')]
    public const schema_fields_GIT_TAG = 'git_tag';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Deploy version')]
    public const schema_fields_DEPLOY_VERSION = 'deploy_version';

    #[Col(type: 'longtext', nullable: true, comment: 'Release manifest JSON')]
    public const schema_fields_MANIFEST_JSON = 'manifest_json';

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'SHA-256 of manifest_json')]
    public const schema_fields_MANIFEST_CHECKSUM = 'manifest_checksum';

    #[Col(type: 'longtext', nullable: true, comment: 'Manifest signature JSON')]
    public const schema_fields_SIGNATURE = 'signature';

    #[Col(type: 'varchar', length: 16, nullable: false, default: 'L0', comment: 'Rollback policy L0-L3')]
    public const schema_fields_ROLLBACK_POLICY = 'rollback_policy';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'pending', comment: 'Release status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'timestamp', nullable: true, comment: 'Row created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'timestamp', nullable: true, comment: 'Release applied at')]
    public const schema_fields_APPLIED_AT = 'applied_at';
}
