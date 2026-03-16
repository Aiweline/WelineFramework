<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：布局计划模型 - 用于定时布局切换
 */

namespace Weline\Layout\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '布局计划表')]
#[Index(name: 'idx_layout_id', columns: ['layout_id'], comment: '布局ID索引')]
#[Index(name: 'idx_module_code', columns: ['module_code'], comment: '模块代码索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_start_time', columns: ['start_time'], comment: '开始时间索引')]
class LayoutSchedule extends Model
{

    public const schema_table = 'weline_layout_schedule';
    public const schema_primary_key = 'schedule_id';
    public const indexer = 'weline_layout_schedule';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '计划ID')]
    public const schema_fields_ID = 'schedule_id';
    #[Col('int', nullable: false, comment: '布局ID')]
    public const schema_fields_LAYOUT_ID = 'layout_id';
    #[Col('varchar', 128, nullable: false, comment: '模块代码')]
    public const schema_fields_MODULE_CODE = 'module_code';
    #[Col('varchar', 64, nullable: false, comment: '布局类型')]
    public const schema_fields_LAYOUT_TYPE = 'layout_type';
    #[Col('datetime', nullable: false, comment: '开始时间')]
    public const schema_fields_START_TIME = 'start_time';
    #[Col('datetime', comment: '结束时间')]
    public const schema_fields_END_TIME = 'end_time';
    #[Col('int', 1, default: 0, comment: '是否循环执行')]
    public const schema_fields_IS_RECURRING = 'is_recurring';
    #[Col('varchar', 128, default: '', comment: 'Cron表达式')]
    public const schema_fields_CRON_EXPRESSION = 'cron_expression';
    #[Col('varchar', 20, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
// ===== Getters and Setters =====

    public function getLayoutId(): int
    {
        return (int)$this->getData(self::schema_fields_LAYOUT_ID);
    }

    public function setLayoutId(int $layoutId): static
    {
        return $this->setData(self::schema_fields_LAYOUT_ID, $layoutId);
    }

    public function getModuleCode(): string
    {
        return (string)$this->getData(self::schema_fields_MODULE_CODE);
    }

    public function setModuleCode(string $moduleCode): static
    {
        return $this->setData(self::schema_fields_MODULE_CODE, $moduleCode);
    }

    public function getLayoutType(): string
    {
        return (string)$this->getData(self::schema_fields_LAYOUT_TYPE);
    }

    public function setLayoutType(string $layoutType): static
    {
        return $this->setData(self::schema_fields_LAYOUT_TYPE, $layoutType);
    }

    public function getStartTime(): string
    {
        return (string)$this->getData(self::schema_fields_START_TIME);
    }

    public function setStartTime(string $startTime): static
    {
        return $this->setData(self::schema_fields_START_TIME, $startTime);
    }

    public function getEndTime(): ?string
    {
        $endTime = $this->getData(self::schema_fields_END_TIME);
        return $endTime ? (string)$endTime : null;
    }

    public function setEndTime(?string $endTime): static
    {
        return $this->setData(self::schema_fields_END_TIME, $endTime);
    }

    public function isRecurring(): bool
    {
        return (bool)$this->getData(self::schema_fields_IS_RECURRING);
    }

    public function setIsRecurring(bool $isRecurring): static
    {
        return $this->setData(self::schema_fields_IS_RECURRING, $isRecurring ? 1 : 0);
    }

    public function getCronExpression(): string
    {
        return (string)$this->getData(self::schema_fields_CRON_EXPRESSION);
    }

    public function setCronExpression(string $cronExpression): static
    {
        return $this->setData(self::schema_fields_CRON_EXPRESSION, $cronExpression);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::schema_fields_STATUS);
    }

    public function setStatus(string $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function getDescription(): string
    {
        return (string)$this->getData(self::schema_fields_DESCRIPTION);
    }

    public function setDescription(string $description): static
    {
        return $this->setData(self::schema_fields_DESCRIPTION, $description);
    }

    /**
     * 获取待执行的计划
     */
    public function getPendingSchedules(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->reset()
            ->where(self::schema_fields_STATUS, self::STATUS_PENDING)
            ->where(self::schema_fields_START_TIME, $now, '<=')
            ->order(self::schema_fields_START_TIME, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 获取需要结束的活动计划
     */
    public function getExpiredActiveSchedules(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->reset()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->where(self::schema_fields_END_TIME, $now, '<=')
            ->where(self::schema_fields_END_TIME, '', '!=')
            ->order(self::schema_fields_END_TIME, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 获取关联的布局模型
     */
    public function getLayout(): ?Layout
    {
        $layoutId = $this->getLayoutId();
        if ($layoutId <= 0) {
            return null;
        }
        /** @var Layout $layout */
        $layout = ObjectManager::getInstance(Layout::class);
        $layout->load($layoutId);
        return $layout->getId() ? $layout : null;
    }
}


