<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Search\Service\ArrayProductSearchProjectionSource;
use Weline\Search\Service\SearchIndexBuilder;
use Weline\Search\Service\SearchIndexIncrementalApplier;

final class SearchCoalescedEventCoverageTest extends TestCase
{
    private ?object $previousEvents = null;

    protected function setUp(): void
    {
        $class = \Weline\Framework\Event\EventsManager::class;
        $this->previousEvents = \Weline\Framework\Manager\ObjectManager::_getInstance($class);
        $events = $this->createMock($class);
        \Weline\Framework\Manager\ObjectManager::setInstance($class, $events);
    }

    protected function tearDown(): void
    {
        $class = \Weline\Framework\Event\EventsManager::class;
        if ($this->previousEvents !== null) {
            \Weline\Framework\Manager\ObjectManager::setInstance($class, $this->previousEvents);
        } else {
            (new \ReflectionMethod(\Weline\Framework\Manager\ObjectManager::class, 'removeScopedInstance'))
                ->invoke(null, $class);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testLatestProjectionAcknowledgesEarlierEventsForTheSameProduct(string $backend): void
    {
        [$source, $store, $applier] = $this->runtime($backend);
        $this->seed($source, 3, 301);

        $event = $this->event(3, 301, [2]);
        $queue = $this->createMock(\Weline\Queue\Api\QueueTaskContextInterface::class);
        $queue->method('getContent')->willReturn(json_encode([
            'contract' => 'search.incremental_queue.v1',
            'event_id' => substr($event['idempotency_key'], strlen('resource-change:')),
            'event_seq' => 3, 'target_type' => 'product', 'target_id' => 301,
            'covered_events' => $event['covered_events'],
        ], JSON_THROW_ON_ERROR));
        $queue->method('getScopeEnvelope')->willReturn(\Weline\Framework\Runtime\ScopeEnvelope::of(
            \Weline\Framework\Runtime\ScopeIdentity::website(0, 'default'),
        ));
        $consumer = new \Weline\Search\Queue\SearchIndexIncrementalQueue(
            $applier,
            $this->createMock(\Weline\Websites\Api\Catalog\StoreCatalogInterface::class),
            $this->createMock(\Weline\Search\Api\SearchProjectionPendingDrainerInterface::class),
        );
        self::assertTrue($consumer->validate($queue));
        self::assertSame('QUEUE_DONE: search_incremental_applied', $consumer->applyEvent($queue));

        self::assertSame('v3', $store->documentsForScope(0, 11, 111)[0]['title']);
        self::assertSame(3, $store->watermark(0)['incremental_watermark']);
        self::assertTrue($applier->apply($this->event(3, 301, [2]))['replayed']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testCoalescedProductCannotJumpPastAnotherMissingProductEvent(string $backend): void
    {
        [$source, $store, $applier] = $this->runtime($backend);
        $this->seed($source, 4, 301);
        $applier->apply($this->event(4, 301, [3]));
        self::assertSame(1, $store->watermark(0)['incremental_watermark']);

        $this->seed($source, 2, 302);
        $applier->apply($this->event(2, 302));
        self::assertSame(4, $store->watermark(0)['incremental_watermark']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testProjectionFailureDoesNotAcknowledgeAnyCoveredEvent(string $backend): void
    {
        [$source, $store, $applier] = $this->runtime($backend);
        try {
            $applier->apply($this->event(3, 301, [2]));
            self::fail('缺少最终投影时不能确认被合并事件');
        } catch (\RuntimeException $exception) {
            self::assertSame('product_source_change_missing', $exception->getMessage());
        }
        self::assertSame(1, $store->watermark(0)['incremental_watermark']);
        self::assertSame([], $store->documentsForWebsite(0));

        $this->seed($source, 2, 301);
        $applier->apply($this->event(2, 301));
        self::assertSame(2, $store->watermark(0)['incremental_watermark']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testPartialDocumentWriteRollsBackCoverageAndDocuments(string $backend): void
    {
        [$source, $store, $applier] = $this->runtime($backend);
        $this->seed($source, 3, 301);
        $projection = $source->projectChange(['website_id' => 0, 'event_seq' => 3]);
        $conflict = $projection['documents'][0];
        $conflict['title'] = 'same-version-conflict';
        $projection['documents'][] = $conflict;
        $source->seedChange(0, 3, $projection);
        try {
            $applier->apply($this->event(3, 301, [2]));
            self::fail('第二条文档失败时必须回滚首条写入');
        } catch (\RuntimeException $exception) {
            self::assertSame('search_document_same_version_payload_conflict', $exception->getMessage());
        }
        self::assertSame([], $store->documentsForWebsite(0));
        self::assertSame(1, $store->watermark(0)['incremental_watermark']);
        $this->seed($source, 2, 301);
        $applier->apply($this->event(2, 301));
        self::assertSame(2, $store->watermark(0)['incremental_watermark']);
    }

    public function testInterleavedAdmissionPreservesBothWritersCoverage(): void
    {
        $script = dirname(__DIR__, 2) . '/fixtures/coalesced-admission.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' race', $lines, $status);
        self::assertSame(0, $status);
        self::assertSame(['latest' => 4, 'covered' => [2, 3]], json_decode(implode('', $lines), true));
    }

    public static function backends(): array
    {
        return [['memory'], ['sqlite']];
    }

    private function runtime(string $backend): array
    {
        $source = ArrayProductSearchProjectionSource::forTesting();
        $source->seedSnapshot(0, [], 1);
        $builder = SearchIndexBuilder::forTesting(source: $source);
        $builder->rebuildWebsite(0);
        if ($backend === 'memory') {
            return [$source, $builder->store(), SearchIndexIncrementalApplier::forTesting($builder)];
        }
        $connection = \Weline\Framework\Database\ConnectionFactory::getInstance(
            new \Weline\Framework\Database\DbManager\ConfigProvider([
                'type' => 'sqlite', 'database' => '', 'path' => ':memory:', 'persistent' => false,
            ]),
        );
        $connector = $connection->getConnector();
        foreach (['document', 'watermark', 'applied_event'] as $entity) {
            $connector->query('DROP TABLE IF EXISTS search_ws_0_' . $entity)->fetch();
        }
        $connector->query("CREATE TABLE search_ws_0_document (
            document_id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT, entity_id TEXT, website_id INTEGER, website_code TEXT,
            store_id INTEGER, store_code TEXT, channel_id INTEGER, channel_code TEXT,
            locale TEXT, currency TEXT, generation INTEGER, document_version INTEGER,
            payload_hash TEXT, title TEXT, sku TEXT, status TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(generation,entity_type,entity_id,store_id,channel_id,locale,currency))")->fetch();
        $connector->query("CREATE TABLE search_ws_0_watermark (
            watermark_id INTEGER PRIMARY KEY AUTOINCREMENT, website_id INTEGER UNIQUE,
            active_generation INTEGER, build_generation INTEGER, build_source_watermark INTEGER,
            full_watermark INTEGER, incremental_watermark INTEGER, build_token TEXT,
            build_status TEXT, shard_fingerprint TEXT, row_version INTEGER,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)")->fetch();
        $connector->query("CREATE TABLE search_ws_0_applied_event (
            applied_event_id INTEGER PRIMARY KEY AUTOINCREMENT, generation INTEGER, event_seq INTEGER,
            idempotency_key TEXT, payload_hash TEXT, applied_at DATETIME,
            UNIQUE(generation,event_seq), UNIQUE(generation,idempotency_key))")->fetch();
        $model = static function (string $class) use ($connection) {
            $model = new $class();
            $model->setConnection($connection);
            $model->__init();
            return $model;
        };
        $store = new \Weline\Search\Service\DatabaseSearchIndexStore(
            $model(\Weline\Search\Model\Shard\SearchDocument::class),
            $model(\Weline\Search\Model\Shard\SearchWatermark::class),
            $model(\Weline\Search\Model\Shard\SearchAppliedEvent::class),
            new \Weline\Framework\Database\Transaction\TransactionCoordinator(),
        );
        $build = $store->beginBuild(0, 1, hash('sha256', 'coverage-test'));
        $store->commitBuild(0, $build['generation'], $build['build_token'], 1, static fn(): int => 1);
        return [$source, $store, new SearchIndexIncrementalApplier($builder->registry(), $store, $source)];
    }

    private function seed(ArrayProductSearchProjectionSource $source, int $seq, int $productId): void
    {
        $source->seedChange(0, $seq, [
            'documents' => [[
                'entity_type' => 'product', 'entity_id' => (string)$productId,
                'website_id' => 0, 'website_code' => 'default',
                'store_id' => 11, 'store_code' => 'store-a',
                'channel_id' => 111, 'channel_code' => 'channel-a',
                'locale' => '', 'currency' => '', 'title' => 'v' . $seq,
                'sku' => 'coverage-probe', 'status' => 'published', 'document_version' => $seq,
            ]],
            'delete_keys' => [], 'source_watermark' => $seq,
        ]);
    }

    private function event(int $seq, int $productId, array $covered = []): array
    {
        return [
            'website_id' => 0, 'event_seq' => $seq,
            'idempotency_key' => 'resource-change:' . str_pad((string)$seq, 32, '0', STR_PAD_LEFT),
            'target_type' => 'product', 'target_id' => $productId,
            'covered_events' => array_map(static fn(int $coveredSeq): array => [
                'event_seq' => $coveredSeq,
                'event_id' => str_pad((string)$coveredSeq, 32, '0', STR_PAD_LEFT),
            ], $covered),
        ];
    }
}
