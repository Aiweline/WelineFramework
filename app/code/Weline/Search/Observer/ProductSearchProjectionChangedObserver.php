<?php

declare(strict_types=1);

namespace Weline\Search\Observer;

use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface;
use Weline\Search\Api\SearchProjectionQueueAdmissionInterface;
use Weline\Search\Queue\SearchIndexIncrementalQueue;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Converts immutable Product projection changes into coalesced scoped Queue slots.
 */
final class ProductSearchProjectionChangedObserver implements AsyncObserverInterface
{
    public const OBSERVER_NAME = 'search_product_projection_changed';

    public function __construct(
        private readonly StoreCatalogInterface $stores,
        private readonly SearchProjectionQueueAdmissionInterface $admission,
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
                (string)__('Product Search 投影负载无效'),
            );
        }

        $scope = $this->scope($change, $after);
        $this->admission->admit([
            'contract' => SearchIndexIncrementalQueue::CONTRACT,
            'event_id' => $change->eventId(),
            'event_seq' => $change->revision(),
            'target_type' => (string)$after['target_type'],
            'target_id' => (int)$after['target_id'],
        ], $scope);
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
        $store = $this->stores->byId($storeId);
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
