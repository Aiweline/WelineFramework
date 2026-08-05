<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'SEO 自动优化站点检测父任务')]
#[Index(name: 'uniq_request_key', columns: ['request_key'], type: 'UNIQUE')]
#[Index(name: 'idx_website_state', columns: ['website_id', 'lifecycle_state', 'phase'])]
#[Index(name: 'idx_last_activity', columns: ['last_activity_at'])]
class SeoOptimizationCycle extends Model
{
    public const schema_table = 'weline_seo_optimization_cycle';
    public const schema_primary_key = 'cycle_id';

    public const LIFECYCLE_WAITING = 'waiting';
    public const LIFECYCLE_RUNNING = 'running';
    public const LIFECYCLE_TERMINAL = 'terminal';

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true)] public const schema_fields_ID = 'cycle_id';
    #[Col('int', 0, nullable: false)] public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 160, nullable: false)] public const schema_fields_REQUEST_KEY = 'request_key';
    #[Col('varchar', 32, nullable: false, default: 'scheduler')] public const schema_fields_TRIGGER_SOURCE = 'trigger_source';
    #[Col('varchar', 16, nullable: false, default: self::LIFECYCLE_WAITING)] public const schema_fields_LIFECYCLE_STATE = 'lifecycle_state';
    #[Col('varchar', 24, nullable: false, default: 'scheduled')] public const schema_fields_PHASE = 'phase';
    #[Col('varchar', 32)] public const schema_fields_OUTCOME = 'outcome';
    #[Col('int', 0)] public const schema_fields_TARGET_TOTAL = 'target_total';
    #[Col('int', 0, nullable: false, default: 0)] public const schema_fields_COMPLETED_COUNT = 'completed_count';
    #[Col('int', 0, nullable: false, default: 0)] public const schema_fields_ISSUE_COUNT = 'issue_count';
    #[Col('int', 0, nullable: false, default: 0)] public const schema_fields_FAILURE_COUNT = 'failure_count';
    #[Col('bigint', 0)] public const schema_fields_ANALYZE_QUEUE_ID = 'analyze_queue_id';
    #[Col('datetime')] public const schema_fields_SCHEDULED_AT = 'scheduled_at';
    #[Col('datetime')] public const schema_fields_STARTED_AT = 'started_at';
    #[Col('datetime')] public const schema_fields_LAST_ACTIVITY_AT = 'last_activity_at';
    #[Col('datetime')] public const schema_fields_FINISHED_AT = 'finished_at';
    #[Col('datetime')] public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime')] public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        parent::save_before();
        $now = \gmdate('Y-m-d H:i:s');
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
