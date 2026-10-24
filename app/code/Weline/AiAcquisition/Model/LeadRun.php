<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 获客智能体运行记录：票据、模型、提示词、检索/线索统计与成本台账。
 */
#[Table(comment: 'AI获客智能体运行')]
#[Index(name: 'idx_ai_acquisition_run_ticket', columns: ['ticket'], type: 'UNIQUE')]
#[Index(name: 'idx_ai_acquisition_run_status', columns: ['status'])]
#[Index(name: 'idx_ai_acquisition_run_model', columns: ['model_code'])]
#[Index(name: 'idx_ai_acquisition_run_created', columns: ['created_at'])]
class LeadRun extends Model
{
    public const schema_table = 'ai_acquisition_run';
    public const schema_primary_key = 'id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING => '待连接',
        self::STATUS_RUNNING => '执行中',
        self::STATUS_PAUSED => '已暂停',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_FAILED => '失败',
        self::STATUS_CANCELLED => '已取消',
    ];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', default: 0, comment: '归属网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 100, nullable: false, comment: '模型代码')]
    public const schema_fields_MODEL_CODE = 'model_code';
    #[Col('int', default: 0, comment: '提示词档案ID')]
    public const schema_fields_PROMPT_PROFILE_ID = 'prompt_profile_id';
    #[Col('text', nullable: true, comment: '运行提示词快照')]
    public const schema_fields_PROMPT_BODY = 'prompt_body';
    #[Col('varchar', 20, default: 'pending', comment: '运行状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('char', 64, nullable: false, comment: '浏览器桥票据')]
    public const schema_fields_TICKET = 'ticket';
    #[Col('int', default: 0, comment: '票据过期时间')]
    public const schema_fields_TICKET_EXPIRES_AT = 'ticket_expires_at';
    #[Col('int', default: 10, comment: '目标线索数')]
    public const schema_fields_TARGET_COUNT = 'target_count';
    #[Col('int', default: 8, comment: '最大轮次')]
    public const schema_fields_MAX_ITERATIONS = 'max_iterations';
    #[Col('int', default: 0, comment: '搜索次数')]
    public const schema_fields_SEARCHES_COUNT = 'searches_count';
    #[Col('int', default: 0, comment: '发现线索数')]
    public const schema_fields_LEADS_FOUND = 'leads_found';
    #[Col('int', default: 0, comment: '入库线索数')]
    public const schema_fields_LEADS_SAVED = 'leads_saved';
    #[Col('int', default: 0, comment: '输入token')]
    public const schema_fields_INPUT_TOKENS = 'input_tokens';
    #[Col('int', default: 0, comment: '输出token')]
    public const schema_fields_OUTPUT_TOKENS = 'output_tokens';
    #[Col('decimal', '12,6', default: 0, comment: '累计成本')]
    public const schema_fields_COST = 'cost';
    #[Col('varchar', 8, default: 'CNY', comment: '币种')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('text', nullable: true, comment: '错误信息')]
    public const schema_fields_ERROR = 'error';
    #[Col('text', nullable: true, comment: '运行元数据JSON')]
    public const schema_fields_META_JSON = 'meta_json';
    #[Col('int', default: 0, comment: '创建人')]
    public const schema_fields_CREATED_BY = 'created_by';
    #[Col('int', default: 0, comment: '开始时间')]
    public const schema_fields_STARTED_AT = 'started_at';
    #[Col('int', default: 0, comment: '结束时间')]
    public const schema_fields_FINISHED_AT = 'finished_at';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'status', 'created_at'];

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
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }

    public function getStatusLabel(): string
    {
        $status = (string)$this->getData(self::schema_fields_STATUS);
        return (string)(self::STATUSES[$status] ?? $status);
    }
}
