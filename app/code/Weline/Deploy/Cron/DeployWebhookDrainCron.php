<?php

declare(strict_types=1);

namespace Weline\Deploy\Cron;

use Weline\Deploy\Service\DeployWebhookJobDrainService;
use Weline\Framework\Cron\CronTaskInterface;

/**
 * 消费 Deploy 发布队列（webhook 命中的与 Cron 检测到的都走这条），并收口已退出的外部任务。
 * 跑在 cron CLI 进程里：不需要 sudo，不占 WLS 请求 cgroup。
 */
class DeployWebhookDrainCron implements CronTaskInterface
{
    public function name(): string
    {
        return 'DeployWebhookDrain';
    }

    public function execute_name(): string
    {
        return 'deploy_webhook_drain';
    }

    public function tip(): string
    {
        return (string)__('消费 Deploy 发布队列，并收口已退出的 systemd-run 外部任务（不依赖 sudo）');
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function execute(): string
    {
        /** @var DeployWebhookJobDrainService $drain */
        $drain = w_obj(DeployWebhookJobDrainService::class);
        $result = $drain->drain(3);

        return sprintf(
            'ok processed=%d success=%d failed=%d skipped=%d reconciled=%d',
            (int)($result['processed'] ?? 0),
            (int)($result['ok'] ?? 0),
            (int)($result['failed'] ?? 0),
            (int)($result['skipped'] ?? 0),
            (int)($result['reconciled'] ?? 0)
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
