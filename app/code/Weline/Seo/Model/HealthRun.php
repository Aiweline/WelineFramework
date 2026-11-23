<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 一次健康探测跑批（cron 或手动）。
 */
#[Table(comment: 'SEO URL 健康探测跑批')]
#[Index(name: 'idx_health_run_website_started', columns: ['website_id', 'started_at'], comment: '按站点查跑批')]
#[Index(name: 'idx_health_run_status', columns: ['status'], comment: '按状态查')]
class HealthRun extends Model
{
    public const schema_table = 'weline_seo_health_run';
    public const schema_primary_key = 'run_id';
    public array $_unit_primary_keys = ['run_id'];
    public array $_index_sort_keys = ['run_id', 'website_id', 'status'];

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const TRIGGER_CRON = 'cron';
    public const TRIGGER_MANUAL = 'manual';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'run_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'cron', comment: '触发方式')]
    public const schema_fields_TRIGGER_TYPE = 'trigger_type';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'running', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'int', nullable: false, default: 0, comment: '探测数')]
    public const schema_fields_PROBED = 'probed';
    #[Col(type: 'int', nullable: false, default: 0, comment: '正常数')]
    public const schema_fields_OK_COUNT = 'ok_count';
    #[Col(type: 'int', nullable: false, default: 0, comment: '问题数')]
    public const schema_fields_ISSUE_COUNT = 'issue_count';
    #[Col(type: 'text', nullable: true, comment: '按问题计数 JSON')]
    public const schema_fields_BY_ISSUE = 'by_issue';
    #[Col(type: 'int', nullable: false, default: 0, comment: '本轮生效动作数')]
    public const schema_fields_ACTIONS_APPLIED = 'actions_applied';
    #[Col(type: 'int', nullable: false, default: 0, comment: '预算耗时 ms')]
    public const schema_fields_BUDGET_MS = 'budget_ms';
    #[Col(type: 'int', nullable: false, default: 0, comment: '实际耗时 ms')]
    public const schema_fields_DURATION_MS = 'duration_ms';
    #[Col(type: 'text', nullable: true, comment: '错误信息')]
    public const schema_fields_ERROR = 'error';
    #[Col(type: 'datetime', nullable: true, comment: '开始时间')]
    public const schema_fields_STARTED_AT = 'started_at';
    #[Col(type: 'datetime', nullable: true, comment: '结束时间')]
    public const schema_fields_FINISHED_AT = 'finished_at';
    #[Col(type: 'datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function save_before(): void
    {
        parent::save_before();

        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
        $this->setData(self::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
    }

    public function getByIssue(): array
    {
        $raw = $this->getData(self::schema_fields_BY_ISSUE);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function setByIssue(array $counts): static
    {
        ksort($counts);

        return $this->setData(
            self::schema_fields_BY_ISSUE,
            json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
