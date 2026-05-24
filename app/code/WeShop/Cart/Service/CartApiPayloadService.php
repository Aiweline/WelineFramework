<?php

declare(strict_types=1);

namespace WeShop\Cart\Service;

use Weline\FileManager\Helper\Image as ImageHelper;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Model\Cart;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use WeShop\Product\Service\ConfigurableProductService;
use WeShop\Product\Service\ProductService;

class CartApiPayloadService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly ProductService $productService,
        private readonly ConfigurableProductService $configurableProductService,
        private readonly PriceService $priceService,
        private readonly ?CartCountCookieService $cartCountCookieService = null
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildAddResponse(?int $customerId, array $payload): array
    {
        if (($customerId ?? 0) <= 0) {
            return $this->unauthorizedResponse();
        }

        $productId = (int) ($payload['product_id'] ?? 0);
        $qty = \max(1, (int) ($payload['qty'] ?? 1));
        $selectedOptions = $this->normalizeSelectedOptions($payload['selected_options'] ?? []);
        $selectedOptionLabels = $this->normalizeSelectedOptionLabels($payload['selected_option_labels'] ?? []);

        if ($productId <= 0) {
            return $this->errorResponse(422, (string) __('无效的商品 ID。'));
        }

        $product = $this->productService->getProduct($productId);
        if (!$product || !$product->getId()) {
            return $this->errorResponse(404, (string) __('商品不存在。'));
        }

        if ((int) $product->getStatus() !== 1) {
            return $this->errorResponse(422, (string) __('商品不可用。'));
        }

        $isConfigurable = $this->configurableProductService->isConfigurable($productId);
        $optionConfig = $selectedOptions !== [] || $isConfigurable
            ? $this->getPurchasableOptionConfig($productId, $product)
            : ['attributes' => [], 'variants' => []];
        $finalProduct = $product;
        $optionSnapshot = [];
        if ($isConfigurable) {
            if ($selectedOptions === []) {
                return $this->errorResponse(409, (string) __('请选择商品规格。'), [
                    'requires_options' => true,
                    'options' => $optionConfig,
                    'product_id' => $productId,
                ]);
            }

            $variant = $this->configurableProductService->findVariantByOptions($productId, $selectedOptions);
            if (!$variant || !$variant->getId()) {
                return $this->errorResponse(422, (string) __('所选商品配置不可用。'));
            }

            $finalProduct = $variant;
            $optionSnapshot = $this->buildSelectedOptionSnapshot($productId, $selectedOptions, $optionConfig);
        } elseif ($selectedOptions !== []) {
            $optionSnapshot = $this->buildSelectedOptionSnapshot($productId, $selectedOptions, $optionConfig);
            $optionSnapshot = $this->applySubmittedOptionLabels($optionSnapshot, $selectedOptionLabels);
            $hasDeclaredOptions = \is_array($optionConfig['attributes'] ?? null) && $optionConfig['attributes'] !== [];
            if ($hasDeclaredOptions && $optionSnapshot === []) {
                return $this->errorResponse(422, (string) __('所选商品规格组合不可用。'));
            }
        }
        $optionSnapshot = $this->applySubmittedOptionLabels($optionSnapshot, $selectedOptionLabels);

        $stock = (int) $finalProduct->getStock();
        if ($stock < $qty) {
            return $this->errorResponse(
                422,
                (string) __('库存不足，可售数量：%{1}', $stock)
            );
        }

        $finalProductId = (int) $finalProduct->getId();
        $price = (float) $this->priceService->calculatePrice($finalProductId, $customerId, $qty);
        $cart = $this->cartService->addToCart($customerId, $finalProductId, $qty, $price, $optionSnapshot);
        $cartItemId = (int) $cart->getId();
        if ($cartItemId <= 0) {
            $cartItemId = $this->cartService->findCartItemId($customerId, $finalProductId);
            if ($cartItemId > 0) {
                $cart->setId($cartItemId);
            }
        }
        $cartCount = $this->cartService->getCartItemCount($customerId);
        $totals = $this->cartService->calculateTotals($customerId);
        $cartTotal = (float) ($totals['total'] ?? 0);

        $this->syncCartCountCookie($cartCount);

        return $this->successResponse((string) __('已成功加入购物车。'), [
            'cart_item_id' => $cartItemId,
            'cart_count' => $cartCount,
            'cart_total' => $cartTotal,
            'cart_total_formatted' => $this->priceService->formatPrice($cartTotal),
            'product' => [
                'id' => $finalProductId,
                'name' => (string) $finalProduct->getName(),
                'price' => $price,
                'qty' => $qty,
                'image' => $this->normalizeProductImage((string) ($finalProduct->getImage() ?: $product->getImage())),
                'options' => $optionSnapshot,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildOptionsResponse(int $productId): array
    {
        if ($productId <= 0) {
            return $this->errorResponse(422, (string) __('无效的商品 ID。'));
        }

        $product = $this->productService->getProduct($productId);
        if (!$product || !$product->getId()) {
            return $this->errorResponse(404, (string) __('商品不存在。'));
        }

        if (!$this->configurableProductService->isConfigurable($productId)) {
            return $this->successResponse((string) __('商品规格加载成功。'), [
                'is_configurable' => false,
                'product' => [
                    'id' => $productId,
                    'name' => (string) $product->getName(),
                    'price' => (float) $this->priceService->calculatePrice($productId),
                    'image' => $this->normalizeProductImage((string) $product->getImage()),
                ],
                'options' => null,
            ]);
        }

        return $this->successResponse((string) __('商品规格加载成功。'), [
            'is_configurable' => true,
            'product' => [
                'id' => $productId,
                'name' => (string) $product->getName(),
                'price' => (float) $this->priceService->calculatePrice($productId),
                'image' => $this->normalizeProductImage((string) $product->getImage()),
            ],
            'options' => $this->configurableProductService->getConfigurableOptions($productId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUpdateResponse(?int $customerId, int $itemId, int $quantity): array
    {
        if (($customerId ?? 0) <= 0) {
            return $this->unauthorizedResponse();
        }

        if ($itemId <= 0) {
            return $this->errorResponse(422, (string) __('无效的购物车项。'));
        }

        if ($quantity <= 0) {
            return $this->errorResponse(422, (string) __('无效的数量。'));
        }

        $this->cartService->updateCart($itemId, $quantity, $customerId);

        $totals = $this->buildCompactTotals($customerId);
        $this->syncCartCountCookie((int) ($totals['count'] ?? 0));

        return $this->successResponse((string) __('购物车更新成功。'), [
            'totals' => $totals,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRemoveResponse(?int $customerId, int $itemId): array
    {
        if (($customerId ?? 0) <= 0) {
            return $this->unauthorizedResponse();
        }

        if ($itemId <= 0) {
            return $this->errorResponse(422, (string) __('无效的购物车项。'));
        }

        $this->cartService->moveToTrash($itemId, $customerId);

        $totals = $this->buildCompactTotals($customerId);
        $this->syncCartCountCookie((int) ($totals['count'] ?? 0));

        return $this->successResponse((string) __('商品已移至购物车回收站。'), [
            'totals' => $totals,
            'trash' => $this->buildTrashData($customerId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRestoreResponse(?int $customerId, int $itemId): array
    {
        if (($customerId ?? 0) <= 0) {
            return $this->unauthorizedResponse();
        }

        if ($itemId <= 0) {
            return $this->errorResponse(422, (string) __('无效的购物车项。'));
        }

        $this->cartService->restoreFromTrash($itemId, $customerId);

        $totals = $this->buildCompactTotals($customerId);
        $this->syncCartCountCookie((int) ($totals['count'] ?? 0));

        return $this->successResponse((string) __('商品已恢复至购物车。'), [
            'totals' => $totals,
            'trash' => $this->buildTrashData($customerId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTrashResponse(?int $customerId, int $limit = 6): array
    {
        if (($customerId ?? 0) <= 0) {
            return $this->unauthorizedResponse();
        }

        return $this->successResponse((string) __('购物车回收站加载成功。'), [
            'trash' => $this->buildTrashData($customerId, $limit),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildMiniItemsResponse(?int $customerId): array
    {
        if (($customerId ?? 0) <= 0) {
            return $this->successResponse((string) __('迷你购物车加载成功。'), [
                'html' => '',
                'items' => [],
                'totals' => $this->buildFullTotals(0),
            ]);
        }

        $items = $this->formatMiniItems($this->cartService->getCartItems($customerId));

        return $this->successResponse((string) __('迷你购物车加载成功。'), [
            'html' => '',
            'items' => $items,
            'totals' => $this->buildFullTotals($customerId),
        ]);
    }

    /**
     * @param array<int, mixed>|string $selectedOptions
     * @return array<int, int>
     */
    private function normalizeSelectedOptions(array|string $selectedOptions): array
    {
        if (\is_string($selectedOptions) && $selectedOptions !== '') {
            $decoded = \json_decode($selectedOptions, true);
            $selectedOptions = \is_array($decoded) ? $decoded : [];
        }

        if (!\is_array($selectedOptions)) {
            return [];
        }

        return \array_values(\array_filter(\array_map(static fn(mixed $value): int => (int) $value, $selectedOptions)));
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function normalizeSelectedOptionLabels(mixed $rawLabels): array
    {
        if (\is_string($rawLabels)) {
            $rawLabels = \trim($rawLabels);
            if ($rawLabels === '') {
                return [];
            }

            $decoded = \json_decode($rawLabels, true);
            if (\is_array($decoded)) {
                return $this->normalizeSelectedOptionLabels($decoded);
            }

            $rawLabels = \preg_split('/\s*(?:\/|\|)\s*/', $rawLabels) ?: [];
        }

        if (!\is_array($rawLabels) || $rawLabels === []) {
            return [];
        }

        $labels = [];
        foreach ($rawLabels as $key => $rawLabel) {
            if (\is_array($rawLabel)) {
                $label = \trim((string) ($rawLabel['label'] ?? $rawLabel['name'] ?? $key));
                $value = \trim((string) ($rawLabel['value'] ?? $rawLabel['text'] ?? ''));
            } else {
                $text = \trim((string) $rawLabel);
                if ($text === '') {
                    continue;
                }

                [$label, $value] = \array_pad(\explode(':', $text, 2), 2, '');
                $label = \trim((string) $label);
                $value = \trim((string) $value);
                if ($value === '') {
                    $value = $label;
                    $label = '';
                }
            }

            if ($value === '') {
                continue;
            }

            $labels[] = [
                'label' => $label !== '' ? $label : (string) __('规格'),
                'value' => $value,
            ];
        }

        return $labels;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function formatMiniItems(array $items): array
    {
        $formattedItems = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);
            $product = \is_array($item['product'] ?? null) ? $item['product'] : [];
            $productId = (int) ($item['product_id'] ?? $item[Cart::schema_fields_PRODUCT_ID] ?? 0);
            $productName = (string) ($product['name'] ?? $item[Cart::schema_fields_PRODUCT_NAME] ?? $item['product_name'] ?? '');
            if ($productName === '' && $productId > 0) {
                $productName = (string) __('商品 #%{1}', $productId);
            }
            $rawImage = (string) ($product['image'] ?? $item[Cart::schema_fields_PRODUCT_IMAGE] ?? $item['product_image'] ?? '');
            $optionItems = $this->cartService->normalizeOptions($item['options'] ?? $item[Cart::schema_fields_PRODUCT_OPTIONS] ?? null);
            $optionItems = $this->enrichMiniOptionItems($productId, $optionItems);
            $options = $this->cartService->formatOptions($optionItems);
            $formattedItems[] = [
                'cart_id' => (int) ($item['cart_id'] ?? $item['id'] ?? 0),
                'product_id' => $productId,
                'name' => $productName,
                'image' => $this->normalizeProductImage($rawImage),
                'price' => $price,
                'price_formatted' => $this->priceService->formatPrice($price),
                'quantity' => $quantity,
                'subtotal' => $price * $quantity,
                'subtotal_formatted' => $this->priceService->formatPrice($price * $quantity),
                'url' => (string) ($product['url'] ?? '#'),
                'options' => $options,
                'option_items' => $optionItems,
            ];
        }

        return $formattedItems;
    }

    /**
     * @param array<int, int> $selectedOptionIds
     * @return array<int, array<string, mixed>>
     */
    private function buildSelectedOptionSnapshot(int $productId, array $selectedOptionIds, ?array $config = null): array
    {
        $selectedOptionIds = \array_values(\array_unique(\array_filter(\array_map('intval', $selectedOptionIds))));
        if ($productId <= 0 || $selectedOptionIds === []) {
            return [];
        }

        $selectedMap = \array_fill_keys($selectedOptionIds, true);
        $config = \is_array($config) ? $config : $this->configurableProductService->getConfigurableOptions($productId);
        $attributes = \is_array($config['attributes'] ?? null) ? $config['attributes'] : [];
        $snapshot = [];
        foreach ($attributes as $attribute) {
            if (!\is_array($attribute)) {
                continue;
            }

            $attributeId = (int) ($attribute['attribute_id'] ?? 0);
            $label = \trim((string) ($attribute['name'] ?? $attribute['origin_name'] ?? $attribute['code'] ?? ''));
            $options = \is_array($attribute['options'] ?? null) ? $attribute['options'] : [];
            foreach ($options as $option) {
                if (!\is_array($option)) {
                    continue;
                }

                $optionId = (int) ($option['option_id'] ?? 0);
                if ($optionId <= 0 || !isset($selectedMap[$optionId])) {
                    continue;
                }

                $value = \trim((string) ($option['value'] ?? $option['origin_value'] ?? $option['code'] ?? ''));
                if ($value === '') {
                    continue;
                }

                $snapshot[] = [
                    'label' => $label !== '' ? $label : (string) __('规格'),
                    'value' => $value,
                    'attribute_id' => $attributeId,
                    'option_id' => $optionId,
                    'code' => (string) ($option['code'] ?? ''),
                    'swatch_type' => (string) ($option['swatch_type'] ?? ''),
                    'swatch_value' => (string) ($option['swatch_value'] ?? ''),
                ];
            }
        }

        return $snapshot;
    }

    /**
     * @param array<int, array<string, mixed>> $snapshot
     * @param array<int, array{label: string, value: string}> $submittedLabels
     * @return array<int, array<string, mixed>>
     */
    private function applySubmittedOptionLabels(array $snapshot, array $submittedLabels): array
    {
        if ($submittedLabels === []) {
            return $snapshot;
        }

        $merged = [];
        foreach ($submittedLabels as $index => $submitted) {
            $label = \trim((string) ($submitted['label'] ?? ''));
            $value = \trim((string) ($submitted['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $option = [
                'label' => $label !== '' ? $label : (string) __('规格'),
                'value' => $value,
            ];

            foreach (['attribute_id', 'option_id', 'code', 'swatch_type', 'swatch_value'] as $key) {
                if (isset($snapshot[$index][$key])) {
                    $option[$key] = $snapshot[$index][$key];
                }
            }

            $merged[] = $option;
        }

        return $merged !== [] ? $merged : $snapshot;
    }

    /**
     * @return array{attributes: array<int, array<string, mixed>>, variants: array<int, array<string, mixed>>}
     */
    private function getPurchasableOptionConfig(int $productId, Product $product): array
    {
        $config = $this->configurableProductService->getConfigurableOptions($productId);
        $attributes = \is_array($config['attributes'] ?? null) ? $config['attributes'] : [];
        if ($attributes !== []) {
            return [
                'attributes' => $attributes,
                'variants' => \is_array($config['variants'] ?? null) ? $config['variants'] : [],
            ];
        }

        return $this->buildDemoCategoryOptionConfig($product);
    }

    /**
     * @param array<int, array<string, mixed>> $optionItems
     * @return array<int, array<string, mixed>>
     */
    private function enrichMiniOptionItems(int $productId, array $optionItems): array
    {
        if ($productId <= 0 || $optionItems === []) {
            return $optionItems;
        }

        $needsSwatch = false;
        foreach ($optionItems as $item) {
            if (!\is_array($item)) {
                continue;
            }
            if (\trim((string) ($item['swatch_type'] ?? '')) === '' || \trim((string) ($item['swatch_value'] ?? '')) === '') {
                $needsSwatch = true;
                break;
            }
        }

        if (!$needsSwatch) {
            return $optionItems;
        }

        $product = $this->productService->getProduct($productId);
        if (!$product || !$product->getId()) {
            return $optionItems;
        }

        $config = $this->getPurchasableOptionConfig($productId, $product);
        $attributes = \is_array($config['attributes'] ?? null) ? $config['attributes'] : [];
        if ($attributes === []) {
            return $optionItems;
        }

        $swatchByOptionId = [];
        $swatchByCode = [];
        foreach ($attributes as $attribute) {
            if (!\is_array($attribute) || !\is_array($attribute['options'] ?? null)) {
                continue;
            }

            foreach ($attribute['options'] as $option) {
                if (!\is_array($option)) {
                    continue;
                }

                $swatchType = \trim((string) ($option['swatch_type'] ?? ''));
                $swatchValue = \trim((string) ($option['swatch_value'] ?? ''));
                if ($swatchType === '' || $swatchValue === '') {
                    continue;
                }

                $swatch = [
                    'swatch_type' => $swatchType,
                    'swatch_value' => $swatchValue,
                ];
                $optionId = (int) ($option['option_id'] ?? 0);
                if ($optionId > 0) {
                    $swatchByOptionId[$optionId] = $swatch;
                }

                $code = \trim((string) ($option['code'] ?? ''));
                if ($code !== '') {
                    $swatchByCode[\strtolower($code)] = $swatch;
                }
            }
        }

        foreach ($optionItems as &$item) {
            if (!\is_array($item)) {
                continue;
            }
            if (\trim((string) ($item['swatch_type'] ?? '')) !== '' && \trim((string) ($item['swatch_value'] ?? '')) !== '') {
                continue;
            }

            $optionId = (int) ($item['option_id'] ?? 0);
            $code = \strtolower(\trim((string) ($item['code'] ?? '')));
            $swatch = $optionId > 0 && isset($swatchByOptionId[$optionId])
                ? $swatchByOptionId[$optionId]
                : ($code !== '' && isset($swatchByCode[$code]) ? $swatchByCode[$code] : null);
            if ($swatch !== null) {
                $item['swatch_type'] = $swatch['swatch_type'];
                $item['swatch_value'] = $swatch['swatch_value'];
            }
        }
        unset($item);

        return $optionItems;
    }

    /**
     * Demo/category sample products expose option selectors without generated
     * child variants. Store the submitted option snapshot so checkout and order
     * pages can show the same selected values.
     *
     * @return array{attributes: array<int, array<string, mixed>>, variants: array<int, array<string, mixed>>}
     */
    private function buildDemoCategoryOptionConfig(Product $product): array
    {
        $sku = (string) $product->getData(Product::schema_fields_sku);
        if (!\str_starts_with($sku, 'DEMO-CAT-')) {
            return ['attributes' => [], 'variants' => []];
        }

        $productId = (int) $product->getId();

        return [
            'attributes' => [
                [
                    'attribute_id' => 900001,
                    'code' => 'color',
                    'name' => (string) __('颜色'),
                    'origin_name' => 'Color',
                    'options' => [
                        ['option_id' => 900101, 'code' => 'black', 'value' => (string) __('黑色'), 'origin_value' => 'Black', 'swatch_type' => 'color', 'swatch_value' => '#111827', 'available_product_ids' => [$productId]],
                        ['option_id' => 900102, 'code' => 'navy', 'value' => (string) __('藏青'), 'origin_value' => 'Navy', 'swatch_type' => 'color', 'swatch_value' => '#1e3a8a', 'available_product_ids' => [$productId]],
                        ['option_id' => 900103, 'code' => 'beige', 'value' => (string) __('米色'), 'origin_value' => 'Beige', 'swatch_type' => 'color', 'swatch_value' => '#d6b98c', 'available_product_ids' => [$productId]],
                    ],
                ],
                [
                    'attribute_id' => 900002,
                    'code' => 'size',
                    'name' => (string) __('尺码'),
                    'origin_name' => 'Size',
                    'options' => [
                        ['option_id' => 900201, 'code' => 'm', 'value' => 'M', 'origin_value' => 'M', 'swatch_type' => 'text', 'swatch_value' => 'M', 'available_product_ids' => [$productId]],
                        ['option_id' => 900202, 'code' => 'l', 'value' => 'L', 'origin_value' => 'L', 'swatch_type' => 'text', 'swatch_value' => 'L', 'available_product_ids' => [$productId]],
                        ['option_id' => 900203, 'code' => 'xl', 'value' => 'XL', 'origin_value' => 'XL', 'swatch_type' => 'text', 'swatch_value' => 'XL', 'available_product_ids' => [$productId]],
                    ],
                ],
            ],
            'variants' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unauthorizedResponse(): array
    {
        return $this->errorResponse(401, (string) __('请先登录'), [
            'requires_login' => true,
            'cart_count' => 0,
            'cart_total' => 0.0,
            'cart_total_formatted' => $this->priceService->formatPrice(0),
            'totals' => $this->buildFullTotals(0),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCompactTotals(int $customerId): array
    {
        $totals = $this->cartService->calculateTotals($customerId);
        $subtotal = (float) ($totals['subtotal'] ?? 0);
        $total = (float) ($totals['total'] ?? 0);

        return [
            'subtotal' => $subtotal,
            'subtotal_formatted' => $this->priceService->formatPrice($subtotal),
            'total' => $total,
            'total_formatted' => $this->priceService->formatPrice($total),
            'count' => $this->cartService->getCartItemCount($customerId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFullTotals(int $customerId): array
    {
        $totals = $customerId > 0 ? $this->cartService->calculateTotals($customerId) : [];
        $subtotal = (float) ($totals['subtotal'] ?? 0);
        $shipping = (float) ($totals['shipping'] ?? 0);
        $tax = (float) ($totals['tax'] ?? 0);
        $discount = (float) ($totals['discount'] ?? 0);
        $total = (float) ($totals['total'] ?? 0);

        return [
            'subtotal' => $subtotal,
            'subtotal_formatted' => $this->priceService->formatPrice($subtotal),
            'shipping' => $shipping,
            'shipping_formatted' => $this->priceService->formatPrice($shipping),
            'tax' => $tax,
            'tax_formatted' => $this->priceService->formatPrice($tax),
            'discount' => $discount,
            'discount_formatted' => $this->priceService->formatPrice($discount),
            'total' => $total,
            'total_formatted' => $this->priceService->formatPrice($total),
            'count' => $customerId > 0 ? $this->cartService->getCartItemCount($customerId) : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTrashData(int $customerId, int $limit = 6): array
    {
        return [
            'count' => $this->cartService->getTrashItemCount($customerId),
            'items' => $this->formatMiniItems($this->cartService->getTrashItems($customerId, $limit)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function successResponse(string $message, array $data = []): array
    {
        return [
            'code' => 200,
            'msg' => $message,
            'data' => ['success' => true, 'message' => $message] + $data,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function errorResponse(int $code, string $message, array $data = []): array
    {
        return [
            'code' => $code,
            'msg' => $message,
            'data' => ['success' => false, 'message' => $message] + $data,
        ];
    }

    private function normalizeProductImage(string $image): string
    {
        $image = \trim($image);
        if ($image === '') {
            return '';
        }

        return ImageHelper::pathToMediaUrl($image, 144, 144);
    }

    private function syncCartCountCookie(int $count): void
    {
        $this->getCartCountCookieService()->sync($count);
    }

    private function getCartCountCookieService(): CartCountCookieService
    {
        return $this->cartCountCookieService ?? ObjectManager::getInstance(CartCountCookieService::class);
    }
}
