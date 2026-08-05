<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '框架测试运行记录')]
#[Index(name: 'idx_test_run_status', columns: ['status', 'created_at'])]
#[Index(name: 'idx_test_run_module', columns: ['module', 'type'])]
#[Index(name: 'idx_test_run_task', columns: ['task_id'])]
class TestRun extends Model
{
    public const schema_table = 'weline_framework_test_run';
    public const schema_primary_key = 'run_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ERROR = 'error';

    public const TYPE_E2E = 'e2e';
    public const TYPE_UNIT = 'unit';
    public const TYPE_INTEGRATION = 'integration';

    #[Col(type: 'bigint', length: 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Run ID')]
    public const schema_fields_ID = 'run_id';

    #[Col(type: 'varchar', length: 128, nullable: false, default: '', comment: '目标模块')]
    public const schema_fields_MODULE = 'module';

    #[Col(type: 'varchar', length: 32, nullable: false, default: 'e2e', comment: '测试类型 e2e|unit|integration')]
    public const schema_fields_TYPE = 'type';

    #[Col(type: 'tinyint', length: 1, nullable: false, default: 1, comment: '是否启用 Playwright UI（headed）')]
    public const schema_fields_UI_ENABLED = 'ui_enabled';

    #[Col(type: 'varchar', length: 24, nullable: false, default: 'pending', comment: '运行状态')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'bigint', length: 20, nullable: false, default: 0, comment: '关联队列任务 ID')]
    public const schema_fields_TASK_ID = 'task_id';

    #[Col(type: 'int', length: 11, nullable: true, comment: '进程退出码')]
    public const schema_fields_EXIT_CODE = 'exit_code';

    #[Col(type: 'longtext', nullable: true, comment: '进度 JSON')]
    public const schema_fields_PROGRESS_JSON = 'progress_json';

    #[Col(type: 'longtext', nullable: true, comment: '运行日志')]
    public const schema_fields_LOG = 'log';

    #[Col(type: 'longtext', nullable: true, comment: '选中的用例文件 JSON')]
    public const schema_fields_FILES_JSON = 'files_json';

    #[Col(type: 'varchar', length: 512, nullable: false, default: '', comment: '报告路径')]
    public const schema_fields_REPORT_PATH = 'report_path';

    #[Col(type: 'varchar', length: 512, nullable: false, default: '', comment: '错误摘要')]
    public const schema_fields_ERROR_SUMMARY = 'error_summary';

    #[Col(type: 'datetime', nullable: true, comment: '开始时间')]
    public const schema_fields_STARTED_AT = 'started_at';

    #[Col(type: 'datetime', nullable: true, comment: '结束时间')]
    public const schema_fields_FINISHED_AT = 'finished_at';

    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        parent::save_before();
    }
}
