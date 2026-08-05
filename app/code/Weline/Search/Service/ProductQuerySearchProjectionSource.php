<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\ProductSearchProjectionSourceInterface;
use Weline\Search\Model\SearchShardKey;

/**
 * Production adapter: Search reads Product only through its public Query provider.
 */
final class ProductQuerySearchProjectionSource implements ProductSearchProjectionSourceInterface
{
    public function currentWatermark(int $websiteId): int
    {
        SearchShardKey::fromWebsiteId($websiteId);
        $result = \w_query(
            'product_search_projection',
            'currentWatermark',
            ['website_id' => $websiteId],
            'backend',
        );
        $this->assertResult($result, $websiteId);

        return (int)($result['source_watermark'] ?? -1);
    }

    public function snapshotWebsite(int $websiteId): array
    {
        SearchShardKey::fromWebsiteId($websiteId);
        $result = \w_query(
            'product_search_projection',
            'snapshotWebsite',
            ['website_id' => $websiteId],
            'backend',
        );
        $this->assertResult($result, $websiteId);
        if (($result['contract'] ?? null) !== 'product.search_projection_snapshot.v1'
            || !\is_array($result['documents'] ?? null)
        ) {
            throw new \UnexpectedValueException('product_search_snapshot_contract_invalid');
        }

        return $result;
    }

    public function projectChange(array $change): array
    {
        $websiteId = (int)($change['website_id'] ?? -1);
        SearchShardKey::fromWebsiteId($websiteId);
        $result = \w_query(
            'product_search_projection',
            'projectChange',
            $change,
            'backend',
        );
        $this->assertResult($result, $websiteId);
        if (($result['contract'] ?? null) !== 'product.search_projection_change.v1'
            || !\is_array($result['documents'] ?? null)
            || !\is_array($result['delete_keys'] ?? null)
        ) {
            throw new \UnexpectedValueException('product_search_change_contract_invalid');
        }

        return $result;
    }

    private function assertResult(mixed $result, int $websiteId): void
    {
        if (!\is_array($result)
            || (int)($result['website_id'] ?? -1) !== $websiteId
            || (int)($result['source_watermark'] ?? -1) < 0
        ) {
            throw new \UnexpectedValueException('product_search_projection_query_invalid');
        }
    }
}
