<?php

declare(strict_types=1);

namespace Weline\Deploy\Cron;

use Weline\Deploy\Service\DeployWebhookJobDrainService;
use Weline\Framework\Cron\CronTaskInterface;

/**
 * Drain durable webhook release jobs. Runs in cron CLI process — no sudo / no WLS request cgroup.
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
        return (string)__('消费 Deploy Webhook 发布队列，并收口已退出的 systemd-run 外部任务（不依赖 sudo）');
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
            (int)$result['processed'],
            (int)$result['ok'],
            (int)$result['failed'],
            (int)$result['skipped'],
            (int)($result['reconciled'] ?? 0)
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
