<?php

declare(strict_types=1);

namespace Weline\Deploy\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '发布历史')]
#[Index(name: 'idx_deploy_version', columns: ['deploy_version'])]
#[Index(name: 'idx_is_current', columns: ['is_current'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_git_ref_type', columns: ['git_ref_type'])]
#[Index(name: 'idx_trigger_type', columns: ['trigger_type'])]
class DeployRelease extends Model
{
    public const schema_table        = 'w_deploy_release';
    public const schema_primary_key = 'release_id';

    #[Col('varchar', 64, nullable: false, primaryKey: true, comment: '发布 ID')]
    public const schema_fields_ID = 'release_id';

    #[Col('varchar', 64, nullable: false, comment: '部署版本（tag 名或 commit 短 SHA）')]
    public const schema_fields_DEPLOY_VERSION = 'deploy_version';

    #[Col('varchar', 64, comment: 'Worker 构建 ID')]
    public const schema_fields_WORKER_BUILD_ID = 'worker_build_id';

    #[Col('varchar', 16, nullable: false, default: 'branch', comment: 'ref 类型：branch / tag')]
    public const schema_fields_GIT_REF_TYPE = 'git_ref_type';

    #[Col('varchar', 255, comment: '原始 ref')]
    public const schema_fields_GIT_REF = 'git_ref';

    #[Col('varchar', 64, comment: 'Git commit SHA')]
    public const schema_fields_GIT_COMMIT = 'git_commit';

    #[Col('varchar', 64, comment: 'Git 分支')]
    public const schema_fields_GIT_BRANCH = 'git_branch';

    #[Col('varchar', 128, nullable: true, comment: 'Git tag（仅 tag 发布）')]
    public const schema_fields_GIT_TAG = 'git_tag';

    #[Col('varchar', 32, nullable: false, default: 'cli', comment: '触发方式：webhook / cli / manual')]
    public const schema_fields_TRIGGER_TYPE = 'trigger_type';

    #[Col('varchar', 255, nullable: true, comment: '触发来源（webhook ref / 操作人）')]
    public const schema_fields_TRIGGER_REF = 'trigger_ref';

    #[Col('varchar', 16, nullable: false, default: 'pending', comment: '状态：pending / running / success / failed')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 11, comment: '开始时间戳')]
    public const schema_fields_STARTED_AT = 'started_at';

    #[Col('int', 11, nullable: true, comment: '完成时间戳')]
    public const schema_fields_FINISHED_AT = 'finished_at';

    #[Col('int', 11, nullable: true, comment: '耗时（毫秒）')]
    public const schema_fields_DURATION_MS = 'duration_ms';

    #[Col('text', nullable: true, comment: '错误信息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';

    #[Col('text', nullable: true, comment: '输出尾部')]
    public const schema_fields_OUTPUT_TAIL = 'output_tail';

    #[Col('smallint', 1, default: 0, comment: '是否当前生效')]
    public const schema_fields_IS_CURRENT = 'is_current';
}
