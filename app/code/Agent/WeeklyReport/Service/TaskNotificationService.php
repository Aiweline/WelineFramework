<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Service;

use Agent\WeeklyReport\Model\WeeklyTask;
use Weline\Framework\Manager\ObjectManager;

/**
 * 任务通知服务
 * 
 * 检查临期、逾期的重点任务，通过 w_msg 发送通知
 */
class TaskNotificationService
{
    private ?WeeklyTask $taskModel = null;

    private function getTaskModel(): WeeklyTask
    {
        if ($this->taskModel === null) {
            $this->taskModel = ObjectManager::getInstance(WeeklyTask::class);
        }
        return clone $this->taskModel;
    }

    /**
     * 检查并发送临期任务通知
     * 
     * @param int $daysThreshold 临期天数阈值（默认 2 天）
     * @return int 发送的通知数量
     */
    public function checkAndNotify(int $daysThreshold = 2): int
    {
        $notifiedCount = 0;
        $today = date('Y-m-d');
        $thresholdDate = date('Y-m-d', strtotime("+{$daysThreshold} days"));

        $tasks = $this->getTaskModel()
            ->where(WeeklyTask::schema_fields_STATUS, WeeklyTask::STATUS_COMPLETED, '!=')
            ->where(WeeklyTask::schema_fields_END_DATE, null, 'IS NOT')
            ->where(WeeklyTask::schema_fields_END_DATE, $thresholdDate, '<=')
            ->where(function ($query) {
                $query->where(WeeklyTask::schema_fields_IS_IMPORTANT, 1)
                    ->orWhere(WeeklyTask::schema_fields_PRIORITY, WeeklyTask::PRIORITY_URGENT)
                    ->orWhere(WeeklyTask::schema_fields_PRIORITY, WeeklyTask::PRIORITY_HIGH);
            })
            ->select()
            ->fetch()
            ->getItems();

        foreach ($tasks as $task) {
            $taskId = $task->getId();
            $taskName = $task->getData(WeeklyTask::schema_fields_TASK_NAME);
            $endDate = $task->getData(WeeklyTask::schema_fields_END_DATE);
            $status = $task->getData(WeeklyTask::schema_fields_STATUS);
            $priority = (int) ($task->getData(WeeklyTask::schema_fields_PRIORITY) ?: WeeklyTask::PRIORITY_NORMAL);
            $isImportant = (bool) $task->getData(WeeklyTask::schema_fields_IS_IMPORTANT);
            $notifiedAt = $task->getData(WeeklyTask::schema_fields_NOTIFIED_AT);

            if ($notifiedAt && strtotime($notifiedAt) > strtotime('-4 hours')) {
                continue;
            }

            $daysLeft = (strtotime($endDate) - strtotime($today)) / 86400;

            if ($daysLeft < 0) {
                $this->sendOverdueNotification($task, abs((int) $daysLeft));
            } else {
                $this->sendDeadlineNotification($task, (int) $daysLeft);
            }

            $this->updateNotifiedAt($taskId);
            $notifiedCount++;
        }

        return $notifiedCount;
    }

    /**
     * 发送逾期通知
     */
    private function sendOverdueNotification(WeeklyTask $task, int $daysOverdue): void
    {
        $taskId = $task->getId();
        $taskName = $task->getData(WeeklyTask::schema_fields_TASK_NAME);
        $status = $task->getData(WeeklyTask::schema_fields_STATUS);
        $isImportant = (bool) $task->getData(WeeklyTask::schema_fields_IS_IMPORTANT);

        $importantMark = $isImportant ? '⭐重点 ' : '';
        $title = __('周报任务已逾期');
        $content = __(
            '%{mark}任务「%{task}」已逾期 %{days} 天，当前状态：%{status}，请尽快处理！',
            [
                'mark' => $importantMark,
                'task' => $taskName,
                'days' => $daysOverdue,
                'status' => $status,
            ]
        );

        $this->sendNotification('weekly_task_overdue', 'error', $title, $content, [
            'task_id' => $taskId,
            'task_name' => $taskName,
            'days_overdue' => $daysOverdue,
        ]);
    }

