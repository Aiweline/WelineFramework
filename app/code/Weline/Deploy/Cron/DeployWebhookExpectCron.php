<?php

declare(strict_types=1);

namespace Weline\Deploy\Cron;

use Weline\Deploy\Service\DeployWebhookExpectWatchService;
use Weline\Framework\Cron\CronTaskInterface;

/**
 * Remote tip/tag watch: cron_update enqueues newest only; webhook-only → miss alert.
 * Runs in cron CLI — independent of nginx/WLS HTTP.
 */
class DeployWebhookExpectCron implements CronTaskInterface
{
    public function name(): string
    {
        return 'DeployWebhookExpect';
    }

    public function execute_name(): string
    {
        return 'deploy_webhook_expect';
    }

    public function tip(): string
    {
        return (string)__('Cron 检测远程 tip/tag：启用计划更新时只入队最新一条；仅 Webhook 时超时告警');
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function execute(): string
    {
        /** @var DeployWebhookExpectWatchService $watch */
        $watch = w_obj(DeployWebhookExpectWatchService::class);
        $result = $watch->tick();

        return sprintf(
            'ok skipped=%d reason=%s new_pending=%d cleared=%d notified=%d enqueued=%d',
            !empty($result['skipped']) ? 1 : 0,
            (string)($result['reason'] ?? ''),
            (int)($result['new_pending'] ?? 0),
            (int)($result['cleared'] ?? 0),
            (int)($result['notified'] ?? 0),
            (int)($result['enqueued'] ?? 0)
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
