<?php

declare(strict_types=1);

namespace Weline\Queue\Cron;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Queue\Model\Queue as QueueModel;
use Weline\Queue\Service\QueueDispatchService;

class Queue implements \Weline\Cron\CronTaskInterface
{
    public function __construct(
        QueueModel $queue,
        private readonly Printing $printing,
        ?QueueDispatchService $queueDispatchService = null,
    ) {
        unset($queue);
        $this->queueDispatchService = $queueDispatchService ?? ObjectManager::getInstance(QueueDispatchService::class);
    }

    private QueueDispatchService $queueDispatchService;

    public function name(): string
    {
        return '消息队列-消费任务';
    }

    public function execute_name(): string
    {
        return 'queue';
    }

    public function tip(): string
    {
        return '定时消费任务，每分钟检测一次消息队列。如果有任务继续执行队列中的任务。';
    }

    public function cron_time(): string
    {
        return '*/1 * * * *';
    }

    public function execute(): string
    {
        $this->queueDispatchService->dispatchPendingAutoQueues();

        return 'OK';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 180;
    }
}
