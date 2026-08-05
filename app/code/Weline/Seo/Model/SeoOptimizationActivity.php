<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'SEO 自动优化追加式实时审计')]
#[Index(name: 'uniq_idempotency_key', columns: ['idempotency_key'], type: 'UNIQUE')]
#[Index(name: 'idx_website_cursor', columns: ['website_id', 'activity_id'])]
#[Index(name: 'idx_cycle_cursor', columns: ['cycle_id', 'activity_id'])]
#[Index(name: 'idx_run_cursor', columns: ['run_id', 'activity_id'])]
#[Index(name: 'idx_expires_at', columns: ['expires_at'])]
class SeoOptimizationActivity extends Model
{
    public const schema_table = 'weline_seo_optimization_activity';
    public const schema_primary_key = 'activity_id';

    public const DURABILITY_CORE = 'core';
    public const DURABILITY_PROGRESS = 'progress';

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true)] public const schema_fields_ID = 'activity_id';
    #[Col('int', 0, nullable: false)] public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('bigint', 0)] public const schema_fields_CYCLE_ID = 'cycle_id';
    #[Col('bigint', 0)] public const schema_fields_RUN_ID = 'run_id';
    #[Col('bigint', 0)] public const schema_fields_EXPERIMENT_ID = 'experiment_id';
    #[Col('bigint', 0)] public const schema_fields_QUEUE_ID = 'queue_id';
    #[Col('varchar', 64)] public const schema_fields_PAGE_TYPE = 'page_type';
    #[Col('varchar', 128)] public const schema_fields_BLOCK_KEY = 'block_key';
    #[Col('varchar', 24, nullable: false)] public const schema_fields_PHASE = 'phase';
    #[Col('varchar', 64, nullable: false)] public const schema_fields_EVENT_TYPE = 'event_type';
    #[Col('varchar', 16, nullable: false, default: 'info')] public const schema_fields_SEVERITY = 'severity';
    #[Col('varchar', 96, nullable: false)] public const schema_fields_MESSAGE_CODE = 'message_code';
    #[Col('varchar', 500, nullable: false)] public const schema_fields_MESSAGE_TEXT = 'message_text';
    #[Col('longtext')] public const schema_fields_FACTS_JSON = 'facts_json';
    #[Col('varchar', 160, nullable: false)] public const schema_fields_IDEMPOTENCY_KEY = 'idempotency_key';
    #[Col('varchar', 16, nullable: false, default: self::DURABILITY_CORE)] public const schema_fields_DURABILITY = 'durability';
    #[Col('datetime', nullable: false)] public const schema_fields_OCCURRED_AT = 'occurred_at';
    #[Col('datetime', nullable: false)] public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col('datetime')] public const schema_fields_CREATED_AT = 'created_at';

    public function save_before(): void
    {
        parent::save_before();
        if (!$this->getData(self::schema_fields_OCCURRED_AT)) {
            $this->setData(self::schema_fields_OCCURRED_AT, \gmdate('Y-m-d H:i:s'));
        }
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, \gmdate('Y-m-d H:i:s'));
        }
    }
}
