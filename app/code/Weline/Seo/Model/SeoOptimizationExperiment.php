<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'SEO自动优化连续窗口实验')]
#[Index(name: 'uniq_experiment_key', columns: ['experiment_key'], type: 'UNIQUE')]
#[Index(name: 'idx_owner_status', columns: ['website_id', 'page_type', 'block_key', 'status'])]
#[Index(name: 'idx_evaluate_after', columns: ['evaluate_after'])]
class SeoOptimizationExperiment extends Model
{
    public const schema_table = 'weline_seo_optimization_experiment';
    public const schema_primary_key = 'experiment_id';
    public const STATUS_PUBLISH_PENDING = 'publish_pending';
    public const STATUS_FINALIZE_PENDING = 'finalize_pending';
    public const STATUS_ROLLBACK_PENDING = 'rollback_pending';
    public const STATUS_EVALUATING = 'evaluating';
    public const STATUS_KEPT = 'kept';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_MANUAL_INTERVENTION = 'manual_intervention';

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true)] public const schema_fields_ID = 'experiment_id';
    #[Col('varchar', 96, nullable: false)] public const schema_fields_EXPERIMENT_KEY = 'experiment_key';
    #[Col('bigint', 0, nullable: false)] public const schema_fields_RUN_ID = 'run_id';
    #[Col('int', 0, nullable: false)] public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 64, nullable: false)] public const schema_fields_ADAPTER = 'adapter';
    #[Col('varchar', 64, nullable: false)] public const schema_fields_PAGE_TYPE = 'page_type';
    #[Col('varchar', 128)] public const schema_fields_BLOCK_KEY = 'block_key';
    #[Col('int', 0, nullable: false)] public const schema_fields_BASE_REVISION = 'base_revision';
    #[Col('int', 0, nullable: false)] public const schema_fields_CANDIDATE_REVISION = 'candidate_revision';
    #[Col('varchar', 64, nullable: false)] public const schema_fields_BASE_FINGERPRINT = 'base_fingerprint';
    #[Col('varchar', 64, nullable: false)] public const schema_fields_CANDIDATE_FINGERPRINT = 'candidate_fingerprint';
    #[Col('varchar', 128, nullable: false)] public const schema_fields_PRIMARY_METRIC = 'primary_metric';
    #[Col('longtext')] public const schema_fields_GUARDRAILS_JSON = 'guardrails_json';
    #[Col('longtext')] public const schema_fields_BASELINE_METRICS_JSON = 'baseline_metrics_json';
    #[Col('longtext')] public const schema_fields_CANDIDATE_METRICS_JSON = 'candidate_metrics_json';
    #[Col('varchar', 32, nullable: false, default: self::STATUS_EVALUATING)] public const schema_fields_STATUS = 'status';
    #[Col('varchar', 24, nullable: false, default: 'shadow')] public const schema_fields_AUTOMATION_MODE = 'automation_mode';
    #[Col('datetime', nullable: false)] public const schema_fields_APPLIED_AT = 'applied_at';
    #[Col('datetime', nullable: false)] public const schema_fields_EVALUATE_AFTER = 'evaluate_after';
    #[Col('datetime', nullable: false)] public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('datetime')] public const schema_fields_RESOLVED_AT = 'resolved_at';
    #[Col('datetime')] public const schema_fields_COOLDOWN_UNTIL = 'cooldown_until';
    #[Col('datetime')] public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime')] public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        parent::save_before();
        $now = \date('Y-m-d H:i:s');
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
