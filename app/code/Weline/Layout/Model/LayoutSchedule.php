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

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class LayoutSchedule extends Model
{
    public const indexer = 'weline_layout_schedule';
    public const fields_ID = 'schedule_id';
    public const fields_LAYOUT_ID = 'layout_id';
    public const fields_MODULE_CODE = 'module_code';
    public const fields_LAYOUT_TYPE = 'layout_type';
    public const fields_START_TIME = 'start_time';
    public const fields_END_TIME = 'end_time';
    public const fields_IS_RECURRING = 'is_recurring';
    public const fields_CRON_EXPRESSION = 'cron_expression';
    public const fields_STATUS = 'status';
    public const fields_DESCRIPTION = 'description';

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('布局计划表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                '计划ID'
            )
            ->addColumn(
                self::fields_LAYOUT_ID,
                TableInterface::column_type_INTEGER,
                11,
                'not null',
                '布局ID'
            )
            ->addColumn(
                self::fields_MODULE_CODE,
                TableInterface::column_type_VARCHAR,
                128,
                'not null',
                '模块代码'
            )
            ->addColumn(
                self::fields_LAYOUT_TYPE,
                TableInterface::column_type_VARCHAR,
                64,
                'not null',
                '布局类型'
            )
            ->addColumn(
                self::fields_START_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null',
                '开始时间'
            )
            ->addColumn(
                self::fields_END_TIME,
                TableInterface::column_type_DATETIME,
                0,
                '',
                '结束时间'
            )
            ->addColumn(
                self::fields_IS_RECURRING,
                TableInterface::column_type_INTEGER,
                1,
                'default 0',
                '是否循环执行'
            )
            ->addColumn(
                self::fields_CRON_EXPRESSION,
                TableInterface::column_type_VARCHAR,
                128,
                "default ''",
                'Cron表达式（用于循环任务）'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                "default 'pending'",
                '状态：pending/active/completed/cancelled'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                TableInterface::column_type_TEXT,
                0,
                '',
                '描述'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_layout_id',
                self::fields_LAYOUT_ID,
                '布局ID索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_module_code',
                self::fields_MODULE_CODE,
                '模块代码索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_status',
                self::fields_STATUS,
                '状态索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_start_time',
                self::fields_START_TIME,
                '开始时间索引'
            )
            ->create();
    }

    // ===== Getters and Setters =====

    public function getLayoutId(): int
    {
        return (int)$this->getData(self::fields_LAYOUT_ID);
    }

    public function setLayoutId(int $layoutId): static
    {
        return $this->setData(self::fields_LAYOUT_ID, $layoutId);
    }

    public function getModuleCode(): string
    {
        return (string)$this->getData(self::fields_MODULE_CODE);
    }

    public function setModuleCode(string $moduleCode): static
    {
        return $this->setData(self::fields_MODULE_CODE, $moduleCode);
    }

    public function getLayoutType(): string
    {
        return (string)$this->getData(self::fields_LAYOUT_TYPE);
    }

    public function setLayoutType(string $layoutType): static
    {
        return $this->setData(self::fields_LAYOUT_TYPE, $layoutType);
    }

    public function getStartTime(): string
    {
        return (string)$this->getData(self::fields_START_TIME);
    }

    public function setStartTime(string $startTime): static
    {
        return $this->setData(self::fields_START_TIME, $startTime);
    }

    public function getEndTime(): ?string
    {
        $endTime = $this->getData(self::fields_END_TIME);
        return $endTime ? (string)$endTime : null;
    }

    public function setEndTime(?string $endTime): static
    {
        return $this->setData(self::fields_END_TIME, $endTime);
    }

    public function isRecurring(): bool
    {
        return (bool)$this->getData(self::fields_IS_RECURRING);
    }

    public function setIsRecurring(bool $isRecurring): static
    {
        return $this->setData(self::fields_IS_RECURRING, $isRecurring ? 1 : 0);
    }

    public function getCronExpression(): string
    {
        return (string)$this->getData(self::fields_CRON_EXPRESSION);
    }

    public function setCronExpression(string $cronExpression): static
    {
        return $this->setData(self::fields_CRON_EXPRESSION, $cronExpression);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::fields_STATUS);
    }

    public function setStatus(string $status): static
    {
        return $this->setData(self::fields_STATUS, $status);
    }

    public function getDescription(): string
    {
        return (string)$this->getData(self::fields_DESCRIPTION);
    }

    public function setDescription(string $description): static
    {
        return $this->setData(self::fields_DESCRIPTION, $description);
    }

    /**
     * 获取待执行的计划
     */
    public function getPendingSchedules(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->reset()
            ->where(self::fields_STATUS, self::STATUS_PENDING)
            ->where(self::fields_START_TIME, $now, '<=')
            ->order(self::fields_START_TIME, 'ASC')
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
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->where(self::fields_END_TIME, $now, '<=')
            ->where(self::fields_END_TIME, null, 'IS NOT NULL')
            ->order(self::fields_END_TIME, 'ASC')
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

