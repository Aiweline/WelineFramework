<?php

declare(strict_types=1);

namespace WeShop\Cart\Service;

use WeShop\Cart\Model\Cart;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Service\ProductRecommendationService;
use Weline\FileManager\Helper\Image as ImageHelper;
use Weline\Framework\Manager\ObjectManager;

class CartPageDataService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly ProductRecommendationService $productRecommendationService,
        private readonly ?PriceService $priceService = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId): array
    {
        $cartItems = $this->mapCartItems($this->cartService->getCartItems($customerId));
        $trashItems = $this->mapCartItems($this->cartService->getTrashItems($customerId, 6));
        $totals = $this->cartService->calculateTotals($customerId);
        $cartCount = array_sum(array_map(static fn(array $item): int => (int) ($item['qty'] ?? 0), $cartItems));
        $trashCount = $this->cartService->getTrashItemCount($customerId);
        $summary = [
            'subtotal' => (float) ($totals['subtotal'] ?? 0),
            'shipping' => (float) ($totals['shipping'] ?? 0),
            'discount' => (float) ($totals['discount'] ?? 0),
            'tax' => (float) ($totals['tax'] ?? 0),
            'grand_total' => (float) ($totals['total'] ?? 0),
        ];
        $summary += [
            'subtotal_formatted' => $this->formatPrice($summary['subtotal']),
            'shipping_formatted' => $this->formatPrice($summary['shipping']),
            'discount_formatted' => $this->formatPrice($summary['discount']),
            'tax_formatted' => $this->formatPrice($summary['tax']),
            'grand_total_formatted' => $this->formatPrice($summary['grand_total']),
        ];
        $recommendations = $this->productRecommendationService->getRecommendations(
            array_column($cartItems, 'product_id'),
            6
        );

        return [
            'cart_items' => $cartItems,
            'cart_count' => $cartCount,
            'item_count' => $cartCount,
            'cart_total' => $summary['subtotal'],
            'shipping' => $summary['shipping'],
            'tax' => $summary['tax'],
            'cart_summary' => $summary,
            'cart_trash_items' => $trashItems,
            'cart_trash_count' => $trashCount,
            'recommendations' => $recommendations,
            'related_products' => $recommendations,
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapCartItems(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = is_array($item['product'] ?? null) ? $item['product'] : [];
            $qty = (int) ($item[Cart::schema_fields_QUANTITY] ?? $item['qty'] ?? 1);
            $price = (float) ($item[Cart::schema_fields_PRICE] ?? $item['price'] ?? 0);
            $originalPrice = (float) ($item['original_price'] ?? $price);
            $stockQty = (int) ($item['stock_qty'] ?? $product['stock'] ?? 0);
            $stockStatus = (string) ($item['stock_status'] ?? ($stockQty > 0 ? 'in_stock' : 'out_of_stock'));
            $productId = (int) ($item[Cart::schema_fields_PRODUCT_ID] ?? $item['product_id'] ?? 0);
            $productName = (string) ($product['name'] ?? $item['product_name'] ?? $item['name'] ?? '');
            $productImage = (string) ($product['image'] ?? $item['product_image'] ?? $item['image'] ?? '');
            $isAvailableProduct = $product !== [] && $productName !== '';

            $mapped[] = [
                'item_id' => (int) ($item[Cart::schema_fields_ID] ?? $item['item_id'] ?? 0),
                'product_id' => $productId,
                'name' => $productName !== '' ? $productName : (string) __('商品 #%{1}', $productId),
                'image' => $this->normalizeImageUrl($productImage, 360, 360),
                'price' => $price,
                'price_formatted' => $this->formatPrice($price),
                'original_price' => $originalPrice,
                'original_row_total_formatted' => $this->formatPrice($originalPrice * $qty),
                'qty' => $qty,
                'row_total' => $price * $qty,
                'row_total_formatted' => $this->formatPrice($price * $qty),
                'stock_status' => $stockStatus,
                'stock_qty' => $stockQty,
                'in_stock' => $isAvailableProduct && ($stockStatus === 'in_stock' || $stockQty > 0),
                'is_available' => $isAvailableProduct,
                'options' => $this->normalizeOptions($item['options'] ?? $item['option'] ?? null),
                'seller' => (string) ($item['seller'] ?? $product['seller'] ?? ''),
            ];
        }

        return $mapped;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeOptions(mixed $rawOptions): array
    {
        if (is_string($rawOptions) && trim($rawOptions) !== '') {
            $decoded = json_decode(trim($rawOptions), true);
            if (is_array($decoded)) {
                return $this->normalizeOptions($decoded);
            }

            return [[
                'label' => (string) __('规格'),
                'value' => trim($rawOptions),
            ]];
        }

        if (is_array($rawOptions)) {
            $isAssoc = array_keys($rawOptions) !== range(0, count($rawOptions) - 1);
            if ($isAssoc) {
                $options = [];
                foreach ($rawOptions as $label => $value) {
                    $options[] = [
                        'label' => (string) $label,
                        'value' => is_scalar($value) ? (string) $value : '',
                    ];
                }

                return $options;
            }

            return array_values(array_filter(array_map(static function (mixed $option): ?array {
                if (is_array($option) && isset($option['label'], $option['value'])) {
                    $normalized = [
                        'label' => (string) $option['label'],
                        'value' => (string) $option['value'],
                    ];

                    foreach (['attribute_id', 'option_id'] as $idKey) {
                        $id = (int) ($option[$idKey] ?? 0);
                        if ($id > 0) {
                            $normalized[$idKey] = $id;
                        }
                    }

                    foreach (['code', 'attribute_code', 'option_code', 'swatch_type', 'swatch_value', 'option_image'] as $stringKey) {
                        $stringValue = trim((string) ($option[$stringKey] ?? ''));
                        if ($stringValue !== '') {
                            $normalized[$stringKey] = $stringValue;
                        }
                    }

                    if (($normalized['swatch_type'] ?? '') === 'image') {
                        if (($normalized['swatch_value'] ?? '') === '' && ($normalized['option_image'] ?? '') !== '') {
                            $normalized['swatch_value'] = $normalized['option_image'];
                        }
                        if (($normalized['option_image'] ?? '') === '' && ($normalized['swatch_value'] ?? '') !== '') {
                            $normalized['option_image'] = $normalized['swatch_value'];
                        }
                    }

                    return $normalized;
                }

                return null;
            }, $rawOptions)));
        }

        if (is_string($rawOptions) && trim($rawOptions) !== '') {
            return [[
                'label' => (string) __('规格'),
                'value' => trim($rawOptions),
            ]];
        }

        return [];
    }

    private function normalizeImageUrl(string $image, ?int $width = null, ?int $height = null): string
    {
        $image = trim($image);
        if ($image === '') {
            return '';
        }

        return ImageHelper::pathToMediaUrl($image, $width, $height);
    }

    private function formatPrice(float $price): string
    {
        return $this->getPriceService()->formatPrice($price);
    }

    private function getPriceService(): PriceService
    {
        return $this->priceService ?? ObjectManager::getInstance(PriceService::class);
    }
}
