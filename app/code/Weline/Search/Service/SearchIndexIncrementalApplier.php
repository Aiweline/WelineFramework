<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\ProductSearchProjectionSourceInterface;
use Weline\Search\Api\SearchIndexStorageInterface;
use Weline\Search\Api\SearchShardRegistryInterface;
use Weline\Search\Model\SearchShardKey;

/**
 * Durable idempotent Product projection event applier.
 */
final class SearchIndexIncrementalApplier
{
    public function __construct(
        private readonly SearchShardRegistryInterface $registry,
        private readonly SearchIndexStorageInterface $store,
        private readonly ProductSearchProjectionSourceInterface $source,
    ) {
    }

    public static function forTesting(?SearchIndexBuilder $builder = null): self
    {
        $builder ??= SearchIndexBuilder::forTesting();

        return new self(
            $builder->registry(),
            $builder->store(),
            $builder->source(),
        );
    }

    public function store(): SearchIndexStorageInterface
    {
        return $this->store;
    }

    public function registry(): SearchShardRegistryInterface
    {
        return $this->registry;
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    public function apply(array $event): array
    {
        $websiteId = (int)($event['website_id'] ?? -1);
        SearchShardKey::fromWebsiteId($websiteId);
        $this->registry->assertReady($websiteId);
        $idempotencyKey = \trim((string)($event['idempotency_key'] ?? ''));
        $eventSeq = (int)($event['event_seq'] ?? 0);
        $legacyDocument = \is_array($event['document'] ?? null) ? $event['document'] : [];
        $targetType = \trim((string)(
            $event['target_type'] ?? $legacyDocument['entity_type'] ?? ''
        ));
        $targetId = (int)($event['target_id'] ?? $legacyDocument['entity_id'] ?? 0);
        if ($targetId < 1
            && $this->source instanceof ArrayProductSearchProjectionSource
            && $legacyDocument !== []
        ) {
            $targetId = 1;
        }
        if ($idempotencyKey === '' || $eventSeq < 1 || $targetType === '' || $targetId < 1) {
            throw new \InvalidArgumentException('search_incremental_event_invalid');
        }
        $this->seedLegacyHarnessEvent($websiteId, $eventSeq, $event);

        $projection = $this->source->projectChange([
            'website_id' => $websiteId,
            'event_seq' => $eventSeq,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ] + (\array_key_exists('store_id', $event)
            ? ['store_id' => (int)$event['store_id']]
            : []));
        if ((int)($projection['website_id'] ?? -1) !== $websiteId
            || (int)($projection['event_seq'] ?? 0) !== $eventSeq
            || !\is_array($projection['documents'] ?? null)
            || !\is_array($projection['delete_keys'] ?? null)
        ) {
            throw new \UnexpectedValueException('product_search_incremental_projection_invalid');
        }
        $result = $this->store->applyChange(
            $websiteId,
            $eventSeq,
            $idempotencyKey,
            $projection['documents'],
            $projection['delete_keys'],
        );

        return $result + [
            'website_id' => $websiteId,
            'event_seq' => $eventSeq,
            'source_watermark' => (int)($projection['source_watermark'] ?? 0),
            'source_of_truth' => 'product_current_projection',
        ];
    }

    /** @param array<string,mixed> $event */
    private function seedLegacyHarnessEvent(int $websiteId, int $eventSeq, array $event): void
    {
        if (!$this->source instanceof ArrayProductSearchProjectionSource
            || !\is_array($event['document'] ?? null)
        ) {
            return;
        }
        $document = $event['document'];
        $document['website_id'] = $websiteId;
        $document['website_code'] = \trim((string)($document['website_code'] ?? ''))
            ?: ($websiteId === 0 ? 'default' : 'website-' . $websiteId);
        $document['entity_type'] = (string)($document['entity_type'] ?? 'product');
        if ((int)($document['store_id'] ?? 0) <= 0) {
            $document['store_id'] = 1;
        }
        $document['store_code'] = \trim((string)($document['store_code'] ?? ''))
            ?: 'default';
        if ((int)($document['channel_id'] ?? 0) <= 0) {
            $document['channel_id'] = 1;
        }
        $document['channel_code'] = \trim((string)($document['channel_code'] ?? ''))
            ?: 'default';
        $document['locale'] = (string)($document['locale'] ?? '');
        $document['currency'] = (string)($document['currency'] ?? '');
        $document['document_version'] = (int)(
            $document['document_version'] ?? $document['publish_version'] ?? $eventSeq
        );
        $identity = \array_intersect_key($document, \array_flip([
            'entity_type',
            'entity_id',
            'website_id',
            'website_code',
            'store_id',
            'store_code',
            'channel_id',
            'channel_code',
            'locale',
            'currency',
        ]));
        $this->source->seedChange($websiteId, $eventSeq, [
            'documents' => [$document],
            'delete_keys' => [$identity],
            'source_watermark' => $eventSeq,
        ]);
    }

    /** @param list<array<string,mixed>> $events @return list<array<string,mixed>> */
    public function applyMany(array $events): array
    {
        $results = [];
        foreach ($events as $event) {
            $results[] = $this->apply($event);
        }

        return $results;
    }
}
