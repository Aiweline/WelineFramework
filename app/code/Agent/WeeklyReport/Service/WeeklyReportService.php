<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Service;

use Agent\WeeklyReport\Model\WeeklyReport;
use Agent\WeeklyReport\Model\WeeklyTask;
use Weline\Framework\Manager\ObjectManager;

/**
 * 周报服务
 * 
 * 职责：周报 CRUD、周次计算、数据管理
 */
class WeeklyReportService
{
    private ?WeeklyReport $reportModel = null;
    private ?WeeklyTask $taskModel = null;
    private ?HolidayService $holidayService = null;

    private string $firstWeekStartDate = '2026-01-12';

    private function getReportModel(): WeeklyReport
    {
        if ($this->reportModel === null) {
            $this->reportModel = ObjectManager::getInstance(WeeklyReport::class);
        }
        return clone $this->reportModel;
    }

    private function getTaskModel(): WeeklyTask
    {
        if ($this->taskModel === null) {
            $this->taskModel = ObjectManager::getInstance(WeeklyTask::class);
        }
        return clone $this->taskModel;
    }

    private function getHolidayService(): HolidayService
    {
        if ($this->holidayService === null) {
            $this->holidayService = ObjectManager::getInstance(HolidayService::class);
        }
        return $this->holidayService;
    }

    /**
     * 根据日期计算周次（基于导入数据的第一周起始日期）
     */
    public function calculateWeekNumber(string $date): int
    {
        $firstWeekStart = new \DateTime($this->firstWeekStartDate);
        $targetDate = new \DateTime($date);

        $diff = $firstWeekStart->diff($targetDate);
        $days = (int) $diff->format('%r%a');

        if ($days < 0) {
            return 0;
        }

        return (int) floor($days / 7) + 1;
    }

    /**
     * 获取周的起止日期
     */
    public function getWeekDateRange(int $weekNumber, int $year = 2026): array
    {
        $firstWeekStart = new \DateTime($this->firstWeekStartDate);

        $weekStart = clone $firstWeekStart;
        $weekStart->modify('+' . (($weekNumber - 1) * 7) . ' days');

        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days');

        return [
            'start' => $weekStart->format('Y-m-d'),
            'end' => $weekEnd->format('Y-m-d'),
        ];
    }

    /**
     * 获取当前周次
     */
    public function getCurrentWeekNumber(): int
    {
        return $this->calculateWeekNumber(date('Y-m-d'));
    }

    /**
     * 获取当前周报
     */
    public function getCurrentWeekReport(): WeeklyReport
    {
        $weekNumber = $this->getCurrentWeekNumber();
        $year = (int) date('Y');
        $dateRange = $this->getWeekDateRange($weekNumber, $year);

        $report = $this->getReportModel()->getOrCreateWeekReport(
            $year,
            $weekNumber,
            $dateRange['start'],
            $dateRange['end']
        );

        $holidayInfo = $this->getHolidayService()->isHolidayWeek($dateRange['start'], $dateRange['end']);
        if ($holidayInfo && !$report->isHolidayWeek()) {
            $report->setAsHolidayWeek($holidayInfo['name']);
            $report->save();
        }

        return $report;
    }

    /**
     * 获取指定周报
     */
    public function getWeekReport(int $weekNumber, int $year = 2026): ?WeeklyReport
    {
        return $this->getReportModel()->getCurrentWeekReport($year, $weekNumber);
    }

    /**
     * 获取或创建指定周报
     */
    public function getOrCreateWeekReport(int $weekNumber, int $year = 2026): WeeklyReport
    {
        $dateRange = $this->getWeekDateRange($weekNumber, $year);

        $report = $this->getReportModel()->getOrCreateWeekReport(
            $year,
            $weekNumber,
            $dateRange['start'],
            $dateRange['end']
        );

        $holidayInfo = $this->getHolidayService()->isHolidayWeek($dateRange['start'], $dateRange['end']);
        if ($holidayInfo && !$report->isHolidayWeek()) {
            $report->setAsHolidayWeek($holidayInfo['name']);
            $report->save();
        }

        return $report;
    }

