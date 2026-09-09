<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'SEO 正文近重复跑批')]
#[Index(name: 'idx_dup_run_website', columns: ['website_id'])]
#[Index(name: 'idx_dup_run_status', columns: ['status'])]
class SeoDupRun extends Model
{
    public const schema_table = 'weline_seo_dup_run';
    public const schema_primary_key = 'run_id';

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '跑批ID')]
    public const schema_fields_ID = 'run_id';
    #[Col('int', 0, nullable: false, default: 0, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('text', null, false, comment: '范围JSON')]
    public const schema_fields_SCOPE_JSON = 'scope_json';
    #[Col('varchar', 32, nullable: false, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', null, true, comment: '统计JSON')]
    public const schema_fields_STATS_JSON = 'stats_json';
    #[Col('int', 0, nullable: false, default: 0, comment: '问题数')]
    public const schema_fields_ISSUE_COUNT = 'issue_count';
    #[Col('varchar', 255, nullable: false, default: '', comment: '报告路径')]
    public const schema_fields_REPORT_PATH = 'report_path';
    #[Col('varchar', 512, nullable: false, default: '', comment: '报告绝对URL')]
    public const schema_fields_REPORT_URL = 'report_url';
    #[Col('datetime', comment: '开始时间')]
    public const schema_fields_STARTED_AT = 'started_at';
    #[Col('datetime', comment: '结束时间')]
    public const schema_fields_FINISHED_AT = 'finished_at';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function save_before(): void
    {
        parent::save_before();
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
        $this->setData(self::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
    }
}
