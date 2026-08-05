<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\ProductSearchProjectionSourceInterface;
use Weline\Search\Model\SearchShardKey;

/**
 * Mutable deterministic Product source for concurrency-focused unit tests.
 */
final class ArrayProductSearchProjectionSource implements ProductSearchProjectionSourceInterface
{
    /** @var array<int,int> */
    private array $watermarks = [];

    /** @var array<int,list<array<string,mixed>>> */
    private array $documents = [];

    /** @var array<int,array<int,array<string,mixed>>> */
    private array $changes = [];

    /** @var null|callable(self,int):void */
    private mixed $snapshotHook = null;

    public static function forTesting(): self
    {
        return new self();
    }

    /** @param list<array<string,mixed>> $documents */
    public function seedSnapshot(int $websiteId, array $documents, int $watermark): void
    {
        SearchShardKey::fromWebsiteId($websiteId);
        if ($watermark < 0) {
            throw new \InvalidArgumentException('product_source_watermark_invalid');
        }
        $this->documents[$websiteId] = \array_values($documents);
        $this->watermarks[$websiteId] = $watermark;
    }

    /** @param array<string,mixed> $change */
    public function seedChange(int $websiteId, int $eventSeq, array $change): void
    {
        $this->changes[$websiteId][$eventSeq] = $change + [
            'contract' => 'product.search_projection_change.v1',
            'website_id' => $websiteId,
            'event_seq' => $eventSeq,
            'source_watermark' => \max($eventSeq, $this->watermarks[$websiteId] ?? 0),
            'documents' => [],
            'delete_keys' => [],
        ];
        $this->watermarks[$websiteId] = \max($eventSeq, $this->watermarks[$websiteId] ?? 0);
    }

    public function onSnapshot(?callable $hook): void
    {
        $this->snapshotHook = $hook;
    }

    public function currentWatermark(int $websiteId): int
    {
        SearchShardKey::fromWebsiteId($websiteId);

        return $this->watermarks[$websiteId] ?? 0;
    }

    public function snapshotWebsite(int $websiteId): array
    {
        if ($this->snapshotHook !== null) {
            ($this->snapshotHook)($this, $websiteId);
        }
        $documents = $this->documents[$websiteId] ?? [];

        return [
            'contract' => 'product.search_projection_snapshot.v1',
            'website_id' => $websiteId,
            'source_watermark' => $this->currentWatermark($websiteId),
            'scope_count' => $documents === [] ? 1 : \count($documents),
            'document_count' => \count($documents),
            'documents' => $documents,
            'snapshot_hash' => \hash(
                'sha256',
                (string)\json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ),
        ];
    }

    public function projectChange(array $change): array
    {
        $websiteId = (int)($change['website_id'] ?? -1);
        $eventSeq = (int)($change['event_seq'] ?? 0);
        $seeded = $this->changes[$websiteId][$eventSeq] ?? null;
        if ($seeded === null) {
            throw new \RuntimeException('product_source_change_missing');
        }

        return $seeded;
    }
}
