<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Queue\Model\Queue;
use Weline\Queue\Model\Queue\Type;
use Weline\Queue\Service\QueueDispatchService;
use Weline\Search\Api\SearchProjectionPendingDrainerInterface;
use Weline\Search\Queue\SearchIndexIncrementalQueue;

/**
 * Drains sibling Search projection queue rows inside the active Worker process.
 */
final class SearchProjectionPendingDrainer implements SearchProjectionPendingDrainerInterface
{
    public function __construct(
        private readonly Queue $queue,
        private readonly QueueDispatchService $dispatch,
    ) {
    }

    /**
     * @return array{drained:int,failed:int,remaining:int}
     */
    public function drainSiblings(
        SearchIndexIncrementalQueue $consumer,
        int $excludeQueueId,
        int $limit,
    ): array {
        $limit = \max(0, $limit);
        if ($limit < 1) {
            return ['drained' => 0, 'failed' => 0, 'remaining' => 0];
        }

        $pid = \getmypid();
        if (!\is_int($pid) || $pid < 1) {
            return ['drained' => 0, 'failed' => 0, 'remaining' => 0];
        }

        $pending = $this->loadPendingProjectionQueues($excludeQueueId, $limit + 1);
        $remainingHint = \max(0, \count($pending) - $limit);
        $pending = \array_slice($pending, 0, $limit);

        $drained = 0;
        $failed = 0;
        foreach ($pending as $row) {
            if (!$row instanceof Queue) {
                continue;
            }
            $queueId = (int)$row->getId();
            if ($queueId < 1 || $queueId === $excludeQueueId) {
                continue;
            }
            $claimed = $this->dispatch->claimQueueForManualRun($queueId, $pid);
            if (empty($claimed['confirmed']) || !\is_array($claimed['data'] ?? null)) {
                continue;
            }
            $task = clone $this->queue;
            $task->clearData()->setData($claimed['data']);
            try {
                if (!$consumer->validate($task)) {
                    $this->dispatch->failQueueWorkerSafely(
                        $queueId,
                        '',
                        $pid,
                        'QUEUE_ERROR: search_incremental_batch_validate_failed',
                    );
                    $failed++;
                    continue;
                }
                $result = $consumer->applyEvent($task);
                $completed = $this->dispatch->completeQueueWorkerSafely(
                    $queueId,
                    '',
                    $pid,
                    $result,
                );
                if (empty($completed['confirmed'])) {
                    $this->dispatch->failQueueWorkerSafely(
                        $queueId,
                        '',
                        $pid,
                        'QUEUE_ERROR: search_incremental_batch_complete_failed',
                    );
                    $failed++;
                    continue;
                }
                $drained++;
            } catch (\Throwable $throwable) {
                $this->dispatch->failQueueWorkerSafely(
                    $queueId,
                    '',
                    $pid,
                    'QUEUE_ERROR: search_incremental_batch_failed: ' . $throwable->getMessage(),
                );
                $failed++;
            }
        }

        return [
            'drained' => $drained,
            'failed' => $failed,
            'remaining' => $remainingHint,
        ];
    }

    /** @return list<Queue> */
    private function loadPendingProjectionQueues(int $excludeQueueId, int $limit): array
    {
        $limit = \max(1, $limit);
        $class = \ltrim(SearchIndexIncrementalQueue::class, '\\');
        $query = clone $this->queue;
        $items = $query->reset()
            ->joinModel(Type::class, 't', 'main_table.type_id=t.type_id', 'inner')
            ->where(Queue::schema_fields_finished, 0)
            ->where(Queue::schema_fields_auto, 1)
            ->where(Queue::schema_fields_status, Queue::status_pending)
            ->where('t.' . Type::schema_fields_class, $class)
            ->order(Queue::schema_fields_ID, 'ASC')
            ->pagination(1, $limit)
            ->select()
            ->fetch()
            ->getItems();

        $rows = [];
        foreach ($items as $item) {
            if (!$item instanceof Queue) {
                continue;
            }
            if ((int)$item->getId() === $excludeQueueId) {
                continue;
            }
            // Skip delayed rows; cron/dispatch will release them when due.
            $notBefore = $item->getNotBefore();
            if ($notBefore !== '' && $notBefore > \gmdate('Y-m-d H:i:s')) {
                continue;
            }
            $rows[] = $item;
        }

        return $rows;
    }
}
