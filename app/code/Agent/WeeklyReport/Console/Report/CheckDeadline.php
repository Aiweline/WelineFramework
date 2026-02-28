<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Console\Report;

use Agent\WeeklyReport\Service\TaskNotificationService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;

/**
 * 检查临期任务并发送通知
 * 
 * 命令：report:check-deadline
 * 
 * 建议通过 cron 定时执行（每小时或每 4 小时）
 */
class CheckDeadline extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $daysThreshold = (int) ($args['days'] ?? $args['d'] ?? 2);
        $dryRun = isset($args['dry-run']) || isset($args['n']);

        $notificationService = ObjectManager::getInstance(TaskNotificationService::class);

        $stats = $notificationService->getNotificationStats($daysThreshold);

        echo "📊 任务截止日期检查\n";
        echo "   临期阈值: {$daysThreshold} 天\n";
        echo "   已逾期任务: {$stats['overdue']} 个\n";
        echo "   即将到期任务: {$stats['upcoming']} 个\n";
        echo "   需通知总数: {$stats['total']} 个\n\n";

        if ($stats['total'] === 0) {
            echo "✅ 暂无需要通知的临期/逾期任务\n";
            return 'OK';
        }

        if ($dryRun) {
            echo "ℹ️  模拟运行模式，不发送实际通知\n";
            return 'DRY_RUN';
        }

        echo "🔔 正在发送通知...\n";
        $notifiedCount = $notificationService->checkAndNotify($daysThreshold);
        echo "✅ 已发送 {$notifiedCount} 条通知\n";

        return 'OK';
    }

    public function tip(): string
    {
        return '检查临期/逾期的重点任务并发送通知';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'report:check-deadline',
            '检查临期/逾期的重点任务（⭐重点或高/紧急优先级），通过 w_msg 发送通知',
            [
                '-d, --days <N>' => '临期天数阈值（默认 2 天）',
                '-n, --dry-run' => '模拟运行，不实际发送通知',
            ],
            [],
            [
                '检查 2 天内到期的任务' => 'php bin/w report:check-deadline',
                '检查 3 天内到期的任务' => 'php bin/w report:check-deadline -d 3',
                '模拟运行' => 'php bin/w report:check-deadline --dry-run',
            ]
        );
    }
}
