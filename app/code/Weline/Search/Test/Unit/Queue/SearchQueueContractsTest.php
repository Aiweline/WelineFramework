<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Queue;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Api\BatchDrainingQueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Search\Api\SearchProjectionPendingDrainerInterface;
use Weline\Search\Queue\SearchIndexIncrementalQueue;
use Weline\Search\Service\ArrayProductSearchProjectionSource;
use Weline\Search\Service\SearchIndexBuilder;
use Weline\Search\Service\SearchIndexIncrementalApplier;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSearchQueueContractsPass().
 */
final class SearchQueueContractsTest extends TestCase
{
    public function testAllSearchQueueContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'SearchIndexIncrementalBatchDrain::testConsumerDeclaresBatchDrainingContract' => function () {
            $this->assert_SearchIndexIncrementalBatchDrain_testConsumerDeclaresBatchDrainingContract();
        },
            'SearchIndexIncrementalBatchDrain::testExecuteAppendsBatchDrainTelemetryWithoutChangingPrimaryResult' => function () {
            $this->assert_SearchIndexIncrementalBatchDrain_testExecuteAppendsBatchDrainTelemetryWithoutChangingPrimaryResult();
        },
            'SearchIndexIncrementalBatchDrain::testExecuteSkipsTelemetryWhenDrainerFindsNoSiblings' => function () {
            $this->assert_SearchIndexIncrementalBatchDrain_testExecuteSkipsTelemetryWhenDrainerFindsNoSiblings();
        },
        ];
    }

private function assert_SearchIndexIncrementalBatchDrain_testConsumerDeclaresBatchDrainingContract(): void
{
    $consumer = new SearchIndexIncrementalQueue(SearchIndexIncrementalApplier::forTesting(SearchIndexBuilder::forTesting()), $this->createMock(StoreCatalogInterface::class), $this->noopDrainer_SearchIndexIncrementalBatchDrain());
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Search';
    self::assertInstanceOf(BatchDrainingQueueConsumerInterface::class, $consumer);
    self::assertSame(100, SearchIndexIncrementalQueue::DEFAULT_BATCH_DRAIN_LIMIT);
    self::assertSame(SearchIndexIncrementalQueue::DEFAULT_BATCH_DRAIN_LIMIT, $consumer->batchDrainLimit());
    self::assertStringContainsString('BatchDrainingQueueConsumerInterface', (string) \file_get_contents($moduleRoot . '/Queue/SearchIndexIncrementalQueue.php'));
    self::assertFileExists($moduleRoot . '/Service/SearchProjectionPendingDrainer.php');
    self::assertFileExists($moduleRoot . '/Api/SearchProjectionPendingDrainerInterface.php');
    self::assertStringContainsString('SearchProjectionPendingDrainerInterface', (string) \file_get_contents($moduleRoot . '/etc/module.php'));
}

private function assert_SearchIndexIncrementalBatchDrain_testExecuteAppendsBatchDrainTelemetryWithoutChangingPrimaryResult(): void
{
    $source = ArrayProductSearchProjectionSource::forTesting();
    $source->seedSnapshot(0, [$this->document_SearchIndexIncrementalBatchDrain(1, 'v1')], 1);
    $builder = SearchIndexBuilder::forTesting(source: $source);
    $builder->rebuildWebsite(0);
    $source->seedChange(0, 2, ['documents' => [$this->document_SearchIndexIncrementalBatchDrain(2, 'v2')], 'delete_keys' => [$this->identity_SearchIndexIncrementalBatchDrain()], 'source_watermark' => 2]);
    $stores = $this->createMock(StoreCatalogInterface::class);
    $stores->method('byCode')->with(0, 'store-a')->willReturn($this->store_SearchIndexIncrementalBatchDrain());
    $drainer = new class implements SearchProjectionPendingDrainerInterface
    {
        public int $calls = 0;
        /** @var list<array{0:int,1:int}> */
        public array $args = [];
        public function drainSiblings(SearchIndexIncrementalQueue $consumer, int $excludeQueueId, int $limit): array
        {
            $this->calls++;
            $this->args[] = [$excludeQueueId, $limit];
            return ['drained' => 3, 'failed' => 1, 'remaining' => 2];
        }
    };
    $consumer = new SearchIndexIncrementalQueue(SearchIndexIncrementalApplier::forTesting($builder), $stores, $drainer);
    $queue = $this->queue_SearchIndexIncrementalBatchDrain($this->payload_SearchIndexIncrementalBatchDrain(), ScopeEnvelope::of(ScopeIdentity::store(0, 'default', 'store-a', ScopeIdentity::MODE_NORMAL)), 99);
    $result = $consumer->execute($queue);
    self::assertSame(1, $drainer->calls);
    self::assertSame([[99, SearchIndexIncrementalQueue::DEFAULT_BATCH_DRAIN_LIMIT]], $drainer->args);
    self::assertStringStartsWith('QUEUE_DONE: search_incremental_applied', $result);
    self::assertStringContainsString('batch_drained=3', $result);
    self::assertStringContainsString('batch_failed=1', $result);
    self::assertStringContainsString('batch_remaining=2', $result);
    self::assertSame('v2', $builder->store()->documentsForScope(0, 11, 111)[0]['title']);
}

