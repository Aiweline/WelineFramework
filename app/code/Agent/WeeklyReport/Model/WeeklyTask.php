<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 周报任务明细表模型
 */
class WeeklyTask extends Model
{
    public const table = 'agent_weekly_task';
    public string $_primary_key = 'task_id';

    public const fields_ID = 'task_id';
    public const fields_REPORT_ID = 'report_id';
    public const fields_CATEGORY = 'category';
    public const fields_TASK_NAME = 'task_name';
    public const fields_SUB_TASK = 'sub_task';
    public const fields_RELATED_DOC = 'related_doc';
    public const fields_START_DATE = 'start_date';
    public const fields_END_DATE = 'end_date';
    public const fields_STATUS = 'status';
    public const fields_PROGRESS = 'progress';
    public const fields_RISKS = 'risks';
    public const fields_NEXT_WEEK_PLAN = 'next_week_plan';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_PRIORITY = 'priority';
    public const fields_IS_IMPORTANT = 'is_important';
    public const fields_NOTIFIED_AT = 'notified_at';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    public const PRIORITY_LOW = 1;
    public const PRIORITY_NORMAL = 2;
    public const PRIORITY_HIGH = 3;
    public const PRIORITY_URGENT = 4;

    public const STATUS_TODO = '待开始';
    public const STATUS_IN_PROGRESS = '进行中';
    public const STATUS_TESTING = '测试中';
    public const STATUS_COMPLETED = '已完成';
    public const STATUS_UPGRADING = '升级中';

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('周报任务明细表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                null,
                'primary key auto_increment',
                '任务ID'
            )
            ->addColumn(
                self::fields_REPORT_ID,
                TableInterface::column_type_INTEGER,
                null,
                'not null',
                '关联周报ID'
            )
            ->addColumn(
                self::fields_CATEGORY,
                TableInterface::column_type_VARCHAR,
                100,
                'default null',
                '类别或团队'
            )
            ->addColumn(
                self::fields_TASK_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '主任务名称'
            )
            ->addColumn(
                self::fields_SUB_TASK,
                TableInterface::column_type_VARCHAR,
                500,
                'default null',
                '子任务描述'
            )
            ->addColumn(
                self::fields_RELATED_DOC,
                TableInterface::column_type_VARCHAR,
                500,
                'default null',
                '相关文档链接'
            )
            ->addColumn(
                self::fields_START_DATE,
                TableInterface::column_type_DATE,
                null,
                'default null',
                '开始时间'
            )
            ->addColumn(
                self::fields_END_DATE,
                TableInterface::column_type_DATE,
                null,
                'default null',
                '截止时间'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                50,
                'default null',
                '状态'
            )
            ->addColumn(
                self::fields_PROGRESS,
                TableInterface::column_type_TEXT,
                null,
                '',
                '本周进展'
            )
            ->addColumn(
                self::fields_RISKS,
                TableInterface::column_type_TEXT,
                null,
                '',
                '风险与解决方案'
            )
            ->addColumn(
                self::fields_NEXT_WEEK_PLAN,
                TableInterface::column_type_TEXT,
                null,
                '',
                '下周计划'
            )
            ->addColumn(
                self::fields_SORT_ORDER,
                TableInterface::column_type_INTEGER,
                null,
                'not null default 0',
                '排序'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                null,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                TableInterface::column_type_DATETIME,
                null,
                'not null default CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_report_id',
                [self::fields_REPORT_ID],
                '周报ID索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_status',
                [self::fields_STATUS],
                '状态索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_category',
                [self::fields_CATEGORY],
                '类别索引'
            )
            ->create();
    }

    /**
     * 初始化模型（必须实现）
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            return;
        }

        $alter = $setup->alterTable();
        $hasChanges = false;

        if (!$setup->hasField(self::fields_PRIORITY)) {
            $alter->addColumn(
                self::fields_PRIORITY,
                self::fields_SORT_ORDER,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 2',
                '紧急程度：1低 2普通 3高 4紧急'
            );
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_IS_IMPORTANT)) {
            $alter->addColumn(
                self::fields_IS_IMPORTANT,
                self::fields_PRIORITY,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '是否重点任务：0否 1是'
            );
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_NOTIFIED_AT)) {
            $alter->addColumn(
                self::fields_NOTIFIED_AT,
                self::fields_IS_IMPORTANT,
                TableInterface::column_type_DATETIME,
                0,
                'default null',
                '最后通知时间'
            );
            $hasChanges = true;
        }

        if ($hasChanges) {
            $alter->alter();
        }
    }

    /**
     * 获取状态选项
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_TODO => __('待开始'),
            self::STATUS_IN_PROGRESS => __('进行中'),
            self::STATUS_TESTING => __('测试中'),
            self::STATUS_COMPLETED => __('已完成'),
            self::STATUS_UPGRADING => __('升级中'),
        ];
    }

    /**
     * 获取紧急程度选项
     */
    public static function getPriorityOptions(): array
    {
        return [
            self::PRIORITY_LOW => ['name' => __('低'), 'color' => '90'],
            self::PRIORITY_NORMAL => ['name' => __('普通'), 'color' => '37'],
            self::PRIORITY_HIGH => ['name' => __('高'), 'color' => '33'],
            self::PRIORITY_URGENT => ['name' => __('紧急'), 'color' => '31'],
        ];
    }

    /**
     * 获取紧急程度名称
     */
    public function getPriorityName(): string
    {
        $priority = (int) $this->getData(self::fields_PRIORITY) ?: self::PRIORITY_NORMAL;
        $options = self::getPriorityOptions();
        return $options[$priority]['name'] ?? __('普通');
    }

    /**
     * 获取紧急程度 ANSI 颜色码
     */
    public function getPriorityColor(): string
    {
        $priority = (int) $this->getData(self::fields_PRIORITY) ?: self::PRIORITY_NORMAL;
        $options = self::getPriorityOptions();
        return $options[$priority]['color'] ?? '37';
    }

    /**
     * 是否重点任务
     */
    public function isImportant(): bool
    {
        return (bool) $this->getData(self::fields_IS_IMPORTANT);
    }

    /**
     * 获取周报的所有任务（按紧急程度+重点排序）
     */
    public function getTasksByReportId(int $reportId): array
    {
        return $this->where(self::fields_REPORT_ID, $reportId)
            ->order(self::fields_IS_IMPORTANT, 'DESC')
            ->order(self::fields_PRIORITY, 'DESC')
            ->order(self::fields_SORT_ORDER, 'ASC')
            ->order(self::fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 获取临期未完成的重点任务
     */
    public function getOverdueTasks(int $daysThreshold = 2): array
    {
        $thresholdDate = date('Y-m-d', strtotime("+{$daysThreshold} days"));
        
        return $this->where(self::fields_STATUS, self::STATUS_COMPLETED, '!=')
            ->where(self::fields_END_DATE, null, 'IS NOT')
            ->where(self::fields_END_DATE, $thresholdDate, '<=')
            ->where(function ($query) {
                $query->where(self::fields_IS_IMPORTANT, 1)
                    ->orWhere(self::fields_PRIORITY, self::PRIORITY_URGENT);
            })
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 添加任务
     */
    public function addTask(array $data): self
    {
        $this->setData($data);
        $this->save();
        return $this;
    }

    /**
     * 更新任务状态
     */
    public function updateTaskStatus(int $taskId, string $status): bool
    {
        $this->load($taskId);
        if (!$this->getId()) {
            return false;
        }

        $this->setData(self::fields_STATUS, $status);
        $this->save();
        return true;
    }

    /**
     * 更新任务进展
     */
    public function updateProgress(int $taskId, string $progress): bool
    {
        $this->load($taskId);
        if (!$this->getId()) {
            return false;
        }

        $this->setData(self::fields_PROGRESS, $progress);
        $this->save();
        return true;
    }

    /**
     * 删除任务
     */
    public function deleteTask(int $taskId): bool
    {
        $this->load($taskId);
        if (!$this->getId()) {
            return false;
        }

        $this->delete();
        return true;
    }
}
