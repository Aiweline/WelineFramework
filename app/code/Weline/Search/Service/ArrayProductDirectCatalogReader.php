<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\ProductDirectCatalogRead;
use Weline\Search\Api\ProductDirectCatalogReaderInterface;
use Weline\Search\Model\SearchShardKey;

/**
 * Memory Product published reader for TEST-P3C-02/03.
 */
final class ArrayProductDirectCatalogReader implements ProductDirectCatalogReaderInterface
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private bool $down = false;

    private int $sourceWatermark = 0;

    public static function forTesting(array $rows = []): self
    {
        $reader = new self();
        foreach ($rows as $row) {
            $reader->seed($row);
        }

        return $reader;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function seed(array $row): void
    {
        $websiteId = (int) ($row['website_id'] ?? -1);
        SearchShardKey::fromWebsiteId($websiteId);
        $normalized = [
            'entity_type' => (string) ($row['entity_type'] ?? 'product'),
            'entity_id' => (string) ($row['entity_id'] ?? ''),
            'website_id' => $websiteId,
            'website_code' => (string)(
                $row['website_code'] ?? ($websiteId === 0 ? 'default' : 'website-' . $websiteId)
            ),
            'store_id' => (int) ($row['store_id'] ?? 1),
            'store_code' => (string)($row['store_code'] ?? 'default'),
            'channel_id' => (int) ($row['channel_id'] ?? 1),
            'channel_code' => (string)($row['channel_code'] ?? 'default'),
            'locale' => (string) ($row['locale'] ?? ''),
            'currency' => (string) ($row['currency'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'sku' => (string) ($row['sku'] ?? ''),
            'status' => (string) ($row['status'] ?? 'published'),
            'document_version' => (int) ($row['document_version'] ?? $row['publish_version'] ?? 1),
            'source' => 'product_direct',
        ];
        foreach ($this->rows as $index => $current) {
            if ((string)$current['entity_type'] === $normalized['entity_type']
                && (string)$current['entity_id'] === $normalized['entity_id']
                && (int)$current['website_id'] === $normalized['website_id']
                && (int)$current['store_id'] === $normalized['store_id']
                && (int)$current['channel_id'] === $normalized['channel_id']
                && (string)$current['locale'] === $normalized['locale']
                && (string)$current['currency'] === $normalized['currency']
            ) {
                $this->rows[$index] = $normalized;
                $this->sourceWatermark = \max(
                    $this->sourceWatermark,
                    (int)($row['source_watermark']
                        ?? $row['document_version']
                        ?? $row['publish_version']
                        ?? 0),
                );

                return;
            }
        }
        $this->rows[] = $normalized;
        $this->sourceWatermark = \max(
            $this->sourceWatermark,
            (int)($row['source_watermark'] ?? $row['document_version'] ?? $row['publish_version'] ?? 0),
        );
    }

    public function markDown(bool $down = true): void
    {
        $this->down = $down;
    }

    public function searchPublished(array $query): ProductDirectCatalogRead
    {
        if ($this->down) {
            throw new SearchQueryException(
                SearchQueryException::ERROR_DIRECT_READER_DOWN,
                __('Product 直读不可用'),
                ['website_id' => $query['website_id'] ?? null],
            );
        }

        $websiteId = (int) ($query['website_id'] ?? -1);
        SearchShardKey::fromWebsiteId($websiteId);
        $storeId = (int)($query['store_id'] ?? 0);
        $channelId = (int)($query['channel_id'] ?? 0);
        $locale = \trim((string)($query['locale'] ?? ''));
        $currency = \strtoupper(\trim((string)($query['currency'] ?? '')));
        if ($storeId < 1 || $channelId < 1 || $locale === '' || $currency === '') {
            throw new SearchQueryException(
                SearchQueryException::ERROR_SCOPE,
                __('Search Product 直读要求完整 Store/Channel/locale/currency Scope'),
                [
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                    'channel_id' => $channelId,
                ],
            );
        }
        $q = trim((string) ($query['q'] ?? ''));

        $out = [];
        foreach ($this->rows as $row) {
            if ((int) $row['website_id'] !== $websiteId) {
                continue;
            }
            if (($row['status'] ?? '') !== 'published') {
                continue;
            }
            if ((int)$row['store_id'] !== $storeId
                || (int)$row['channel_id'] !== $channelId
            ) {
                continue;
            }
            $rowLocale = \trim((string)$row['locale']);
            $rowCurrency = \strtoupper(\trim((string)$row['currency']));
            $neutral = $rowLocale === '' && $rowCurrency === '';
            if (($rowLocale === '') !== ($rowCurrency === '')) {
                throw new SearchQueryException(
                    SearchQueryException::ERROR_DIRECT_CONTRACT,
                    __('Product 目录直读文档的 locale/currency 维度不完整'),
                    ['entity_id' => $row['entity_id'] ?? null],
                );
            }
            if (!$neutral && $rowLocale !== $locale) {
                continue;
            }
            if (!$neutral && $rowCurrency !== $currency) {
                continue;
            }
            if ($q !== '' && !$this->matches($row, $q)) {
                continue;
            }
            $row['dimension_source'] = $neutral ? 'neutral' : 'exact';
            $row['requested_locale'] = $locale;
            $row['requested_currency'] = $currency;
            $out[] = $row;
        }

        $snapshotHash = \hash('sha256', (string)\json_encode(
            $this->rows,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return new ProductDirectCatalogRead(
            $this->sourceWatermark,
            $snapshotHash,
            \count($this->rows),
            $out,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matches(array $row, string $q): bool
    {
        $hay = mb_strtolower((string) $row['title'] . ' ' . (string) $row['sku']);

        return str_contains($hay, mb_strtolower($q));
    }
}
