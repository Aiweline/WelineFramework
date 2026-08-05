<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 迁移 checkpoint 持久化行（additive；文件 journal 由 MigrationCheckpointJournalStore 承担跨进程 verify，
 * 本表供后续同库 MIG apply 落库镜像）。
 */
#[Table(comment: '商城迁移 checkpoint')]
#[Index(name: 'uk_mig_checkpoint_id', columns: ['checkpoint_id'], type: 'UNIQUE')]
class MigrationCheckpoint extends Model
{
    public const schema_table = 'weline_migration_checkpoint';
    public const schema_primary_key = 'id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';

    #[Col('varchar', 64, nullable: false, comment: 'checkpoint ID')]
    public const schema_fields_CHECKPOINT_ID = 'checkpoint_id';

    #[Col('varchar', 64, nullable: false, comment: 'phase')]
    public const schema_fields_PHASE = 'phase';

    #[Col('varchar', 64, nullable: false, comment: 'manifest sha256')]
    public const schema_fields_MANIFEST_HASH = 'manifest_hash';

    #[Col('varchar', 64, nullable: false, comment: 'connector fingerprint')]
    public const schema_fields_CONNECTOR_FINGERPRINT = 'connector_fingerprint';

    #[Col('text', nullable: false, comment: 'manifest JSON')]
    public const schema_fields_MANIFEST_JSON = 'manifest_json';

    #[Col('text', nullable: false, comment: 'journal JSON')]
    public const schema_fields_JOURNAL_JSON = 'journal_json';

    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
}
