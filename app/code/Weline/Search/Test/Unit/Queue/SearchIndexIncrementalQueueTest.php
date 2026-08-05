<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Queue;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Search\Queue\SearchIndexIncrementalQueue;
use Weline\Search\Service\ArrayProductSearchProjectionSource;
use Weline\Search\Service\SearchIndexBuilder;
use Weline\Search\Service\SearchIndexIncrementalApplier;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class SearchIndexIncrementalQueueTest extends TestCase
{
    public function testStoredScopeIsTheOnlyAuthorityAndReplayIsIdempotent(): void
    {
        $source = ArrayProductSearchProjectionSource::forTesting();
        $source->seedSnapshot(0, [$this->document(1, 'v1')], 1);
        $builder = SearchIndexBuilder::forTesting(source: $source);
        $builder->rebuildWebsite(0);
        $source->seedChange(0, 2, [
            'documents' => [$this->document(2, 'v2')],
            'delete_keys' => [$this->identity()],
            'source_watermark' => 2,
        ]);
        $stores = $this->createMock(StoreCatalogInterface::class);
        $stores->method('byCode')->with(0, 'store-a')->willReturn($this->store());
        $consumer = new SearchIndexIncrementalQueue(
            SearchIndexIncrementalApplier::forTesting($builder),
            $stores,
        );
        $queue = $this->queue(
            $this->payload(),
            ScopeEnvelope::of(ScopeIdentity::store(
                0,
                'default',
                'store-a',
                ScopeIdentity::MODE_NORMAL,
            )),
        );

        self::assertTrue($consumer->validate($queue));
        self::assertSame(
            'QUEUE_DONE: search_incremental_applied',
            $consumer->execute($queue),
        );
        self::assertSame(
            'v2',
            $builder->store()->documentsForScope(0, 11, 111)[0]['title'],
        );
        self::assertSame(
            'QUEUE_DONE: search_incremental_replayed',
            $consumer->execute($queue),
        );
        self::assertSame(2, $builder->store()->watermark(0)['incremental_watermark']);
    }

    public function testPayloadCannotCarryOrOverrideScopeDimensions(): void
    {
        $builder = SearchIndexBuilder::forTesting();
        $consumer = new SearchIndexIncrementalQueue(
            SearchIndexIncrementalApplier::forTesting($builder),
            $this->createMock(StoreCatalogInterface::class),
        );
        $payload = $this->payload() + [
            'website_id' => 99,
            'store_id' => 999,
            'channel_id' => 9999,
        ];
        $queue = $this->queue(
            $payload,
            ScopeEnvelope::of(ScopeIdentity::website(0, 'default')),
        );

        self::assertFalse($consumer->validate($queue));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('search_incremental_content_fields_invalid');
        $consumer->execute($queue);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'contract' => SearchIndexIncrementalQueue::CONTRACT,
            'event_id' => '0123456789abcdef0123456789abcdef',
            'event_seq' => 2,
            'target_type' => 'store_product',
            'target_id' => 301,
        ];
    }

    /** @return array<string,mixed> */
    private function document(int $version, string $title): array
    {
        return $this->identity() + [
            'sku' => 'SAME-SKU',
            'title' => $title,
            'status' => 'published',
            'document_version' => $version,
        ];
    }

    /** @return array<string,mixed> */
    private function identity(): array
    {
        return [
            'entity_type' => 'product',
            'entity_id' => '301',
            'website_id' => 0,
            'website_code' => 'default',
            'store_id' => 11,
            'store_code' => 'store-a',
            'channel_id' => 111,
            'channel_code' => 'channel-a',
            'locale' => '',
            'currency' => '',
        ];
    }

    private function store(): StoreSummary
    {
        return new StoreSummary(
            11,
            0,
            'store-a',
            'Store A',
            ScopeIdentity::MODE_NORMAL,
            true,
            true,
            'active',
            null,
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function queue(
        array $payload,
        ScopeEnvelope $scope,
    ): QueueTaskContextInterface {
        $queue = $this->createMock(QueueTaskContextInterface::class);
        $queue->method('getContent')->willReturn((string)\json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        $queue->method('getScopeEnvelope')->willReturn($scope);

        return $queue;
    }
}
