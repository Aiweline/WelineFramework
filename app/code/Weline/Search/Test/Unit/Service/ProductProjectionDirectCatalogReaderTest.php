<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Search\Api\ProductSearchProjectionSourceInterface;
use Weline\Search\Service\ProductProjectionDirectCatalogReader;
use Weline\Search\Service\SearchQueryException;

final class ProductProjectionDirectCatalogReaderTest extends TestCase
{
    public function testReadsPublishedCurrentByExactScopeAndNeutralDimensions(): void
    {
        $documents = [
            $this->document('1', 1, 10, '', '', 'Neutral'),
            $this->document('2', 1, 10, 'zh_Hans_CN', 'CNY', '中文'),
            $this->document('3', 1, 10, 'en_US', 'USD', 'English'),
            $this->document('4', 1, 11, 'zh_Hans_CN', 'CNY', 'Other Channel'),
            $this->document('5', 2, 20, 'zh_Hans_CN', 'CNY', 'Other Store'),
        ];
        $reader = new ProductProjectionDirectCatalogReader(
            new ProductProjectionSourceStub($documents, 12),
        );

        $read = $reader->searchPublished([
            'website_id' => 0,
            'store_id' => 1,
            'channel_id' => 10,
            'locale' => 'zh_Hans_CN',
            'currency' => 'CNY',
            'q' => '',
        ]);

        self::assertSame(12, $read->sourceWatermark);
        self::assertSame(5, $read->sourceDocumentCount);
        self::assertSame(['1', '2'], \array_column($read->hits, 'entity_id'));
        self::assertSame('neutral', $read->hits[0]['dimension_source']);
        self::assertSame('exact', $read->hits[1]['dimension_source']);
        self::assertSame('zh_Hans_CN', $read->hits[0]['requested_locale']);
        self::assertSame('CNY', $read->hits[0]['requested_currency']);
    }

    public function testPartialLocalizationDimensionFailsClosed(): void
    {
        $reader = new ProductProjectionDirectCatalogReader(
            new ProductProjectionSourceStub([
                $this->document('1', 1, 10, 'zh_Hans_CN', '', 'Invalid'),
            ], 1),
        );

        try {
            $reader->searchPublished([
                'website_id' => 0,
                'store_id' => 1,
                'channel_id' => 10,
                'locale' => 'zh_Hans_CN',
                'currency' => 'CNY',
            ]);
            self::fail('partial locale/currency identity must fail');
        } catch (SearchQueryException $exception) {
            self::assertSame(
                SearchQueryException::ERROR_DIRECT_CONTRACT,
                $exception->errorCode,
            );
        }
    }

    /** @return array<string,mixed> */
    private function document(
        string $entityId,
        int $storeId,
        int $channelId,
        string $locale,
        string $currency,
        string $title,
    ): array {
        return [
            'entity_type' => 'product',
            'entity_id' => $entityId,
            'website_id' => 0,
            'store_id' => $storeId,
            'channel_id' => $channelId,
            'locale' => $locale,
            'currency' => $currency,
            'title' => $title,
            'sku' => 'SKU-' . $entityId,
            'status' => 'published',
            'document_version' => 1,
        ];
    }
}

final class ProductProjectionSourceStub implements ProductSearchProjectionSourceInterface
{
    /** @param list<array<string,mixed>> $documents */
    public function __construct(
        private readonly array $documents,
        private readonly int $watermark,
    ) {
    }

    public function currentWatermark(int $websiteId): int
    {
        return $this->watermark;
    }

    public function snapshotWebsite(int $websiteId): array
    {
        return [
            'contract' => 'product.search_projection_snapshot.v1',
            'website_id' => $websiteId,
            'source_watermark' => $this->watermark,
            'scope_count' => 1,
            'document_count' => \count($this->documents),
            'documents' => $this->documents,
            'snapshot_hash' => \hash('sha256', (string)\json_encode(
                $this->documents,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )),
        ];
    }

    public function projectChange(array $change): array
    {
        return [];
    }
}
