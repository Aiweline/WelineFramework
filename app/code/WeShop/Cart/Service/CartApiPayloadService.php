<?php

declare(strict_types=1);

namespace WeShop\Cart\Service;

use Weline\FileManager\Helper\Image as ImageHelper;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Model\Cart;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Helper\HanfuDemoOptionImageProvider;
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
        $selectedOptionDetails = $this->normalizeSelectedOptionDetails($payload['selected_option_details'] ?? []);

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
            $optionSnapshot = $this->applySubmittedOptionDetails($optionSnapshot, $selectedOptionDetails);
            $optionSnapshot = $this->applySubmittedOptionLabels($optionSnapshot, $selectedOptionLabels);
            $hasDeclaredOptions = \is_array($optionConfig['attributes'] ?? null) && $optionConfig['attributes'] !== [];
            if ($hasDeclaredOptions && $optionSnapshot === []) {
                return $this->errorResponse(422, (string) __('所选商品规格组合不可用。'));
            }
        }
        $optionSnapshot = $this->applySubmittedOptionDetails($optionSnapshot, $selectedOptionDetails);
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
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSelectedOptionDetails(mixed $rawDetails): array
    {
        if (\is_string($rawDetails)) {
            $rawDetails = \trim($rawDetails);
            if ($rawDetails === '') {
                return [];
            }

            $decoded = \json_decode($rawDetails, true);
            $rawDetails = \is_array($decoded) ? $decoded : [];
        }

        if (!\is_array($rawDetails) || $rawDetails === []) {
            return [];
        }

        $details = [];
        foreach ($rawDetails as $rawDetail) {
            if (!\is_array($rawDetail)) {
                continue;
            }

            $value = \trim((string) ($rawDetail['value'] ?? $rawDetail['text'] ?? $rawDetail['option_label'] ?? ''));
            if ($value === '') {
                continue;
            }

            $label = \trim((string) ($rawDetail['label'] ?? $rawDetail['name'] ?? $rawDetail['attribute_label'] ?? ''));
            $detail = [
                'label' => $label !== '' ? $label : (string) __('规格'),
                'value' => $value,
            ];

            foreach (['attribute_id', 'option_id'] as $idKey) {
                $id = (int) ($rawDetail[$idKey] ?? 0);
                if ($id > 0) {
                    $detail[$idKey] = $id;
                }
            }

            $attributeCode = \trim((string) ($rawDetail['attribute_code'] ?? ''));
            if ($attributeCode !== '') {
                $detail['attribute_code'] = $attributeCode;
            }

            $optionCode = \trim((string) ($rawDetail['option_code'] ?? $rawDetail['code'] ?? ''));
            if ($optionCode !== '') {
                $detail['option_code'] = $optionCode;
                $detail['code'] = $optionCode;
            }

            foreach (['swatch_type', 'swatch_value', 'option_image'] as $key) {
                $stringValue = \trim((string) ($rawDetail[$key] ?? ''));
                if ($stringValue !== '') {
                    $detail[$key] = $stringValue;
                }
            }

            if (($detail['swatch_type'] ?? '') === 'image') {
                if (($detail['swatch_value'] ?? '') === '' && ($detail['option_image'] ?? '') !== '') {
                    $detail['swatch_value'] = $detail['option_image'];
                }
                if (($detail['option_image'] ?? '') === '' && ($detail['swatch_value'] ?? '') !== '') {
                    $detail['option_image'] = $detail['swatch_value'];
                }
            }

            $details[] = $detail;
        }

        return $details;
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
            $attributeCode = \trim((string) ($attribute['code'] ?? ''));
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

                $optionImage = \trim((string) ($option['option_image'] ?? ''));
                $snapshot[] = [
                    'label' => $label !== '' ? $label : (string) __('规格'),
                    'value' => $value,
                    'attribute_id' => $attributeId,
                    'option_id' => $optionId,
                    'code' => (string) ($option['code'] ?? ''),
                    'attribute_code' => $attributeCode,
                    'option_code' => (string) ($option['code'] ?? ''),
                    'swatch_type' => (string) ($option['swatch_type'] ?? ''),
                    'swatch_value' => (string) ($option['swatch_value'] ?? ''),
                    'option_image' => $optionImage,
                ];
            }
        }

        return $snapshot;
    }

    /**
     * @param array<int, array<string, mixed>> $snapshot
     * @param array<int, array<string, mixed>> $submittedDetails
     * @return array<int, array<string, mixed>>
     */
    private function applySubmittedOptionDetails(array $snapshot, array $submittedDetails): array
    {
        if ($submittedDetails === []) {
            return $snapshot;
        }

        if ($snapshot === []) {
            return $submittedDetails;
        }

        $detailsByOptionId = [];
        $detailsByOptionCode = [];
        foreach ($submittedDetails as $detail) {
            $optionId = (int) ($detail['option_id'] ?? 0);
            if ($optionId > 0) {
                $detailsByOptionId[$optionId] = $detail;
            }

            $optionCode = \strtolower(\trim((string) ($detail['option_code'] ?? $detail['code'] ?? '')));
            if ($optionCode !== '') {
                $detailsByOptionCode[$optionCode] = $detail;
            }
        }

        foreach ($snapshot as $index => $option) {
            if (!\is_array($option)) {
                continue;
            }

            $optionId = (int) ($option['option_id'] ?? 0);
            $optionCode = \strtolower(\trim((string) ($option['option_code'] ?? $option['code'] ?? '')));
            $detail = $optionId > 0 && isset($detailsByOptionId[$optionId])
                ? $detailsByOptionId[$optionId]
                : ($optionCode !== '' && isset($detailsByOptionCode[$optionCode])
                    ? $detailsByOptionCode[$optionCode]
                    : ($submittedDetails[$index] ?? null));

            if (\is_array($detail)) {
                $snapshot[$index] = $this->mergeSubmittedOptionDetail($option, $detail);
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $option
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function mergeSubmittedOptionDetail(array $option, array $detail): array
    {
        foreach (['attribute_id', 'option_id'] as $idKey) {
            $id = (int) ($detail[$idKey] ?? 0);
            if ($id > 0 && !isset($option[$idKey])) {
                $option[$idKey] = $id;
            }
        }

        foreach (['label', 'value'] as $key) {
            $value = \trim((string) ($detail[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            if (!isset($option[$key]) || \trim((string) $option[$key]) === '') {
                $option[$key] = $value;
            }
        }

        foreach (['code', 'attribute_code', 'option_code', 'swatch_type', 'swatch_value', 'option_image'] as $key) {
            $value = \trim((string) ($detail[$key] ?? ''));
            if ($value !== '') {
                $option[$key] = $value;
            }
        }

        if (\trim((string) ($option['code'] ?? '')) === '' && \trim((string) ($option['option_code'] ?? '')) !== '') {
            $option['code'] = \trim((string) $option['option_code']);
        }

        if (\trim((string) ($option['option_code'] ?? '')) === '' && \trim((string) ($option['code'] ?? '')) !== '') {
            $option['option_code'] = \trim((string) $option['code']);
        }

        if (\trim((string) ($option['swatch_type'] ?? '')) === 'image') {
            if (\trim((string) ($option['swatch_value'] ?? '')) === '' && \trim((string) ($option['option_image'] ?? '')) !== '') {
                $option['swatch_value'] = \trim((string) $option['option_image']);
            }
            if (\trim((string) ($option['option_image'] ?? '')) === '' && \trim((string) ($option['swatch_value'] ?? '')) !== '') {
                $option['option_image'] = \trim((string) $option['swatch_value']);
            }
        }

        return $option;
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

            foreach (['attribute_id', 'option_id', 'code', 'attribute_code', 'option_code', 'swatch_type', 'swatch_value', 'option_image'] as $key) {
                if (isset($snapshot[$index][$key])) {
                    $option[$key] = $snapshot[$index][$key];
                }
            }

            $merged[] = $option;
        }

        return $merged !== [] ? $merged : $snapshot;
    }

    /**
     * @return array{attributes: array<int, array<string, mixed>>, variants: array<int, array<string, mixed>>, image_matrix?: array<string, array<string, string>>}
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

                $optionImage = \trim((string) ($option['option_image'] ?? ''));
                $swatch = [
                    'swatch_type' => $swatchType,
                    'swatch_value' => $swatchValue,
                ];
                if ($optionImage !== '') {
                    $swatch['option_image'] = $optionImage;
                }
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
                if (isset($swatch['option_image'])) {
                    $item['option_image'] = $swatch['option_image'];
                }
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
            return ['attributes' => [], 'variants' => [], 'image_matrix' => []];
        }

        $productId = (int) $product->getId();

        return [
            'attributes' => [
                [
                    'attribute_id' => 900001,
                    'code' => 'color',
                    'name' => (string) __('颜色'),
                    'origin_name' => 'Color',
                    'options' => HanfuDemoOptionImageProvider::colorOptions($productId),
                ],
                [
                    'attribute_id' => 900002,
                    'code' => 'size',
                    'name' => (string) __('尺码'),
                    'origin_name' => 'Size',
                    'options' => HanfuDemoOptionImageProvider::sizeOptions($productId),
                ],
                [
                    'attribute_id' => 900003,
                    'code' => 'style',
                    'name' => (string) __('款式'),
                    'origin_name' => 'Style',
                    'options' => $this->buildDemoCategoryStyleImageOptions($product),
                ],
            ],
            'variants' => [],
            'image_matrix' => HanfuDemoOptionImageProvider::imageMatrix(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDemoCategoryStyleImageOptions(Product $product): array
    {
        return HanfuDemoOptionImageProvider::styleOptions((int) $product->getId());
    }

    /**
     * @return array<int, string>
     */
    private function extractProductImages(Product $product): array
    {
        $images = [];

        $primaryImage = \trim((string) ($product->getData(Product::schema_fields_image) ?? ''));
        if ($primaryImage !== '') {
            $images[] = $primaryImage;
        }

        $additionalImages = $product->getData(Product::schema_fields_images);
        if (\is_string($additionalImages) && \trim($additionalImages) !== '') {
            $decoded = \json_decode($additionalImages, true);
            $additionalImages = \is_array($decoded) ? $decoded : [];
        }
        if (\is_array($additionalImages)) {
            foreach ($additionalImages as $image) {
                if (\is_string($image) && \trim($image) !== '') {
                    $images[] = \trim($image);
                }
            }
        }

        return \array_values(\array_unique($images));
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
