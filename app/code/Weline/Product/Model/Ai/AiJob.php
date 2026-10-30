<?php

declare(strict_types=1);

namespace Weline\Product\Model\Ai;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * One AI optimization run for one Product/locale/task combination.
 *
 * Durable state only: prompts and snapshots live in `params_json`, the summary
 * lives in `result_json`. Review decisions belong to {@see AiDraft}.
 */
#[Table(comment: 'Product AI optimization jobs')]
#[Index(name: 'idx_product_ai_job_product_time', columns: ['website_id', 'product_id', 'created_at'])]
#[Index(name: 'idx_product_ai_job_status_time', columns: ['status', 'created_at'])]
#[Index(name: 'idx_product_ai_job_batch', columns: ['batch_id'])]
#[Index(name: 'idx_product_ai_job_product_status', columns: ['global_product_uuid', 'status'])]
final class AiJob extends Model
{
    public const schema_table = 'weline_product_ai_job';
    public const schema_primary_key = 'job_id';

    public const TASK_COPY = 'copy';
    public const TASK_IMAGE = 'image';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    /** Running jobs are only flagged; S1 never interrupts a provider call. */
    public const STATUS_CANCEL_REQUESTED = 'cancel_requested';

    #[Col('varchar', 36, primaryKey: true, nullable: false, comment: 'Job UUID')]
    public const schema_fields_ID = 'job_id';

    #[Col('varchar', 36, nullable: true, comment: 'Batch UUID (S2 bulk)')]
    public const schema_fields_BATCH_ID = 'batch_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 36, nullable: false, comment: 'Global Product UUID')]
    public const schema_fields_PRODUCT_UUID = 'global_product_uuid';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Website-local Product ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';

    #[Col('varchar', 24, nullable: false, comment: 'copy / image')]
    public const schema_fields_TASK_TYPE = 'task_type';

    #[Col('varchar', 64, nullable: false, comment: 'AI scenario code')]
    public const schema_fields_SCENARIO_CODE = 'scenario_code';

    #[Col('varchar', 128, nullable: false, default: '', comment: 'Resolved AI model code')]
    public const schema_fields_MODEL_CODE = 'model_code';

    #[Col('varchar', 20, nullable: false, default: '', comment: 'Target locale')]
    public const schema_fields_LOCALE = 'locale';

    #[Col('varchar', 20, nullable: false, default: self::STATUS_QUEUED, comment: 'Job status')]
    public const schema_fields_STATUS = 'status';

    #[Col('text', nullable: true, comment: 'Frozen task params JSON (access context, scope, prompt input)')]
    public const schema_fields_PARAMS_JSON = 'params_json';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Snapshot hash for dedupe')]
    public const schema_fields_INPUT_HASH = 'input_hash';

    #[Col('mediumtext', nullable: true, comment: 'Result summary JSON')]
    public const schema_fields_RESULT_JSON = 'result_json';

    #[Col('varchar', 64, nullable: true, comment: 'Failure code')]
    public const schema_fields_ERROR_CODE = 'error_code';

    #[Col('text', nullable: true, comment: 'Failure message')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Requesting admin ID')]
    public const schema_fields_REQUESTED_BY = 'requested_by';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: true, comment: 'Started at')]
    public const schema_fields_STARTED_AT = 'started_at';

    #[Col('datetime', nullable: true, comment: 'Finished at')]
    public const schema_fields_FINISHED_AT = 'finished_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public static function isActiveStatus(string $status): bool
    {
        return \in_array($status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public static function isFinalStatus(string $status): bool
    {
        return \in_array(
            $status,
            [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED],
            true,
        );
    }
}
