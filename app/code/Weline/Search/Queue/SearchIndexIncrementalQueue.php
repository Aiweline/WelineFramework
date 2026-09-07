<?php

declare(strict_types=1);

namespace Weline\Search\Queue;

use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Api\BatchDrainingQueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Queue\Api\ScopedQueueConsumerInterface;
use Weline\Search\Api\SearchProjectionPendingDrainerInterface;
use Weline\Search\Service\SearchIndexIncrementalApplier;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Scope-fenced durable Search incremental consumer.
 *
 * Implements batch draining: one Worker process applies the primary event, then
 * claims sibling pending projection rows in-process to avoid PHP CLI cold-start
 * storms under product mutation bursts.
 */
final class SearchIndexIncrementalQueue implements
    ScopedQueueConsumerInterface,
    BatchDrainingQueueConsumerInterface
{
    public const CONTRACT = 'search.incremental_queue.v1';
    public const DEFAULT_BATCH_DRAIN_LIMIT = 100;

    private const CONTENT_FIELDS = [
        'contract',
        'event_id',
        'event_seq',
        'target_id',
        'target_type',
    ];

    public function __construct(
        private readonly SearchIndexIncrementalApplier $applier,
        private readonly StoreCatalogInterface $stores,
        private readonly SearchProjectionPendingDrainerInterface $drainer,
    ) {
    }

    public function name(): string
    {
        return (string)__('Search 增量索引');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('从 Product current source 刷新 Website/Store Search 投影');
    }

    public function batchDrainLimit(): int
    {
        return self::DEFAULT_BATCH_DRAIN_LIMIT;
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        try {
            $payload = $this->decode($queue);
            $envelope = $queue->getScopeEnvelope();
        } catch (\Throwable) {
            return false;
        }

        return $envelope instanceof ScopeEnvelope
            && ($payload['contract'] ?? null) === self::CONTRACT
            && \preg_match('/^[a-f0-9]{32}$/D', (string)($payload['event_id'] ?? '')) === 1
            && (int)($payload['event_seq'] ?? 0) >= 1
            && (int)($payload['target_id'] ?? 0) >= 1
            && \in_array(
                (string)($payload['target_type'] ?? ''),
                ['product', 'store_product'],
                true,
            );
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $primary = $this->applyEvent($queue);
        $limit = $this->batchDrainLimit();
        if ($limit < 1) {
            return $primary;
        }

        $batch = $this->drainer->drainSiblings(
            $this,
            (int)$queue->getId(),
            $limit,
        );
        if (($batch['drained'] ?? 0) < 1 && ($batch['failed'] ?? 0) < 1) {
            return $primary;
        }

        return $primary
            . '; batch_drained=' . (int)$batch['drained']
            . '; batch_failed=' . (int)$batch['failed']
            . '; batch_remaining=' . (int)$batch['remaining'];
    }

    /**
     * Apply one projection event without draining siblings.
     * Used by the in-process batch drainer for sibling rows.
     */
    public function applyEvent(QueueTaskContextInterface $queue): string
    {
        $payload = $this->decode($queue);
        $envelope = $queue->getScopeEnvelope()
            ?? throw new \RuntimeException('search_incremental_scope_envelope_missing');
        $scope = $envelope->scope;
        if ($scope->websiteId === null || $scope->websiteCode === null) {
            throw new \RuntimeException('search_incremental_website_scope_missing');
        }
        $event = [
            'website_id' => $scope->websiteId,
            'idempotency_key' => 'resource-change:' . (string)$payload['event_id'],
            'event_seq' => (int)$payload['event_seq'],
            'target_type' => (string)$payload['target_type'],
            'target_id' => (int)$payload['target_id'],
        ];
        if ($scope->scopeKind === ScopeIdentity::KIND_STORE) {
            if ($scope->storeCode === null || $scope->storeCode === '') {
                throw new \RuntimeException('search_incremental_store_scope_missing');
            }
            $store = $this->stores->byCode($scope->websiteId, $scope->storeCode);
            if ($store === null
                || $store->websiteId !== $scope->websiteId
                || $store->storeMode !== $scope->storeMode
            ) {
                throw new \RuntimeException('search_incremental_store_scope_mismatch');
            }
            $event['store_id'] = $store->id;
        }

        $result = $this->applier->apply($event);

        return 'QUEUE_DONE: search_incremental_'
            . (!empty($result['replayed'])
                ? 'replayed'
                : (!empty($result['applied']) ? 'applied' : 'covered'));
    }

    public function acceptedScopeKinds(): array
    {
        return [ScopeIdentity::KIND_WEBSITE, ScopeIdentity::KIND_STORE];
    }

    public function requiredScopeDimensions(): array
    {
        return ['website_id', 'website_code'];
    }

    public function rejectedScopeMessage(ScopeEnvelope $envelope): string
    {
        return (string)__(
            'Search 增量消费者只接受完整 Website 或 Store Scope，当前为 %{1}',
            [$envelope->scope->scopeKind],
        );
    }

    /** @return array<string,mixed> */
    private function decode(QueueTaskContextInterface $queue): array
    {
        $content = \json_decode($queue->getContent(), true, 32, JSON_THROW_ON_ERROR);
        if (!\is_array($content) || \array_is_list($content)) {
            throw new \InvalidArgumentException('search_incremental_content_invalid');
        }
        $fields = \array_keys($content);
        \sort($fields);
        if ($fields !== self::CONTENT_FIELDS) {
            throw new \InvalidArgumentException('search_incremental_content_fields_invalid');
        }

        return $content;
    }
}
