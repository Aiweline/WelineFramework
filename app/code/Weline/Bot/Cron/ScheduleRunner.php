<?php
declare(strict_types=1);

namespace Weline\Bot\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Bot\Model\BotSchedule;
use Weline\Bot\Model\BotScheduleLog;
use Weline\Bot\Service\AgentEngine;
use Weline\Bot\Model\BotRole;

/**
 * 调度任务执行器
 *
 * 定时检查并执行到期的 Bot 调度任务
 */
class ScheduleRunner implements CronTaskInterface
{
    public function __construct(
        private readonly BotSchedule $scheduleModel,
        private readonly BotScheduleLog $logModel,
        private readonly BotRole $roleModel,
        private readonly AgentEngine $agentEngine,
    ) {}

    public function name(): string
    {
        return 'Bot 调度任务执行器';
    }

    public function execute_name(): string
    {
        return 'bot_schedule_runner';
    }

    public function tip(): string
    {
        return '检查并执行到期的 Bot 调度任务';
    }

    public function cron_time(): string
    {
        return '* * * * *'; // 每分钟检查一次
    }

    public function execute(): string
    {
        $now = time();
        $executed = 0;
        $failed = 0;

        // 查找到期的任务
        $schedules = $this->scheduleModel->reset()
            ->where(BotSchedule::schema_fields_STATUS, BotSchedule::STATUS_ENABLED)
            ->where(BotSchedule::schema_fields_NEXT_RUN_AT, $now, '<=')
            ->limit(10) // 每次最多执行 10 个任务
            ->select()
            ->fetch();

        foreach ($schedules->getItems() as $schedule) {
            $logId = null;

            try {
                // 创建执行日志
                $log = $this->logModel;
                $log->setData(BotScheduleLog::schema_fields_SCHEDULE_ID, $schedule->getId());
                $log->setData(BotScheduleLog::schema_fields_STATUS, BotScheduleLog::STATUS_RUNNING);
                $log->setData(BotScheduleLog::schema_fields_STARTED_AT, time());
                $log->setContextSnapshot($schedule->getContext());
                $log->save();
                $logId = $log->getId();

                // 获取角色
                $role = $this->roleModel->load($schedule->getData(BotSchedule::schema_fields_ROLE_ID));
                if (!$role->getId()) {
                    throw new \RuntimeException('Role not found');
                }

                // 执行任务
                $result = $this->agentEngine->execute(
                    $schedule->getData(BotSchedule::schema_fields_PROMPT),
                    $role
                );

                // 更新日志
                $log->markSuccess($result->content);

                // 更新任务状态
                $schedule->recordRun(true);
                $schedule->save();

                $executed++;

            } catch (\Throwable $e) {
                $failed++;

                // 更新日志
                if ($logId) {
                    $log = $this->logModel->load($logId);
                    $log->markFailed($e->getMessage());
                }

                // 更新任务失败计数
                $schedule->recordRun(false);
                $schedule->save();
            }
        }

        return "执行完成: {$executed} 成功, {$failed} 失败";
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 30;
    }
}
