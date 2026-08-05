<?php

declare(strict_types=1);

namespace Weline\Queue\Cron;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Queue\Model\Queue as QueueModel;
use Weline\Queue\Service\AsyncEvent\AsyncEventQueueReconciler;
use Weline\Queue\Service\QueueDispatchService;

class Queue implements \Weline\Framework\Cron\CronTaskInterface
{
    public function __construct(
        QueueModel $queue,
        private readonly Printing $printing,
        ?QueueDispatchService $queueDispatchService = null,
        ?AsyncEventQueueReconciler $asyncEventQueueReconciler = null,
    ) {
        unset($queue);
        $this->queueDispatchService = $queueDispatchService ?? ObjectManager::getInstance(QueueDispatchService::class);
        $this->asyncEventQueueReconciler = $asyncEventQueueReconciler
            ?? ObjectManager::getInstance(AsyncEventQueueReconciler::class);
    }

    private QueueDispatchService $queueDispatchService;
    private AsyncEventQueueReconciler $asyncEventQueueReconciler;

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
        // 固定顺序：relay -> transport reconcile -> due retry -> timeout -> Queue dispatch。
        // Async event maintenance must never block ordinary queue consumption.
        $asyncStatus = 'ok';
        try {
            $this->asyncEventQueueReconciler->run();
        } catch (\Throwable $throwable) {
            $asyncStatus = 'async_reconcile_failed:' . $throwable->getMessage();
            $this->printing->warning(
                __('异步事件投递维护失败，已跳过并继续消费普通队列：%{1}', [$throwable->getMessage()])
            );
        }

        $this->queueDispatchService->dispatchPendingAutoQueues();

        // GC 不得插入上述投递关键路径；在普通 Queue 派发后做有界清理。
        try {
            $this->asyncEventQueueReconciler->collectGarbage();
        } catch (\Throwable $throwable) {
            $asyncStatus .= ';async_gc_failed:' . $throwable->getMessage();
            $this->printing->warning(
                __('异步事件投递 GC 失败，已跳过：%{1}', [$throwable->getMessage()])
            );
        }

        return $asyncStatus === 'ok' ? 'OK' : 'OK;' . $asyncStatus;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 180;
    }
}
