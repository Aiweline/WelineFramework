<?php

declare(strict_types=1);

namespace Weline\Deploy\Cron;

use Weline\Deploy\Service\DeployWebhookExpectWatchService;
use Weline\Framework\Cron\CronTaskInterface;

/**
 * Cron 检测远端 Tag：发现未发布的新 Tag 只入队最新一条；发布由 Drain 任务消费。
 * 跑在 cron CLI 进程里，不依赖 nginx/WLS HTTP，也不依赖 Git 平台回调。
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
        return (string)__('Cron 检测远端 tip/tag：启用计划更新时只入队最新一条');
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
            'ok skipped=%d reason=%s tag=%s new_pending=%d cleared=%d notified=%d enqueued=%d',
            !empty($result['skipped']) ? 1 : 0,
            (string)($result['reason'] ?? ''),
            (string)($result['tag'] ?? ''),
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
