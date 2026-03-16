<?php
declare(strict_types=1);

namespace Weline\Bot\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 机器人调度任务模型
 *
 * 基于 Cron 的自动化调度执行
 */
#[Table(comment: '机器人调度任务表')]
#[Index(name: 'idx_role_id', columns: ['role_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_next_run', columns: ['next_run_at'])]
class BotSchedule extends Model
{
    public const schema_table = 'weline_bot_schedule';
    public const schema_primary_key = 'schedule_id';

    public array $_unit_primary_keys = ['schedule_id'];
    public array $_index_sort_keys = ['schedule_id', 'role_id', 'next_run_at'];

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '调度ID')]
    public const schema_fields_SCHEDULE_ID = 'schedule_id';

    #[Col('int', 11, nullable: false, comment: '角色ID')]
    public const schema_fields_ROLE_ID = 'role_id';

    #[Col('varchar', 255, nullable: false, comment: '任务名称')]
    public const schema_fields_NAME = 'name';

    #[Col('text', comment: '任务描述')]
    public const schema_fields_DESCRIPTION = 'description';

    #[Col('varchar', 50, nullable: false, comment: '触发类型：cron')]
    public const schema_fields_TRIGGER_TYPE = 'trigger_type';

    #[Col('varchar', 255, comment: '触发表达式（cron表达式）')]
    public const schema_fields_TRIGGER_EXPR = 'trigger_expr';

    #[Col('text', nullable: false, comment: '执行提示词')]
    public const schema_fields_PROMPT = 'prompt';

    #[Col('text', comment: '执行上下文（JSON）')]
    public const schema_fields_CONTEXT = 'context';

    #[Col('varchar', 50, default: 'enabled', comment: '状态：enabled/disabled/paused')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', comment: '下次执行时间')]
    public const schema_fields_NEXT_RUN_AT = 'next_run_at';

    #[Col('int', comment: '上次执行时间')]
    public const schema_fields_LAST_RUN_AT = 'last_run_at';

    #[Col('int', 11, default: 0, comment: '执行次数')]
    public const schema_fields_RUN_COUNT = 'run_count';

    #[Col('int', 11, default: 0, comment: '失败次数')]
    public const schema_fields_FAIL_COUNT = 'fail_count';

    #[Col('int', 11, default: 3, comment: '最大重试次数')]
    public const schema_fields_MAX_RETRIES = 'max_retries';

    #[Col('int', 11, default: 0, comment: '当前重试次数')]
    public const schema_fields_RETRY_COUNT = 'retry_count';

    #[Col('int', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('int', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const TRIGGER_CRON = 'cron'; // Cron 表达式
    public const TRIGGER_NATURAL = 'natural'; // @deprecated 已弃用，仅用于兼容历史数据
    public const TRIGGER_EVENT = 'event'; // @deprecated 已弃用，仅用于兼容历史数据

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_PAUSED = 'paused';

    private ?BotRole $roleModel = null;

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_SCHEDULE_ID;
    }

    /**
     * 获取上下文
     */
    public function getContext(): array
    {
        $context = $this->getData(self::schema_fields_CONTEXT);
        if (is_string($context)) {
            $decoded = json_decode($context, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($context) ? $context : [];
    }

    /**
     * 设置上下文
     */
    public function setContext(array $context): self
    {
        return $this->setData(self::schema_fields_CONTEXT, json_encode($context, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取关联角色
     */
    public function getRole(): ?BotRole
    {
        if ($this->roleModel === null && $this->getData(self::schema_fields_ROLE_ID)) {
            $this->roleModel = (new BotRole())->load($this->getData(self::schema_fields_ROLE_ID));
        }
        return $this->roleModel;
    }

    /**
     * 是否启用
     */
    public function isEnabled(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_ENABLED;
    }

    /**
     * 是否到执行时间
     */
    public function isDue(): bool
    {
        $nextRun = (int) $this->getData(self::schema_fields_NEXT_RUN_AT);
        return $nextRun > 0 && time() >= $nextRun;
    }

    /**
     * 计算下次执行时间
     */
    public function calculateNextRun(): int
    {
        if ($this->getData(self::schema_fields_TRIGGER_TYPE) === self::TRIGGER_CRON) {
            $cronExpr = $this->getData(self::schema_fields_TRIGGER_EXPR);
            // 简化的 cron 解析，实际应使用成熟的 cron 库
            return $this->parseCronToTimestamp($cronExpr);
        }
        return 0;
    }

    /**
     * 简化的 Cron 解析（仅支持基本格式）
     */
    private function parseCronToTimestamp(string $cronExpr): int
    {
        // TODO: 集成成熟的 cron 解析库
        // 目前返回1小时后
        return time() + 3600;
    }

    /**
     * 记录执行
     */
    public function recordRun(bool $success = true): self
    {
        $this->setData(self::schema_fields_LAST_RUN_AT, time());
        $this->setData(self::schema_fields_RUN_COUNT, (int) $this->getData(self::schema_fields_RUN_COUNT) + 1);
        $this->setData(self::schema_fields_RETRY_COUNT, 0);

        if (!$success) {
            $this->setData(self::schema_fields_FAIL_COUNT, (int) $this->getData(self::schema_fields_FAIL_COUNT) + 1);
            $this->setData(self::schema_fields_RETRY_COUNT, (int) $this->getData(self::schema_fields_RETRY_COUNT) + 1);
        }

        // 计算下次执行时间
        $this->setData(self::schema_fields_NEXT_RUN_AT, $this->calculateNextRun());

        return $this;
    }

    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
            $this->setData(self::schema_fields_RUN_COUNT, 0);
            $this->setData(self::schema_fields_FAIL_COUNT, 0);
            $this->setData(self::schema_fields_RETRY_COUNT, 0);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);

        if (is_array($this->getData(self::schema_fields_CONTEXT))) {
            $this->setData(self::schema_fields_CONTEXT, json_encode(
                $this->getData(self::schema_fields_CONTEXT),
                JSON_UNESCAPED_UNICODE
            ));
        }

        return parent::beforeSave();
    }
}
