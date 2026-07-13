<?php

declare(strict_types=1);

namespace Weline\Database\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Per-module rollback operation items')]
#[Index(name: 'idx_operation_item', columns: ['operation_id', 'sort_order'], type: 'KEY')]
class ModuleVersionOperationItem extends Model
{
    public const schema_table = 'weline_database_module_version_operation_item';
    public const schema_primary_key = 'id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 64, nullable: false, comment: 'Operation ID')]
    public const schema_fields_OPERATION_ID = 'operation_id';
    #[Col('varchar', 100, nullable: false, comment: 'Module Name')]
    public const schema_fields_MODULE_NAME = 'module_name';
    #[Col('varchar', 50, nullable: false, comment: 'Current Version')]
    public const schema_fields_FROM_VERSION = 'from_version';
    #[Col('varchar', 50, nullable: false, comment: 'Target Version')]
    public const schema_fields_TO_VERSION = 'to_version';
    #[Col('varchar', 64, nullable: true, comment: 'Artifact Provider')]
    public const schema_fields_ARTIFACT_PROVIDER = 'artifact_provider';
    #[Col('varchar', 1024, nullable: true, comment: 'Staged Artifact Path')]
    public const schema_fields_ARTIFACT_PATH = 'artifact_path';
    #[Col('varchar', 64, nullable: true, comment: 'Artifact Checksum')]
    public const schema_fields_ARTIFACT_CHECKSUM = 'artifact_checksum';
    #[Col('varchar', 1024, nullable: true, comment: 'Current Code Snapshot Path')]
    public const schema_fields_BACKUP_PATH = 'backup_path';
    #[Col('varchar', 32, nullable: false, default: 'planned', comment: 'Item Status')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', nullable: false, default: 0, comment: 'Reverse dependency order')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('text', nullable: true, comment: 'Last Error')]
    public const schema_fields_ERROR = 'error_message';
    #[Col('timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created At')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated At')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
