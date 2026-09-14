<?php

declare(strict_types=1);

namespace Weline\Product\Integration\Order;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Order\Api\OrderCatalogImageResolverInterface;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Service\StorefrontProductMediaUrlResolver;

/**
 * Product-side fulfillment of Order catalog image capability.
 */
final class OrderCatalogImageResolver implements OrderCatalogImageResolverInterface
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly StorefrontProductMediaUrlResolver $mediaUrls,
    ) {
    }

    public function resolveReference(string $reference, int $websiteId = 0, int $storeId = 0): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        if (str_starts_with(strtolower($reference), 'http')
            || str_starts_with($reference, '//')
            || str_starts_with($reference, '/')) {
            return $reference;
        }

        return $this->mediaUrls->resolveReference(
            $reference,
            ScopeIdentity::website(max(0, $websiteId), 'default'),
            'zh_Hans_CN',
        );
    }

    public function resolveProductMainImages(int $websiteId, array $productIds, int $storeId = 0): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === []) {
            return [];
        }

        $rows = $this->media->listByProductIds(max(0, $websiteId), $productIds, null);
        /** @var array<int, array{main?:string, any?:string}> $picked */
        $picked = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ((int)($row[Media::schema_fields_HIDDEN] ?? 0) === 1) {
                continue;
            }
            $productId = (int)($row[Media::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId < 1) {
                continue;
            }
            $path = trim((string)($row[Media::schema_fields_PATH] ?? ''));
            if ($path === '') {
                $assetId = trim((string)($row[Media::schema_fields_ASSET_ID] ?? ''));
                $path = $assetId !== '' ? 'asset://' . $assetId : '';
            }
            if ($path === '') {
                continue;
            }
            $role = strtolower(trim((string)($row[Media::schema_fields_ROLE] ?? '')));
            if ($role === 'main' && !isset($picked[$productId]['main'])) {
                $picked[$productId]['main'] = $path;
            }
            if (!isset($picked[$productId]['any'])) {
                $picked[$productId]['any'] = $path;
            }
        }

        $out = [];
        foreach ($picked as $productId => $paths) {
            $path = (string)($paths['main'] ?? $paths['any'] ?? '');
            $url = $this->resolveReference($path, $websiteId, $storeId);
            if ($url !== '') {
                $out[(int)$productId] = $url;
            }
        }

        return $out;
    }
}
