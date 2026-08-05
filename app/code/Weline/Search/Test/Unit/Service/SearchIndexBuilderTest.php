<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Search\Extends\Module\Weline_Framework\Schema\SearchShardSchemaProvider;
use Weline\Search\Model\SearchShardKey;
use Weline\Search\Service\ArrayProductSearchProjectionSource;
use Weline\Search\Service\SearchIndexBuilder;
use Weline\Search\Service\SearchIndexIncrementalApplier;
use Weline\Search\Service\SearchShardSchemaCatalog;

/**
 * TEST-P3C-01/02: staged generation, contiguous Queue watermark and Scope isolation.
 */
final class SearchIndexBuilderTest extends TestCase
{
    public function testSchemaProviderUsesUniqueFamilyAndDeclaresV2ShardTables(): void
    {
        $provider = SearchShardSchemaProvider::forTesting();

        self::assertSame(SearchShardKey::FAMILY_CODE, $provider->getFamilyCode());
        self::assertSame(SearchShardSchemaCatalog::SCHEMA_VERSION, $provider->getSchemaVersion());
        self::assertSame(['0'], $provider->getRegisteredShardKeys());
        self::assertSame(
            [
                'search_ws_0_document',
                'search_ws_0_watermark',
                'search_ws_0_applied_event',
            ],
            array_map(
                static fn($schema): string => $schema->tableName,
                $provider->getTableSchemasForShard('0'),
            ),
        );
        self::assertSame(
            'search_ws_0_applied_event',
            SearchShardKey::tableName('0', 'applied_event'),
        );
        $this->expectException(\InvalidArgumentException::class);
        SearchShardKey::tableName('0', 'invented_table');
    }

    public function testFullBuildRetriesChangedSnapshotAndAtomicallyCommitsLatestGeneration(): void
    {
        $source = ArrayProductSearchProjectionSource::forTesting();
        $source->seedSnapshot(0, [$this->document('101', 1, 11, 111, '初始')], 1);
        $mutated = false;
        $source->onSnapshot(function (
            ArrayProductSearchProjectionSource $source,
            int $websiteId,
        ) use (&$mutated): void {
            if ($mutated) {
                return;
            }
            $mutated = true;
            $source->seedSnapshot(
                $websiteId,
                [$this->document('101', 2, 11, 111, '并发后的当前值')],
                2,
            );
        });
        $builder = SearchIndexBuilder::forTesting(source: $source);

        $result = $builder->rebuildWebsite(0);

        self::assertTrue($result['ok']);
        self::assertSame(2, $result['attempts']);
        self::assertSame(2, $result['source_watermark']);
        self::assertSame(1, $result['generation']);
        self::assertSame(1, $result['document_count']);
        self::assertSame('product_current_projection', $result['source_of_truth']);
        self::assertSame(1, $result['watermark']['active_generation']);
        self::assertSame(2, $result['watermark']['full_watermark']);
        self::assertSame(2, $result['watermark']['incremental_watermark']);
        self::assertSame(
            '并发后的当前值',
            $builder->store()->documentsForScope(0, 11, 111)[0]['title'],
        );
    }

    public function testOutOfOrderEventsAdvanceOnlyContiguousAndNeverOverwriteNewerDocument(): void
    {
        $source = ArrayProductSearchProjectionSource::forTesting();
        $source->seedSnapshot(0, [$this->document('201', 2, 11, 111, 'v2')], 2);
        $builder = SearchIndexBuilder::forTesting(source: $source);
        $builder->rebuildWebsite(0);
        $identity = $this->identity('201', 11, 111);
        $current = $this->document('201', 4, 11, 111, 'v4');
        $source->seedChange(0, 3, [
            'documents' => [$current],
            'delete_keys' => [$identity],
            'source_watermark' => 4,
        ]);
        $source->seedChange(0, 4, [
            'documents' => [$current],
            'delete_keys' => [$identity],
            'source_watermark' => 4,
        ]);
        $applier = SearchIndexIncrementalApplier::forTesting($builder);

        $four = $applier->apply($this->event(4, 'evt-4'));
        self::assertTrue($four['applied']);
        self::assertSame(2, $four['watermark']['incremental_watermark']);
        self::assertSame('v4', $builder->store()->documentsForScope(0, 11, 111)[0]['title']);

        $three = $applier->apply($this->event(3, 'evt-3'));
        self::assertTrue($three['applied']);
        self::assertSame(4, $three['watermark']['incremental_watermark']);
        self::assertSame('v4', $builder->store()->documentsForScope(0, 11, 111)[0]['title']);

        $replay = $applier->apply($this->event(4, 'evt-4'));
        self::assertTrue($replay['replayed']);
        self::assertFalse($replay['applied']);
        self::assertSame(4, $replay['watermark']['incremental_watermark']);
    }

    public function testSameSkuDifferentStoreChannelProjectionsAreStrictlyIsolated(): void
    {
        $source = ArrayProductSearchProjectionSource::forTesting();
        $source->seedSnapshot(0, [
            $this->document('301', 7, 11, 111, 'A 投影', 'SAME-SKU'),
            $this->document('301', 7, 12, 121, 'B 投影', 'SAME-SKU'),
        ], 7);
        $builder = SearchIndexBuilder::forTesting(source: $source);
        $builder->rebuildWebsite(0);

        $scopeA = $builder->store()->documentsForScope(0, 11, 111);
        $scopeB = $builder->store()->documentsForScope(0, 12, 121);

        self::assertCount(1, $scopeA);
        self::assertCount(1, $scopeB);
        self::assertSame('SAME-SKU', $scopeA[0]['sku']);
        self::assertSame('SAME-SKU', $scopeB[0]['sku']);
        self::assertSame('A 投影', $scopeA[0]['title']);
        self::assertSame('B 投影', $scopeB[0]['title']);
        self::assertSame(11, (int)$scopeA[0]['store_id']);
        self::assertSame(111, (int)$scopeA[0]['channel_id']);
        self::assertSame(12, (int)$scopeB[0]['store_id']);
        self::assertSame(121, (int)$scopeB[0]['channel_id']);
    }

    /** @return array<string,mixed> */
    private function document(
        string $entityId,
        int $version,
        int $storeId,
        int $channelId,
        string $title,
        string $sku = 'SKU',
    ): array {
        return $this->identity($entityId, $storeId, $channelId) + [
            'sku' => $sku,
            'title' => $title,
            'status' => 'published',
            'document_version' => $version,
        ];
    }

    /** @return array<string,mixed> */
    private function identity(string $entityId, int $storeId, int $channelId): array
    {
        return [
            'entity_type' => 'product',
            'entity_id' => $entityId,
            'website_id' => 0,
            'website_code' => 'default',
            'store_id' => $storeId,
            'store_code' => 'store-' . $storeId,
            'channel_id' => $channelId,
            'channel_code' => 'channel-' . $channelId,
            'locale' => '',
            'currency' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function event(int $sequence, string $idempotencyKey): array
    {
        return [
            'website_id' => 0,
            'event_seq' => $sequence,
            'idempotency_key' => $idempotencyKey,
            'target_type' => 'product',
            'target_id' => 201,
        ];
    }
}
