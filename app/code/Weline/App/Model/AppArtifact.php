<?php
declare(strict_types=1);

namespace Weline\App\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'App 产物登记表')]
#[Index(name: 'uk_build', columns: ['build_id'], type: 'UNIQUE', comment: '构建ID唯一索引')]
#[Index(name: 'idx_platform_status', columns: ['platform', 'status'], type: 'KEY', comment: '平台状态联合索引')]
class AppArtifact extends Model
{
    public const schema_table = 'weline_app_artifact';
    public const schema_primary_key = 'id';

    public const STATUS_BUILDING = 'building';
    public const STATUS_RELEASED = 'released';
    public const STATUS_FAILED = 'failed';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '产物ID')]
    public const schema_fields_ID = 'id';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: '构建ID（构建期生成）')]
    public const schema_fields_build_id = 'build_id';

    #[Col(type: 'varchar', length: 16, nullable: false, comment: '平台 ios|android|web')]
    public const schema_fields_platform = 'platform';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: '版本（跟随系统+模块）')]
    public const schema_fields_version = 'version';

    #[Col(type: 'int', nullable: false, default: 0, comment: '构建期发布戳（0=未盖章）')]
    public const schema_fields_build_ts = 'build_ts';

    #[Col(type: 'char', length: 64, nullable: false, default: '', comment: '构建期 sha256')]
    public const schema_fields_sha256 = 'sha256';

    #[Col(type: 'bigint', nullable: false, default: 0, comment: '产物字节数')]
    public const schema_fields_size_bytes = 'size_bytes';

    #[Col(type: 'varchar', length: 16, nullable: false, default: 'building', comment: '状态 building|released|failed')]
    public const schema_fields_status = 'status';

    #[Col(type: 'varchar', length: 512, nullable: false, default: '', comment: '产物相对路径')]
    public const schema_fields_path = 'path';

    #[Col(type: 'text', nullable: true, comment: '更新日志')]
    public const schema_fields_changelog = 'changelog';

    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';
}
