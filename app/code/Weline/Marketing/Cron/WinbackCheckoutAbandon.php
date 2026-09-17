<?php

declare(strict_types=1);

namespace Weline\Marketing\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Service\WinbackCheckoutAbandonRunner;

final class WinbackCheckoutAbandon implements CronTaskInterface
{
    public function name(): string
    {
        return (string)__('营销结账遗弃挽回');
    }

    public function execute_name(): string
    {
        return 'marketing_winback_checkout_abandon';
    }

    public function tip(): string
    {
        return (string)__('按挽回活动配置的遗弃时限扫描过期结账报价并发送继续结账邮件');
    }

    public function cron_time(): string
    {
        return '*/15 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }

    public function execute(): string
    {
        /** @var WinbackCheckoutAbandonRunner $runner */
        $runner = ObjectManager::getInstance(WinbackCheckoutAbandonRunner::class);
        $stats = $runner->run();

        return sprintf(
            'winback checkout abandon: processed=%d sent=%d skipped=%d failed=%d',
            (int)$stats['processed'],
            (int)$stats['sent'],
            (int)$stats['skipped'],
            (int)$stats['failed'],
        );
    }
}