    /**
     * 获取周报的所有任务
     */
    public function getWeekTasks(int $reportId): array
    {
        return $this->getTaskModel()->getTasksByReportId($reportId);
    }

    /**
     * 添加任务到周报
     */
    public function addTask(int $reportId, array $taskData): WeeklyTask
    {
        $taskData[WeeklyTask::schema_fields_REPORT_ID] = $reportId;

        $task = $this->getTaskModel();
        return $task->addTask($taskData);
    }

    /**
     * 获取单个任务
     */
    public function getTask(int $taskId): ?WeeklyTask
    {
        $task = $this->getTaskModel();
        $task->load($taskId);
        return $task->getId() ? $task : null;
    }

    /**
     * 更新任务
     */
    public function updateTask(int $taskId, array $taskData): bool
    {
        $task = $this->getTaskModel();
        $task->load($taskId);

        if (!$task->getId()) {
            return false;
        }

        foreach ($taskData as $key => $value) {
            $task->setData($key, $value);
        }

        $task->save();
        return true;
    }

    /**
     * 删除任务
     */
    public function deleteTask(int $taskId): bool
    {
        return $this->getTaskModel()->deleteTask($taskId);
    }

    /**
     * 获取周报状态摘要
     */
    public function getWeekReportSummary(int $reportId): array
    {
        $tasks = $this->getWeekTasks($reportId);

        $summary = [
            'total' => count($tasks),
            'completed' => 0,
            'in_progress' => 0,
            'todo' => 0,
            'testing' => 0,
            'other' => 0,
        ];

        foreach ($tasks as $task) {
            $status = $task->getData(WeeklyTask::schema_fields_STATUS) ?? '';

            switch ($status) {
                case WeeklyTask::STATUS_COMPLETED:
                case '完成':
                    $summary['completed']++;
                    break;
                case WeeklyTask::STATUS_IN_PROGRESS:
                    $summary['in_progress']++;
                    break;
                case WeeklyTask::STATUS_TODO:
                    $summary['todo']++;
                    break;
                case WeeklyTask::STATUS_TESTING:
                    $summary['testing']++;
                    break;
                default:
                    $summary['other']++;
            }
        }

        return $summary;
    }

    /**
     * 获取所有周报列表
     */
    public function getAllReports(?int $year = null): array
    {
        return $this->getReportModel()->getAllReports($year);
    }

    /**
     * 根据描述自动解析任务（AI 解析）
     */
    public function parseTaskFromDescription(string $description): array
    {
        $task = [
            WeeklyTask::schema_fields_TASK_NAME => '',
            WeeklyTask::schema_fields_SUB_TASK => '',
            WeeklyTask::schema_fields_STATUS => WeeklyTask::STATUS_IN_PROGRESS,
            WeeklyTask::schema_fields_PROGRESS => '',
            WeeklyTask::schema_fields_CATEGORY => '',
        ];

        if (str_contains($description, '完成') || str_contains($description, '已完成')) {
            $task[WeeklyTask::schema_fields_STATUS] = WeeklyTask::STATUS_COMPLETED;
        } elseif (str_contains($description, '测试')) {
            $task[WeeklyTask::schema_fields_STATUS] = WeeklyTask::STATUS_TESTING;
        } elseif (str_contains($description, '待开始') || str_contains($description, '计划')) {
            $task[WeeklyTask::schema_fields_STATUS] = WeeklyTask::STATUS_TODO;
        }

        if (str_contains($description, '建站')) {
            $task[WeeklyTask::schema_fields_CATEGORY] = '建站任务';
        } elseif (str_contains($description, 'Demo') || str_contains($description, 'AI')) {
            $task[WeeklyTask::schema_fields_CATEGORY] = 'Demo 自动AI建站系统';
        } elseif (str_contains($description, 'Saas') || str_contains($description, 'saas')) {
            $task[WeeklyTask::schema_fields_CATEGORY] = 'Saas';
        }

        $task[WeeklyTask::schema_fields_TASK_NAME] = $description;
        $task[WeeklyTask::schema_fields_PROGRESS] = $description;

        return $task;
    }
}
