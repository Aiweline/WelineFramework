<?php

declare(strict_types=1);

namespace Weline\Search\Observer;

use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;
use Weline\Queue\Api\QueueStatus;
use Weline\Search\Queue\SearchIndexIncrementalQueue;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Converts immutable Product projection changes into idempotent scoped Queue rows.
 */
final class ProductSearchProjectionChangedObserver implements AsyncObserverInterface
{
    public const OBSERVER_NAME = 'search_product_projection_changed';

    public function __construct(
        private readonly StoreCatalogInterface $stores,
    ) {
    }

    public function supportsAsyncEvent(string $eventName, int $schemaVersion): bool
    {
        return $eventName === ResourceChange::EVENT_NAME
            && $schemaVersion === ResourceChange::SCHEMA_VERSION;
    }

    public function execute(Event &$event): void
    {
        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            throw new NonRetryableAsyncEventException(
                'search_product_projection_contract_mismatch',
                (string)__('Search 只接受 ResourceChange v1'),
            );
        }
        if ($change->resourceType()
            !== ProductSearchProjectionMutationCoordinatorInterface::RESOURCE_TYPE
        ) {
            return;
        }
        $data = $change->toArray();
        $after = $data['after'] ?? null;
        if (!\is_array($after)
            || ($after['contract'] ?? null)
                !== ProductSearchProjectionMutationCoordinatorInterface::CONTRACT
            || (int)($after['event_seq'] ?? 0) !== $change->revision()
            || !\in_array((string)($after['target_type'] ?? ''), [
                'product',
                'store_product',
            ], true)
            || (int)($after['target_id'] ?? 0) < 1
        ) {
            throw new NonRetryableAsyncEventException(
                'search_product_projection_payload_invalid',
                (string)__('Product Search 投影事件负载无效'),
            );
        }

        $scope = $this->scope($change, $after);
        $payload = [
            'contract' => SearchIndexIncrementalQueue::CONTRACT,
            'event_id' => $change->eventId(),
            'event_seq' => $change->revision(),
            'target_type' => (string)$after['target_type'],
            'target_id' => (int)$after['target_id'],
        ];
        $idempotencyKey = 'search-projection:' . $change->eventId();
        $created = \w_query('queue', 'createIfAbsent', [
            'class' => SearchIndexIncrementalQueue::class,
            'name' => (string)__('Search Product 投影事件 %{1}', [$change->eventId()]),
            'module' => 'Weline_Search',
            'content' => $payload,
            'status' => QueueStatus::PENDING,
            'auto' => true,
            'biz_key' => $idempotencyKey,
            'idempotency_scope' => 'search_product_projection',
            'idempotency_key' => $idempotencyKey,
            'scope_envelope' => ScopeEnvelope::of($scope)->toArray(),
        ], 'backend');
        if (!\is_array($created)
            || empty($created['success'])
            || (int)($created['queue_id'] ?? 0) < 1
        ) {
            throw new \RuntimeException('search_incremental_queue_admission_failed');
        }
    }

    /** @param array<string,mixed> $after */
    private function scope(ResourceChange $change, array $after): ScopeIdentity
    {
        $websiteId = $change->websiteId();
        $websiteCode = $change->websiteCode();
        if (($after['scope_kind'] ?? null) === ScopeIdentity::KIND_WEBSITE) {
            return ScopeIdentity::website($websiteId, $websiteCode);
        }
        if (($after['scope_kind'] ?? null) !== ScopeIdentity::KIND_STORE) {
            throw new NonRetryableAsyncEventException(
                'search_projection_scope_kind_invalid',
                (string)__('Product Search 投影 Scope kind 无效'),
            );
        }
        $storeId = (int)($after['store_id'] ?? 0);
        $store = $storeId > 0 ? $this->stores->byId($storeId) : null;
        if ($store === null || $store->websiteId !== $websiteId) {
            throw new NonRetryableAsyncEventException(
                'search_projection_store_scope_invalid',
                (string)__('Product Search 投影 Store Scope 无效'),
            );
        }

        return ScopeIdentity::store(
            $websiteId,
            $websiteCode,
            $store->code,
            $store->storeMode,
        );
    }
}
