<?php
declare(strict_types=1);

namespace Weline\Bot\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 调度任务执行日志模型
 */
#[Table(comment: '调度任务执行日志表')]
#[Index(name: 'idx_schedule_id', columns: ['schedule_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class BotScheduleLog extends Model
{
    public const schema_table = 'weline_bot_schedule_log';
    public const schema_primary_key = 'log_id';

    public array $_unit_primary_keys = ['log_id'];
    public array $_index_sort_keys = ['log_id', 'schedule_id', 'created_at'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '日志ID')]
    public const schema_fields_LOG_ID = 'log_id';

    #[Col('int', 11, nullable: false, comment: '调度ID')]
    public const schema_fields_SCHEDULE_ID = 'schedule_id';

    #[Col('varchar', 50, nullable: false, comment: '执行状态：running/success/failed/timeout')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', comment: '开始时间')]
    public const schema_fields_STARTED_AT = 'started_at';

    #[Col('int', comment: '结束时间')]
    public const schema_fields_FINISHED_AT = 'finished_at';

    #[Col('int', comment: '执行时长（毫秒）')]
    public const schema_fields_DURATION_MS = 'duration_ms';

    #[Col('text', comment: '执行结果')]
    public const schema_fields_RESULT = 'result';

    #[Col('text', comment: '错误信息')]
    public const schema_fields_ERROR = 'error';

    #[Col('text', comment: '执行上下文快照（JSON）')]
    public const schema_fields_CONTEXT_SNAPSHOT = 'context_snapshot';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMEOUT = 'timeout';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_LOG_ID;
    }

    /**
     * 获取上下文快照
     */
    public function getContextSnapshot(): array
    {
        $snapshot = $this->getData(self::schema_fields_CONTEXT_SNAPSHOT);
        if (is_string($snapshot)) {
            $decoded = json_decode($snapshot, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * 计算执行时长
     */
    public function calculateDuration(): int
    {
        $started = (int) $this->getData(self::schema_fields_STARTED_AT);
        $finished = (int) $this->getData(self::schema_fields_FINISHED_AT);
        if ($finished > 0 && $started > 0) {
            return ($finished - $started) * 1000; // 转为毫秒
        }
        return 0;
    }

    /**
     * 标记成功
     */
    public function markSuccess(string $result): self
    {
        $this->setData(self::schema_fields_STATUS, self::STATUS_SUCCESS);
        $this->setData(self::schema_fields_FINISHED_AT, time());
        $this->setData(self::schema_fields_DURATION_MS, $this->calculateDuration());
        $this->setData(self::schema_fields_RESULT, $result);
        return $this;
    }

    /**
     * 标记失败
     */
    public function markFailed(string $error): self
    {
        $this->setData(self::schema_fields_STATUS, self::STATUS_FAILED);
        $this->setData(self::schema_fields_FINISHED_AT, time());
        $this->setData(self::schema_fields_DURATION_MS, $this->calculateDuration());
        $this->setData(self::schema_fields_ERROR, $error);
        return $this;
    }

    public function beforeSave(): self
    {
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, time());
        }

        if (is_array($this->getData(self::schema_fields_CONTEXT_SNAPSHOT))) {
            $this->setData(self::schema_fields_CONTEXT_SNAPSHOT, json_encode(
                $this->getData(self::schema_fields_CONTEXT_SNAPSHOT),
                JSON_UNESCAPED_UNICODE
            ));
        }

        return parent::beforeSave();
    }
}
