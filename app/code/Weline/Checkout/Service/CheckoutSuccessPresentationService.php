<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderCatalogImageResolverInterface;
use Weline\Order\Api\OrderShippingMethodCatalogInterface;
use Weline\Theme\Helper\StorefrontImagePlaceholder;

/**
 * Shopper-facing enrichment for /checkout/success: product thumbs + shipping labels.
 */
final class CheckoutSuccessPresentationService
{
    public function __construct(
        private readonly ?OrderCatalogImageResolverInterface $images = null,
        private readonly ?OrderShippingMethodCatalogInterface $shippingCatalog = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{
     *   items_display: list<array<string, mixed>>,
     *   shipping_method_label: string
     * }
     */
    public function present(
        array $items,
        string $shippingMethodCode,
        int $websiteId = 0,
        int $storeId = 0,
    ): array {
        return [
            'items_display' => $this->buildItemsDisplay($items, $websiteId, $storeId),
            'shipping_method_label' => $this->resolveShippingMethodLabel(
                $shippingMethodCode,
                $websiteId,
                $storeId,
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function buildItemsDisplay(array $items, int $websiteId, int $storeId): array
    {
        if ($items === []) {
            return [];
        }

        $resolver = $this->images();
        $needProductIds = [];
        $resolvedRefs = [];

        foreach ($items as $i => $item) {
            if (!\is_array($item)) {
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
            if (!\is_array($item)) {
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

    private function resolveShippingMethodLabel(string $code, int $websiteId, int $storeId): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }
        $catalog = $this->shippingCatalog();
        if ($catalog === null) {
            return $code;
        }
        try {
            $label = trim($catalog->resolveLabel($code, $websiteId, $storeId));
        } catch (\Throwable) {
            return $code;
        }
        if ($label === '') {
            return $code;
        }
        // Catalog may already translate; __() is idempotent when key is already target locale.
        return (string)__($label);
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

    private function shippingCatalog(): ?OrderShippingMethodCatalogInterface
    {
        if ($this->shippingCatalog instanceof OrderShippingMethodCatalogInterface) {
            return $this->shippingCatalog;
        }
        try {
            $candidate = ObjectManager::getInstance(OrderShippingMethodCatalogInterface::class);

            return $candidate instanceof OrderShippingMethodCatalogInterface ? $candidate : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
