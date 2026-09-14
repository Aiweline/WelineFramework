<?php

declare(strict_types=1);

namespace Weline\Order\Api;

/**
 * Decoupled catalog image resolution for backend order line display.
 *
 * Snapshot `image` is preferred; live product main image is a display-only
 * fallback for historical rows that predate freeze chrome.
 */
interface OrderCatalogImageResolverInterface
{
    /**
     * Resolve a frozen image reference (http(s), /pub/media, asset://) to a display URL.
     */
    public function resolveReference(string $reference, int $websiteId = 0, int $storeId = 0): string;

    /**
     * @param list<int> $productIds
     * @return array<int, string> product_id => display URL (missing ids omitted)
     */
    public function resolveProductMainImages(int $websiteId, array $productIds, int $storeId = 0): array;
}
