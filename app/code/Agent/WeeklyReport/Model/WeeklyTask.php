<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 周报任务明细表模型
 */
#[Table(comment: '周报任务明细表')]
#[Index(name: 'idx_report_id', columns: ['report_id'], comment: '周报ID索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_category', columns: ['category'], comment: '类别索引')]
class WeeklyTask extends Model
{
    public const schema_table = 'agent_weekly_task';
    public const schema_primary_key = 'task_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '任务ID')]
    public const schema_fields_ID = 'task_id';
    #[Col(type: 'int', nullable: false, comment: '关联周报ID')]
    public const schema_fields_REPORT_ID = 'report_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '类别或团队')]
    public const schema_fields_CATEGORY = 'category';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '主任务名称')]
    public const schema_fields_TASK_NAME = 'task_name';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '子任务描述')]
    public const schema_fields_SUB_TASK = 'sub_task';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '相关文档链接')]
    public const schema_fields_RELATED_DOC = 'related_doc';
    #[Col(type: 'date', nullable: true, comment: '开始时间')]
    public const schema_fields_START_DATE = 'start_date';
    #[Col(type: 'date', nullable: true, comment: '截止时间')]
    public const schema_fields_END_DATE = 'end_date';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'text', nullable: true, comment: '本周进展')]
    public const schema_fields_PROGRESS = 'progress';
    #[Col(type: 'text', nullable: true, comment: '风险与解决方案')]
    public const schema_fields_RISKS = 'risks';
    #[Col(type: 'text', nullable: true, comment: '下周计划')]
    public const schema_fields_NEXT_WEEK_PLAN = 'next_week_plan';
    #[Col(type: 'int', nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col(type: 'int', nullable: false, default: 2, comment: '紧急程度：1低 2普通 3高 4紧急')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col(type: 'int', nullable: false, default: 0, comment: '是否重点任务：0否 1是')]
    public const schema_fields_IS_IMPORTANT = 'is_important';
    #[Col(type: 'datetime', nullable: true, comment: '最后通知时间')]
    public const schema_fields_NOTIFIED_AT = 'notified_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const PRIORITY_LOW = 1;
    public const PRIORITY_NORMAL = 2;
    public const PRIORITY_HIGH = 3;
    public const PRIORITY_URGENT = 4;

    public const STATUS_TODO = '待开始';
    public const STATUS_IN_PROGRESS = '进行中';
    public const STATUS_TESTING = '测试中';
    public const STATUS_COMPLETED = '已完成';
    public const STATUS_UPGRADING = '升级中';

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

    public static function getPriorityOptions(): array
    {
        return [
            self::PRIORITY_LOW => ['name' => __('低'), 'color' => '90'],
            self::PRIORITY_NORMAL => ['name' => __('普通'), 'color' => '37'],
            self::PRIORITY_HIGH => ['name' => __('高'), 'color' => '33'],
            self::PRIORITY_URGENT => ['name' => __('紧急'), 'color' => '31'],
        ];
    }

    public function getPriorityName(): string
    {
        $priority = (int) $this->getData(self::schema_fields_PRIORITY) ?: self::PRIORITY_NORMAL;
        $options = self::getPriorityOptions();
        return $options[$priority]['name'] ?? __('普通');
    }

    public function getPriorityColor(): string
    {
        $priority = (int) $this->getData(self::schema_fields_PRIORITY) ?: self::PRIORITY_NORMAL;
        $options = self::getPriorityOptions();
        return $options[$priority]['color'] ?? '37';
    }

    public function isImportant(): bool
    {
        return (bool) $this->getData(self::schema_fields_IS_IMPORTANT);
    }

    public function getTasksByReportId(int $reportId): array
    {
        return $this->where(self::schema_fields_REPORT_ID, $reportId)
            ->order(self::schema_fields_IS_IMPORTANT, 'DESC')
            ->order(self::schema_fields_PRIORITY, 'DESC')
            ->order(self::schema_fields_SORT_ORDER, 'ASC')
            ->order(self::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    public function getOverdueTasks(int $daysThreshold = 2): array
    {
        $thresholdDate = date('Y-m-d', strtotime("+{$daysThreshold} days"));

        return $this->where(self::schema_fields_STATUS, self::STATUS_COMPLETED, '!=')
            ->where(self::schema_fields_END_DATE, null, 'IS NOT')
            ->where(self::schema_fields_END_DATE, $thresholdDate, '<=')
            ->where(function ($query) {
                $query->where(self::schema_fields_IS_IMPORTANT, 1)
                    ->orWhere(self::schema_fields_PRIORITY, self::PRIORITY_URGENT);
            })
            ->select()
            ->fetch()
            ->getItems();
    }

    public function addTask(array $data): self
    {
        $this->setData($data);
        $this->save();
        return $this;
    }

    public function updateTaskStatus(int $taskId, string $status): bool
    {
        $this->load($taskId);
        if (!$this->getId()) {
            return false;
        }
        $this->setData(self::schema_fields_STATUS, $status);
        $this->save();
        return true;
    }

    public function updateProgress(int $taskId, string $progress): bool
    {
        $this->load($taskId);
        if (!$this->getId()) {
            return false;
        }
        $this->setData(self::schema_fields_PROGRESS, $progress);
        $this->save();
        return true;
    }

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
