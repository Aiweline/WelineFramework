<?php

declare(strict_types=1);

namespace Weline\Deploy\Cron;

use Weline\Deploy\Service\DeployWebhookExpectWatchService;
use Weline\Framework\Cron\CronTaskInterface;

/**
 * Tag × Webhook expect-watch: remote new tags without enqueue → webhook_miss.
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
        return (string)__('检测远程新 tag 超时未见 Webhook 入队并告警');
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
            'ok skipped=%d reason=%s new_pending=%d cleared=%d notified=%d',
            !empty($result['skipped']) ? 1 : 0,
            (string)($result['reason'] ?? ''),
            (int)($result['new_pending'] ?? 0),
            (int)($result['cleared'] ?? 0),
            (int)($result['notified'] ?? 0)
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