private function assert_SearchIndexIncrementalBatchDrain_testExecuteSkipsTelemetryWhenDrainerFindsNoSiblings(): void
{
    $source = ArrayProductSearchProjectionSource::forTesting();
    $source->seedSnapshot(0, [$this->document_SearchIndexIncrementalBatchDrain(1, 'v1')], 1);
    $builder = SearchIndexBuilder::forTesting(source: $source);
    $builder->rebuildWebsite(0);
    $source->seedChange(0, 2, ['documents' => [$this->document_SearchIndexIncrementalBatchDrain(2, 'v2')], 'delete_keys' => [$this->identity_SearchIndexIncrementalBatchDrain()], 'source_watermark' => 2]);
    $stores = $this->createMock(StoreCatalogInterface::class);
    $stores->method('byCode')->with(0, 'store-a')->willReturn($this->store_SearchIndexIncrementalBatchDrain());
    $consumer = new SearchIndexIncrementalQueue(SearchIndexIncrementalApplier::forTesting($builder), $stores, $this->noopDrainer_SearchIndexIncrementalBatchDrain());
    $queue = $this->queue_SearchIndexIncrementalBatchDrain($this->payload_SearchIndexIncrementalBatchDrain(), ScopeEnvelope::of(ScopeIdentity::store(0, 'default', 'store-a', ScopeIdentity::MODE_NORMAL)));
    self::assertSame('QUEUE_DONE: search_incremental_applied', $consumer->execute($queue));
}

private function noopDrainer_SearchIndexIncrementalBatchDrain(): SearchProjectionPendingDrainerInterface
{
    return new class implements SearchProjectionPendingDrainerInterface
    {
        public function drainSiblings(SearchIndexIncrementalQueue $consumer, int $excludeQueueId, int $limit): array
        {
            return ['drained' => 0, 'failed' => 0, 'remaining' => 0];
        }
    };
}

/** @return array<string,mixed> */
private function payload_SearchIndexIncrementalBatchDrain(): array
{
    return ['contract' => SearchIndexIncrementalQueue::CONTRACT, 'event_id' => '0123456789abcdef0123456789abcdef', 'event_seq' => 2, 'target_type' => 'store_product', 'target_id' => 301];
}

/** @return array<string,mixed> */
private function document_SearchIndexIncrementalBatchDrain(int $version, string $title): array
{
    return $this->identity_SearchIndexIncrementalBatchDrain() + ['sku' => 'SAME-SKU', 'title' => $title, 'status' => 'published', 'document_version' => $version];
}

/** @return array<string,mixed> */
private function identity_SearchIndexIncrementalBatchDrain(): array
{
    return ['entity_type' => 'product', 'entity_id' => '301', 'website_id' => 0, 'website_code' => 'default', 'store_id' => 11, 'store_code' => 'store-a', 'channel_id' => 111, 'channel_code' => 'channel-a', 'locale' => '', 'currency' => ''];
}

private function store_SearchIndexIncrementalBatchDrain(): StoreSummary
{
    return new StoreSummary(11, 0, 'store-a', 'Store A', ScopeIdentity::MODE_NORMAL, true, true, 'active', null);
}

private function queue_SearchIndexIncrementalBatchDrain(array $payload, ScopeEnvelope $envelope, int $queueId = 1): QueueTaskContextInterface
{
    $queue = $this->createMock(QueueTaskContextInterface::class);
    $queue->method('getId')->willReturn($queueId);
    $queue->method('getContent')->willReturn((string) \json_encode($payload, \JSON_THROW_ON_ERROR));
    $queue->method('getScopeEnvelope')->willReturn($envelope);
    return $queue;
}
}
