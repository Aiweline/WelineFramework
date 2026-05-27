<?php

declare(strict_types=1);

namespace Weline\Queue\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Printing;
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\Queue\Helper\Helper;

class SetupUpgradeQueueCollect implements ObserverInterface
{
    private static bool $hasCollected = false;

    public function __construct(
        private Printing $printing
    ) {
    }

    public function execute(Event &$event): void
    {
        if (self::$hasCollected) {
            return;
        }
        self::$hasCollected = true;

        try {
            RegistryProgress::section('setup:upgrade queue maintenance');
            RegistryProgress::log('queue:collect started');
            Helper::collect();
            RegistryProgress::log('queue:collect finished');
            $this->printing->success(__('队列类型收集完成。'), '系统队列');
        } catch (\Throwable $throwable) {
            w_log_warning('setup:upgrade queue collect failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ], 'setup/scheduler_maintenance.log');
            RegistryProgress::log('queue:collect failed: ' . $throwable->getMessage());
            $this->printing->warning(__('队列类型收集失败，已跳过，不影响本次系统更新。错误：%{1}', [
                $throwable->getMessage(),
            ]));
        }
    }
}
