<?php
declare(strict_types=1);

namespace Weline\Bot\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Tool 调用记录模型
 *
 * 记录 AI 调用技能的详细信息，用于审计和分析
 */
#[Table(comment: 'Tool调用记录表')]
#[Index(name: 'idx_session_id', columns: ['session_id'])]
#[Index(name: 'idx_skill_code', columns: ['skill_code'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class BotToolCall extends Model
{
    public const schema_table = 'weline_bot_tool_call';
    public const schema_primary_key = 'call_id';

    public array $_unit_primary_keys = ['call_id'];
    public array $_index_sort_keys = ['call_id', 'session_id', 'created_at'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '调用ID')]
    public const schema_fields_CALL_ID = 'call_id';

    #[Col('int', 11, nullable: false, comment: '会话ID')]
    public const schema_fields_SESSION_ID = 'session_id';

    #[Col('int', 11, comment: '消息ID')]
    public const schema_fields_MESSAGE_ID = 'message_id';

    #[Col('varchar', 100, nullable: false, comment: '技能代码')]
    public const schema_fields_SKILL_CODE = 'skill_code';

    #[Col('varchar', 255, comment: 'Tool调用ID（OpenAI格式）')]
    public const schema_fields_TOOL_CALL_ID = 'tool_call_id';

    #[Col('text', comment: '调用参数（JSON）')]
    public const schema_fields_ARGUMENTS = 'arguments';

    #[Col('varchar', 50, nullable: false, comment: '执行状态：pending/running/success/failed/timeout/cancelled')]
    public const schema_fields_STATUS = 'status';

    #[Col('text', comment: '执行结果')]
    public const schema_fields_RESULT = 'result';

    #[Col('text', comment: '错误信息')]
    public const schema_fields_ERROR = 'error';

    #[Col('int', comment: '执行时长（毫秒）')]
    public const schema_fields_DURATION_MS = 'duration_ms';

    #[Col('int', 1, default: 0, comment: '是否需要确认')]
    public const schema_fields_REQUIRES_CONFIRMATION = 'requires_confirmation';

    #[Col('int', 1, default: 0, comment: '用户已确认')]
    public const schema_fields_USER_CONFIRMED = 'user_confirmed';

    #[Col('int', comment: '开始时间')]
    public const schema_fields_STARTED_AT = 'started_at';

    #[Col('int', comment: '结束时间')]
    public const schema_fields_FINISHED_AT = 'finished_at';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_CANCELLED = 'cancelled';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_CALL_ID;
    }

    /**
     * 获取调用参数
     */
    public function getArguments(): array
    {
        $args = $this->getData(self::schema_fields_ARGUMENTS);
        if (is_string($args)) {
            $decoded = json_decode($args, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($args) ? $args : [];
    }

    /**
     * 设置调用参数
     */
    public function setArguments(array $args): self
    {
        return $this->setData(self::schema_fields_ARGUMENTS, json_encode($args, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 标记开始执行
     */
    public function markRunning(): self
    {
        $this->setData(self::schema_fields_STATUS, self::STATUS_RUNNING);
        $this->setData(self::schema_fields_STARTED_AT, time());
        return $this;
    }

    /**
     * 标记成功
     */
    public function markSuccess(string $result): self
    {
        $this->setData(self::schema_fields_STATUS, self::STATUS_SUCCESS);
        $this->setData(self::schema_fields_RESULT, $result);
        $this->setData(self::schema_fields_FINISHED_AT, time());
        $this->calculateDuration();
        return $this;
    }

    /**
     * 标记失败
     */
    public function markFailed(string $error): self
    {
        $this->setData(self::schema_fields_STATUS, self::STATUS_FAILED);
        $this->setData(self::schema_fields_ERROR, $error);
        $this->setData(self::schema_fields_FINISHED_AT, time());
        $this->calculateDuration();
        return $this;
    }

    /**
     * 计算执行时长
     */
    private function calculateDuration(): void
    {
        $started = (int) $this->getData(self::schema_fields_STARTED_AT);
        $finished = (int) $this->getData(self::schema_fields_FINISHED_AT);
        if ($finished > 0 && $started > 0) {
            $this->setData(self::schema_fields_DURATION_MS, ($finished - $started) * 1000);
        }
    }

    /**
     * 是否待处理
     */
    public function isPending(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_PENDING;
    }

    /**
     * 是否需要用户确认
     */
    public function needsConfirmation(): bool
    {
        return (bool) $this->getData(self::schema_fields_REQUIRES_CONFIRMATION)
            && !(bool) $this->getData(self::schema_fields_USER_CONFIRMED);
    }

    public function beforeSave(): self
    {
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, time());
            if (!$this->getData(self::schema_fields_STATUS)) {
                $this->setData(self::schema_fields_STATUS, self::STATUS_PENDING);
            }
        }

        if (is_array($this->getData(self::schema_fields_ARGUMENTS))) {
            $this->setData(self::schema_fields_ARGUMENTS, json_encode(
                $this->getData(self::schema_fields_ARGUMENTS),
                JSON_UNESCAPED_UNICODE
            ));
        }

        return parent::beforeSave();
    }
}
