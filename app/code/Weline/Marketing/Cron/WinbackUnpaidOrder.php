<?php

declare(strict_types=1);

namespace Weline\Marketing\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Service\WinbackUnpaidOrderRunner;

final class WinbackUnpaidOrder implements CronTaskInterface
{
    public function name(): string
    {
        return (string)__('营销未付订单挽回');
    }

    public function execute_name(): string
    {
        return 'marketing_winback_unpaid_order';
    }

    public function tip(): string
    {
        return (string)__('按挽回活动配置的遗弃时限扫描未付订单并发送催付邮件');
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
        /** @var WinbackUnpaidOrderRunner $runner */
        $runner = ObjectManager::getInstance(WinbackUnpaidOrderRunner::class);
        $stats = $runner->run();

        return sprintf(
            'winback unpaid: processed=%d sent=%d skipped=%d failed=%d',
            (int)$stats['processed'],
            (int)$stats['sent'],
            (int)$stats['skipped'],
            (int)$stats['failed'],
        );
    }
}