    /**
     * 发送临期通知
     */
    private function sendDeadlineNotification(WeeklyTask $task, int $daysLeft): void
    {
        $taskId = $task->getId();
        $taskName = $task->getData(WeeklyTask::schema_fields_TASK_NAME);
        $endDate = $task->getData(WeeklyTask::schema_fields_END_DATE);
        $status = $task->getData(WeeklyTask::schema_fields_STATUS);
        $isImportant = (bool) $task->getData(WeeklyTask::schema_fields_IS_IMPORTANT);

        $importantMark = $isImportant ? '⭐重点 ' : '';
        $urgency = $daysLeft === 0 ? '今日' : "{$daysLeft} 天后";
        $type = $daysLeft === 0 ? 'urgent' : 'warning';
        
        $title = __('周报任务即将到期');
        $content = __(
            '%{mark}任务「%{task}」将于 %{urgency} 截止（%{date}），当前状态：%{status}',
            [
                'mark' => $importantMark,
                'task' => $taskName,
                'urgency' => $urgency,
                'date' => $endDate,
                'status' => $status,
            ]
        );

        $this->sendNotification('weekly_task_deadline', $type, $title, $content, [
            'task_id' => $taskId,
            'task_name' => $taskName,
            'end_date' => $endDate,
            'days_left' => $daysLeft,
        ]);
    }

    /**
     * 发送通知（通过 w_msg）
     */
    private function sendNotification(string $topic, string $type, string $title, string $content, array $metadata = []): void
    {
        if (function_exists('w_msg')) {
            w_msg($topic, $type, $title, $content, ['metadata' => $metadata]);
        } else {
            w_log_warning("TaskNotificationService: w_msg() 函数不存在，无法发送通知: {$title}", [], 'weekly_report');
        }
    }

    /**
     * 更新最后通知时间
     */
    private function updateNotifiedAt(int $taskId): void
    {
        $task = $this->getTaskModel();
        $task->load($taskId);
        if ($task->getId()) {
            $task->setData(WeeklyTask::schema_fields_NOTIFIED_AT, date('Y-m-d H:i:s'));
            $task->save();
        }
    }

    /**
     * 获取需要通知的任务统计
     */
    public function getNotificationStats(int $daysThreshold = 2): array
    {
        $today = date('Y-m-d');
        $thresholdDate = date('Y-m-d', strtotime("+{$daysThreshold} days"));

        $overdueCount = $this->getTaskModel()
            ->where(WeeklyTask::schema_fields_STATUS, WeeklyTask::STATUS_COMPLETED, '!=')
            ->where(WeeklyTask::schema_fields_END_DATE, null, 'IS NOT')
            ->where(WeeklyTask::schema_fields_END_DATE, $today, '<')
            ->where(function ($query) {
                $query->where(WeeklyTask::schema_fields_IS_IMPORTANT, 1)
                    ->orWhere(WeeklyTask::schema_fields_PRIORITY, WeeklyTask::PRIORITY_URGENT)
                    ->orWhere(WeeklyTask::schema_fields_PRIORITY, WeeklyTask::PRIORITY_HIGH);
            })
            ->select()
            ->fetch()
            ->count();

        $upcomingCount = $this->getTaskModel()
            ->where(WeeklyTask::schema_fields_STATUS, WeeklyTask::STATUS_COMPLETED, '!=')
            ->where(WeeklyTask::schema_fields_END_DATE, null, 'IS NOT')
            ->where(WeeklyTask::schema_fields_END_DATE, $today, '>=')
            ->where(WeeklyTask::schema_fields_END_DATE, $thresholdDate, '<=')
            ->where(function ($query) {
                $query->where(WeeklyTask::schema_fields_IS_IMPORTANT, 1)
                    ->orWhere(WeeklyTask::schema_fields_PRIORITY, WeeklyTask::PRIORITY_URGENT)
                    ->orWhere(WeeklyTask::schema_fields_PRIORITY, WeeklyTask::PRIORITY_HIGH);
            })
            ->select()
            ->fetch()
            ->count();

        return [
            'overdue' => $overdueCount,
            'upcoming' => $upcomingCount,
            'total' => $overdueCount + $upcomingCount,
        ];
    }
}
