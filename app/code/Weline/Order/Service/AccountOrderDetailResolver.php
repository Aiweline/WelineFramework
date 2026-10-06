<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderCatalogImageResolverInterface;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Theme\Helper\StorefrontImagePlaceholder;

final class AccountOrderDetailResolver
{
    public function __construct(
        private readonly OrderFacadeInterface $orders,
        private readonly ?OrderCatalogImageResolverInterface $images = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $customerGroups
     * @return array<string, mixed>|null
     */
    public function resolve(array $customerGroups, string $orderUuid): ?array
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return null;
        }

        $owned = false;
        foreach ($customerGroups as $group) {
            foreach ((array)($group['orders'] ?? []) as $order) {
                $candidate = trim((string)($order['order_uuid'] ?? ''));
                if ($candidate !== '' && hash_equals($candidate, $orderUuid)) {
                    $owned = true;
                    break 2;
                }
            }
        }
        if (!$owned) {
            return null;
        }

        try {
            $detail = $this->orders->get($orderUuid)->toArray();
        } catch (\Throwable) {
            return null;
        }

        $websiteId = (int)($detail['website_id'] ?? 0);
        $storeId = (int)($detail['store_id'] ?? 0);
        $items = is_array($detail['items'] ?? null) ? $detail['items'] : [];
        $detail['items'] = $this->enrichItemImages($items, $websiteId, $storeId);

        return $detail;
    }

    /**
     * Prefer frozen snapshot image; live product main image is display-only fallback.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function enrichItemImages(array $items, int $websiteId, int $storeId): array
    {
        if ($items === []) {
            return [];
        }

        $resolver = $this->images();
        $needProductIds = [];
        $resolvedRefs = [];

        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $raw = trim((string)($item['image_src'] ?? $item['image'] ?? $item['image_url'] ?? $item['thumbnail'] ?? ''));
            $src = '';
            if ($raw !== '' && $resolver !== null) {
                $src = trim($resolver->resolveReference($raw, $websiteId, $storeId));
            } elseif ($raw !== '' && (str_starts_with(strtolower($raw), 'http')
                || str_starts_with($raw, '//')
                || str_starts_with($raw, '/'))) {
                $src = $raw;
            }
            $resolvedRefs[$i] = $src;
            if ($src === '') {
                $productId = (int)($item['product_id'] ?? 0);
                if ($productId > 0) {
                    $needProductIds[$productId] = true;
                }
            }
        }

        $byProductId = [];
        if ($needProductIds !== [] && $resolver !== null) {
            try {
                $byProductId = $resolver->resolveProductMainImages(
                    $websiteId,
                    array_keys($needProductIds),
                    $storeId,
                );
            } catch (\Throwable) {
                $byProductId = [];
            }
        }

        $display = [];
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int)($item['product_id'] ?? 0);
            $src = trim((string)($resolvedRefs[$i] ?? ''));
            if ($src === '' && $productId > 0) {
                $src = trim((string)($byProductId[$productId] ?? ''));
            }
            $imageResolved = StorefrontImagePlaceholder::resolve($src, $productId);
            $display[] = array_merge($item, [
                'image_src' => $imageResolved['src'],
                'image_fallback' => $imageResolved['fallback'],
            ]);
        }

        return $display;
    }

    private function images(): ?OrderCatalogImageResolverInterface
    {
        if ($this->images instanceof OrderCatalogImageResolverInterface) {
            return $this->images;
        }
        try {
            $candidate = ObjectManager::getInstance(OrderCatalogImageResolverInterface::class);

            return $candidate instanceof OrderCatalogImageResolverInterface ? $candidate : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
