<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\ProductDirectCatalogRead;
use Weline\Search\Api\ProductDirectCatalogReaderInterface;
use Weline\Search\Api\ProductSearchProjectionSourceInterface;
use Weline\Search\Model\SearchShardKey;

/**
 * Production Product current reader.
 *
 * 跨模块数据只通过 Product 已发布的 product_search_projection Query 契约读取。
 */
final class ProductProjectionDirectCatalogReader implements ProductDirectCatalogReaderInterface
{
    public function __construct(
        private readonly ProductSearchProjectionSourceInterface $source,
    ) {
    }

    public function searchPublished(array $query): ProductDirectCatalogRead
    {
        $websiteId = (int)($query['website_id'] ?? -1);
        SearchShardKey::fromWebsiteId($websiteId);
        $storeId = (int)($query['store_id'] ?? 0);
        $channelId = (int)($query['channel_id'] ?? 0);
        $locale = \trim((string)($query['locale'] ?? ''));
        $currency = \strtoupper(\trim((string)($query['currency'] ?? '')));
        if ($storeId < 1 || $channelId < 1 || $locale === '' || $currency === '') {
            throw new SearchQueryException(
                SearchQueryException::ERROR_SCOPE,
                (string)__('Search Product 直读要求完整 Store/Channel/locale/currency Scope'),
                [
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                    'channel_id' => $channelId,
                ],
            );
        }

        try {
            $snapshot = $this->source->snapshotWebsite($websiteId);
        } catch (\Throwable $exception) {
            throw new SearchQueryException(
                SearchQueryException::ERROR_DIRECT_READER_DOWN,
                (string)__('Product 目录直读不可用'),
                ['website_id' => $websiteId],
                $exception,
            );
        }

        $documents = $snapshot['documents'] ?? null;
        $sourceWatermark = $snapshot['source_watermark'] ?? null;
        $snapshotHash = \strtolower(\trim((string)($snapshot['snapshot_hash'] ?? '')));
        $documentCount = $snapshot['document_count'] ?? null;
        if (!\is_array($documents)
            || !\is_int($sourceWatermark)
            || $sourceWatermark < 0
            || !\is_int($documentCount)
            || $documentCount !== \count($documents)
            || \preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1
        ) {
            throw new SearchQueryException(
                SearchQueryException::ERROR_DIRECT_CONTRACT,
                (string)__('Product 目录直读返回了无效快照'),
                ['website_id' => $websiteId],
            );
        }

        return new ProductDirectCatalogRead(
            $sourceWatermark,
            $snapshotHash,
            $documentCount,
            $this->filter($documents, $query),
        );
    }

    /**
     * @param list<array<string,mixed>> $documents
     * @param array<string,mixed> $query
     * @return list<array<string,mixed>>
     */
    private function filter(array $documents, array $query): array
    {
        $websiteId = (int)$query['website_id'];
        $storeId = (int)$query['store_id'];
        $channelId = (int)$query['channel_id'];
        $locale = \trim((string)$query['locale']);
        $currency = \strtoupper(\trim((string)$query['currency']));
        $needle = \mb_strtolower(\trim((string)($query['q'] ?? '')));
        $hits = [];

        foreach ($documents as $document) {
            if (!\is_array($document)) {
                throw new SearchQueryException(
                    SearchQueryException::ERROR_DIRECT_CONTRACT,
                    (string)__('Product 目录直读文档格式无效'),
                    ['website_id' => $websiteId],
                );
            }
            if ((int)($document['website_id'] ?? -1) !== $websiteId
                || (int)($document['store_id'] ?? 0) !== $storeId
                || (int)($document['channel_id'] ?? 0) !== $channelId
                || (string)($document['status'] ?? '') !== 'published'
            ) {
                continue;
            }
            $documentLocale = \trim((string)($document['locale'] ?? ''));
            $documentCurrency = \strtoupper(\trim((string)($document['currency'] ?? '')));
            $neutral = $documentLocale === '' && $documentCurrency === '';
            if (($documentLocale === '') !== ($documentCurrency === '')) {
                throw new SearchQueryException(
                    SearchQueryException::ERROR_DIRECT_CONTRACT,
                    (string)__('Product 目录直读文档的 locale/currency 维度不完整'),
                    [
                        'website_id' => $websiteId,
                        'entity_id' => $document['entity_id'] ?? null,
                    ],
                );
            }
            if (!$neutral
                && ($documentLocale !== $locale || $documentCurrency !== $currency)
            ) {
                continue;
            }
            if ($needle !== '') {
                $haystack = \mb_strtolower(
                    (string)($document['title'] ?? '')
                    . ' '
                    . (string)($document['sku'] ?? ''),
                );
                if (!\str_contains($haystack, $needle)) {
                    continue;
                }
            }

            $document['source'] = SearchQueryService::SOURCE_DIRECT;
            $document['dimension_source'] = $neutral ? 'neutral' : 'exact';
            $document['requested_locale'] = $locale;
            $document['requested_currency'] = $currency;
            $hits[] = $document;
        }

        return $hits;
    }
}
