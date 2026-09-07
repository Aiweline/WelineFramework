<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\GenerativeEngineOptimization\Queue\FeedGenerateQueue;
use Weline\GenerativeEngineOptimization\Queue\FeedPushQueue;
use Weline\Queue\Api\QueueStatus;
use Weline\Queue\Model\Queue;
use Weline\Queue\Service\QueueDispatchService;

/**
 * Feed 队列入队：按 feed_id + 作业类型合并，避免 realtime 条目风暴打满 CLI。
 */
class FeedQueueService
{
    public const QUEUE_TYPE_FEED_GENERATE = 'geo_feed_generate';
    public const QUEUE_TYPE_FEED_PUSH = 'geo_feed_push';
    public const IDEMPOTENCY_SCOPE = 'generative_engine_feed_queue_slot';
    public const MODULE = 'Weline_GenerativeEngineOptimization';

    public function __construct(
        private readonly QueueDispatchService $dispatch,
    ) {
    }

    public function enqueueFeedGenerate(int $feedId, string $format = 'json_feed', bool $force = false): int
    {
        $feedId = $this->requireFeedId($feedId);
        $format = $this->normalizeFormat($format);
        $slotKey = $this->generateSlotKey($feedId, $format);

        return $this->admit(
            FeedGenerateQueue::class,
            $slotKey,
            "生成Feed #{$feedId}",
            [
                'feed_id' => $feedId,
                'format' => $format,
                'force' => $force,
            ],
        );
    }

    /**
     * @param list<int|string> $platformIds
     */
    public function enqueueFeedPush(int $feedId, array $platformIds = [], string $pushType = 'scheduled'): int
    {
        $feedId = $this->requireFeedId($feedId);
        $platformIds = $this->normalizePlatformIds($platformIds);
        $pushType = $this->normalizePushType($pushType);
        $slotKey = $this->pushSlotKey($feedId, $platformIds);
        $platformIdsStr = $platformIds === [] ? '所有平台' : implode(',', $platformIds);

        return $this->admit(
            FeedPushQueue::class,
            $slotKey,
            "推送Feed #{$feedId} 到平台 [{$platformIdsStr}]",
            [
                'feed_id' => $feedId,
                'platform_ids' => $platformIds,
                'push_type' => $pushType,
            ],
        );
    }

    /**
     * @param array<string, mixed> $itemData
     */
    public function enqueueFeedItemAdd(int $feedId, string $itemType, int $itemId, array $itemData = []): int
    {
        return $this->enqueueFeedGenerate($feedId, 'json_feed', false);
    }

    public function generateSlotKey(int $feedId, string $format = 'json_feed'): string
    {
        return 'generate:' . $this->requireFeedId($feedId) . ':' . $this->normalizeFormat($format);
    }

    /**
     * @param list<int|string> $platformIds
     */
    public function pushSlotKey(int $feedId, array $platformIds = []): string
    {
        $platformIds = $this->normalizePlatformIds($platformIds);
        $platformKey = $platformIds === [] ? 'all' : implode(',', $platformIds);

        return 'push:' . $this->requireFeedId($feedId) . ':' . $platformKey;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function admit(
        string $class,
        string $slotKey,
        string $name,
        array $content,
        bool $isFollowUp = false,
    ): int {
        $created = \w_query('queue', 'createIfAbsent', [
            'class' => $class,
            'name' => $isFollowUp ? $name . ' / followup' : $name,
            'module' => self::MODULE,
            'content' => $content,
            'status' => QueueStatus::PENDING,
            'auto' => true,
            'biz_key' => $slotKey,
            'idempotency_scope' => self::IDEMPOTENCY_SCOPE,
            'idempotency_key' => $slotKey,
        ]);
        if (!\is_array($created)
            || empty($created['success'])
            || (int)($created['queue_id'] ?? 0) < 1
        ) {
            throw new \RuntimeException((string)__('创建队列失败。'));
        }
        if (!empty($created['created'])) {
            return (int)$created['queue_id'];
        }

        $queueId = (int)$created['queue_id'];
        $status = (string)($created['status'] ?? '');
        if ($status === Queue::status_pending) {
            $this->refreshPending($queueId, $name, $content, $isFollowUp);

            return $queueId;
        }
        if ($status === Queue::status_running) {
            if ($isFollowUp) {
                return $queueId;
            }

            return $this->admit($class, $slotKey . ':followup', $name, $content, true);
        }

        $this->reopenTerminal($queueId, $name, $content, $isFollowUp);

        return $queueId;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function refreshPending(int $queueId, string $name, array $content, bool $isFollowUp): void
    {
        $updated = \w_query('queue', 'update', [
            'queue_id' => $queueId,
            'content' => $content,
            'name' => $isFollowUp ? $name . ' / followup' : $name,
        ]);
        if (\is_array($updated) && !empty($updated['success'])) {
            return;
        }

        $errorCode = \is_array($updated) ? (string)($updated['error_code'] ?? '') : '';
        if ($errorCode === 'queue_edit_active' || $errorCode === 'queue_state_changed') {
            return;
        }

        throw new \RuntimeException((string)__('合并更新 Feed 队列失败。'));
    }

    /**
     * @param array<string, mixed> $content
     */
    private function reopenTerminal(int $queueId, string $name, array $content, bool $isFollowUp): void
    {
        $requeued = $this->dispatch->requeueQueueSafely($queueId);
        if (empty($requeued['confirmed'])) {
            throw new \RuntimeException(
                'generative_engine_feed_queue_reopen_failed:'
                . (string)($requeued['error_code'] ?? 'unknown'),
            );
        }

        $updated = \w_query('queue', 'update', [
            'queue_id' => $queueId,
            'content' => $content,
            'name' => $isFollowUp ? $name . ' / followup' : $name,
        ]);
        if (!\is_array($updated) || empty($updated['success'])) {
            throw new \RuntimeException((string)__('重开 Feed 队列失败。'));
        }

        $queue = ObjectManager::getInstance(Queue::class);
        $queue->clearData()->setData(\is_array($updated['data'] ?? null) ? $updated['data'] : []);
        if ((int)$queue->getId() < 1) {
            $queue->clearData()->clearQuery()
                ->where(Queue::schema_fields_ID, $queueId)
                ->find()
                ->fetch();
        }
        $this->dispatch->dispatchQueueIfEligible($queue);
    }

    private function requireFeedId(int $feedId): int
    {
        if ($feedId < 1) {
            throw new \InvalidArgumentException((string)__('Feed ID 无效。'));
        }

        return $feedId;
    }

    private function normalizeFormat(string $format): string
    {
        $format = \trim($format);
        if ($format === '') {
            return 'json_feed';
        }
        if (\preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $format) !== 1) {
            throw new \InvalidArgumentException((string)__('Feed 格式无效。'));
        }

        return $format;
    }

    private function normalizePushType(string $pushType): string
    {
        $pushType = \trim($pushType);
        if ($pushType === '') {
            return 'scheduled';
        }
        if (\preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $pushType) !== 1) {
            throw new \InvalidArgumentException((string)__('Feed 推送类型无效。'));
        }

        return $pushType;
    }

    /**
     * @param list<int|string> $platformIds
     * @return list<int>
     */
    private function normalizePlatformIds(array $platformIds): array
    {
        $normalized = [];
        foreach ($platformIds as $platformId) {
            $id = (int)$platformId;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }
        $normalized = \array_values($normalized);
        \sort($normalized, \SORT_NUMERIC);

        return $normalized;
    }
}
