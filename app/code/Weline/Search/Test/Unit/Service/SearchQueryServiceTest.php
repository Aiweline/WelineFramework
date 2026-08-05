<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Search\Service\ArrayProductDirectCatalogReader;
use Weline\Search\Service\SearchIndexBuilder;
use Weline\Search\Service\SearchQueryException;
use Weline\Search\Service\SearchQueryService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/**
 * TEST-P3C-02/03/04：完整 Scope、真实空集证据、degraded 与 recovery gate。
 */
final class SearchQueryServiceTest extends TestCase
{
    public function testModeOffDefaultsToProductCurrentWithSnapshotEvidence(): void
    {
        $direct = ArrayProductDirectCatalogReader::forTesting([
            [
                'website_id' => 0,
                'store_id' => 1,
                'channel_id' => 1,
                'entity_id' => '1',
                'sku' => 'A',
                'title' => '默认站',
                'locale' => 'zh_Hans_CN',
                'currency' => 'CNY',
                'document_version' => 4,
            ],
        ]);
        $service = SearchQueryService::forTesting(
            SearchIndexBuilder::forTesting(),
            $direct,
        );
        $result = $service->search($this->query());

        self::assertTrue($result['ok']);
        self::assertSame(SearchQueryService::SOURCE_DIRECT, $result['source']);
        self::assertFalse($result['degraded']);
        self::assertSame(1, $result['hit_count']);
        self::assertSame(4, $result['direct_source_watermark']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $result['direct_snapshot_hash'],
        );
        self::assertSame(1, $result['direct_document_count']);
        self::assertSame(1, $result['direct_match_count']);
        self::assertFalse($service->degrade()->isActive(0));
    }

    public function testIndexUsesExactStoreChannelLocaleCurrencyAndNeutralFallback(): void
    {
        $builder = SearchIndexBuilder::forTesting();
        $builder->registry()->ensureWebsite(1);
        $builder->rebuildWebsite(0, [
            $this->document('10', 0, 1, 1, 'zh_Hans_CN', 'CNY', '零号站中文'),
            $this->document('11', 0, 1, 1, 'en_US', 'USD', 'Zero English'),
            $this->document('12', 0, 1, 2, 'zh_Hans_CN', 'CNY', '另一渠道'),
            $this->document('13', 0, 1, 1, '', '', '中性 SKU'),
            $this->document('13', 0, 1, 1, 'zh_Hans_CN', 'CNY', '中文覆盖'),
        ]);
        $builder->rebuildWebsite(1, [
            $this->document('20', 1, 1, 1, 'zh_Hans_CN', 'CNY', '一号站商品'),
        ]);

        $service = SearchQueryService::forTesting($builder);
        $service->rollout()->setMode(
            SearchQueryService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['0:1:1', '0:1:2', '1:1:1'],
        );

        $zh = $service->search($this->query());
        self::assertSame(SearchQueryService::SOURCE_INDEX, $zh['source']);
        self::assertSame(['10', '13'], $this->ids($zh['hits']));
        self::assertSame('中文覆盖', $zh['hits'][1]['title']);
        self::assertSame('exact', $zh['hits'][1]['dimension_source']);

        $english = $service->search($this->query(locale: 'en_US', currency: 'USD'));
        self::assertSame(['11', '13'], $this->ids($english['hits']));
        self::assertSame('中性 SKU', $english['hits'][1]['title']);
        self::assertSame('neutral', $english['hits'][1]['dimension_source']);

        $channelTwo = $service->search($this->query(channelId: 2));
        self::assertSame(['12'], $this->ids($channelTwo['hits']));

        $siteOne = $service->search($this->query(websiteId: 1));
        self::assertSame(['20'], $this->ids($siteOne['hits']));
        foreach ($siteOne['hits'] as $hit) {
            self::assertSame(1, (int)$hit['website_id']);
        }
    }

    public function testIndexUnavailableDegradesWithDurableMarkerAndDirectEvidence(): void
    {
        $builder = SearchIndexBuilder::forTesting();
        $builder->rebuildWebsite(0, [
            $this->document('30', 0, 1, 1, '', '', '索引商品', 2),
        ]);
        $direct = ArrayProductDirectCatalogReader::forTesting([
            [
                'website_id' => 0,
                'store_id' => 1,
                'channel_id' => 1,
                'entity_id' => '30',
                'sku' => 'IDX',
                'title' => '直读商品',
                'document_version' => 2,
            ],
        ]);
        $service = SearchQueryService::forTesting($builder, $direct);
        $service->rollout()->setMode(
            SearchQueryService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['0:1:1'],
        );
        $service->forceIndexDown(true);

        $result = $service->search($this->query());
        self::assertTrue($result['ok']);
        self::assertTrue($result['degraded']);
        self::assertSame(SearchQueryService::SOURCE_DEGRADED, $result['source']);
        self::assertSame('index_forced_down', $result['degrade_reason']);
        self::assertSame(1, $result['hit_count']);
        self::assertSame('直读商品', $result['hits'][0]['title']);
        self::assertTrue($result['degrade_marker_persisted']);
        self::assertTrue($service->degrade()->isActive(0));
        self::assertSame(1, $result['direct_document_count']);
        self::assertSame(1, $result['direct_match_count']);

        $service->forceIndexDown(false);
        $stillDegraded = $service->search($this->query());
        self::assertSame(SearchQueryService::SOURCE_DEGRADED, $stillDegraded['source']);
        self::assertSame('index_forced_down', $stillDegraded['degrade_reason']);
    }

