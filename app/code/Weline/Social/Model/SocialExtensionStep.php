<?php

declare(strict_types=1);

namespace Weline\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Social extension step')]
#[Index(name: 'idx_social_ext_step_job', columns: ['job_id', 'step_no'])]
class SocialExtensionStep extends Model
{
    public const schema_table = 'weline_social_extension_step';
    public const schema_primary_key = 'step_id';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Step ID')]
    public const schema_fields_ID = 'step_id';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Job ID')]
    public const schema_fields_JOB_ID = 'job_id';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Step number')]
    public const schema_fields_STEP_NO = 'step_no';
    #[Col('varchar', 32, nullable: false, default: '', comment: 'Action')]
    public const schema_fields_ACTION = 'action';
    #[Col('varchar', 64, nullable: false, default: '', comment: 'Snapshot hash')]
    public const schema_fields_SNAPSHOT_HASH = 'snapshot_hash';
    #[Col('text', nullable: true, comment: 'Decision JSON')]
    public const schema_fields_DECISION_JSON = 'decision_json';
    #[Col('varchar', 20, nullable: false, default: '', comment: 'Step result')]
    public const schema_fields_RESULT = 'result';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Duration in milliseconds')]
    public const schema_fields_DURATION_MS = 'duration_ms';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['step_id'];
    public array $_index_sort_keys = ['job_id'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