    public function testLegitimateDirectEmptyCarriesSnapshotProof(): void
    {
        $service = SearchQueryService::forTesting(
            SearchIndexBuilder::forTesting(),
            ArrayProductDirectCatalogReader::forTesting(),
        );
        $result = $service->search($this->query(q: 'not-found'));

        self::assertSame(SearchQueryService::SOURCE_DIRECT, $result['source']);
        self::assertSame(0, $result['hit_count']);
        self::assertSame(0, $result['direct_document_count']);
        self::assertSame(0, $result['direct_match_count']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $result['direct_snapshot_hash'],
        );
    }

    public function testDirectReaderFailureNeverReturnsEmptySuccess(): void
    {
        $builder = SearchIndexBuilder::forTesting();
        $builder->rebuildWebsite(0, []);
        $direct = ArrayProductDirectCatalogReader::forTesting();
        $direct->markDown(true);
        $service = SearchQueryService::forTesting($builder, $direct);
        $service->rollout()->setMode(
            SearchQueryService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['0:1:1'],
        );
        $service->forceIndexDown(true);

        $this->expectException(SearchQueryException::class);
        $this->expectExceptionMessage('Product 直读不可用');
        $service->search($this->query());
    }

    public function testRecoveryRequiresCurrentProductAndIndexWatermarksToMatch(): void
    {
        $service = SearchQueryService::forTesting();
        $service->degrade()->mark(0, 'consumer_stopped', 9, 5);
        self::assertTrue($service->degrade()->isActive(0));

        try {
            $service->degrade()->clearIfRecovered(0, 8, 9);
            self::fail('lagging index must not clear marker');
        } catch (SearchQueryException $exception) {
            self::assertSame(
                SearchQueryException::ERROR_RECOVERY_WATERMARK,
                $exception->errorCode,
            );
        }
        self::assertTrue($service->degrade()->isActive(0));

        $cleared = $service->degrade()->clearIfRecovered(0, 9, 9);
        self::assertFalse($cleared['active']);
        self::assertFalse($service->degrade()->isActive(0));
    }

    public function testShadowKeepsStorefrontOnProductDirect(): void
    {
        $builder = SearchIndexBuilder::forTesting();
        $builder->rebuildWebsite(0, [
            $this->document('70', 0, 1, 1, '', '', '索引值'),
        ]);
        $direct = ArrayProductDirectCatalogReader::forTesting([
            [
                'website_id' => 0,
                'store_id' => 1,
                'channel_id' => 1,
                'entity_id' => '70',
                'sku' => 'S70',
                'title' => '直读值',
            ],
        ]);
        $service = SearchQueryService::forTesting($builder, $direct);
        $service->rollout()->setMode(
            SearchQueryService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_SHADOW,
        );

        $result = $service->search($this->query());
        self::assertSame(SearchQueryService::SOURCE_DIRECT, $result['source']);
        self::assertSame('直读值', $result['hits'][0]['title']);

        $preview = $service->previewIndexForShadow($this->query());
        self::assertSame(SearchQueryService::SOURCE_INDEX, $preview['source']);
        self::assertSame('索引值', $preview['hits'][0]['title']);
    }

    public function testCompleteScopeIsRequiredAndWebsiteZeroRemainsValid(): void
    {
        $service = SearchQueryService::forTesting();
        $valid = $service->search($this->query(websiteId: 0));
        self::assertSame(0, $valid['website_id']);

        $this->expectException(SearchQueryException::class);
        $service->search([
            'website_id' => 0,
            'store_id' => 1,
            'locale' => 'zh_Hans_CN',
            'currency' => 'CNY',
        ]);
    }

    /**
     * @return array{website_id:int,store_id:int,channel_id:int,locale:string,currency:string,q:string}
     */
    private function query(
        int $websiteId = 0,
        int $storeId = 1,
        int $channelId = 1,
        string $locale = 'zh_Hans_CN',
        string $currency = 'CNY',
        string $q = '',
    ): array {
        return [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'channel_id' => $channelId,
            'locale' => $locale,
            'currency' => $currency,
            'q' => $q,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function document(
        string $entityId,
        int $websiteId,
        int $storeId,
        int $channelId,
        string $locale,
        string $currency,
        string $title,
        int $version = 1,
    ): array {
        return [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'channel_id' => $channelId,
            'entity_id' => $entityId,
            'sku' => 'SKU-' . $entityId,
            'title' => $title,
            'locale' => $locale,
            'currency' => $currency,
            'document_version' => $version,
        ];
    }

    /**
     * @param list<array<string,mixed>> $hits
     * @return list<string>
     */
    private function ids(array $hits): array
    {
        return \array_values(\array_map(
            static fn(array $hit): string => (string)$hit['entity_id'],
            $hits,
        ));
    }
}
