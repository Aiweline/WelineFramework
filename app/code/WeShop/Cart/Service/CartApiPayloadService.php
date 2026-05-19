<?php

declare(strict_types=1);

namespace WeShop\Cart\Service;

use WeShop\Cart\Model\Cart;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Service\ConfigurableProductService;
use WeShop\Product\Service\ProductService;

class CartApiPayloadService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly ProductService $productService,
        private readonly ConfigurableProductService $configurableProductService,
        private readonly PriceService $priceService
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

        $finalProduct = $product;
        if ($this->configurableProductService->isConfigurable($productId)) {
            if ($selectedOptions === []) {
                return $this->errorResponse(409, (string) __('请选择商品规格。'), [
                    'requires_options' => true,
                    'options' => $this->configurableProductService->getConfigurableOptions($productId),
                    'product_id' => $productId,
                ]);
            }

            $variant = $this->configurableProductService->findVariantByOptions($productId, $selectedOptions);
            if (!$variant || !$variant->getId()) {
                return $this->errorResponse(422, (string) __('所选商品配置不可用。'));
            }

            $finalProduct = $variant;
        }

        $stock = (int) $finalProduct->getStock();
        if ($stock < $qty) {
            return $this->errorResponse(
                422,
                (string) __('库存不足，可售数量：%{1}', $stock)
            );
        }

        $finalProductId = (int) $finalProduct->getId();
        $price = (float) $this->priceService->calculatePrice($finalProductId, $customerId, $qty);
        $cart = $this->cartService->addToCart($customerId, $finalProductId, $qty, $price);
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
                'image' => (string) ($finalProduct->getImage() ?: $product->getImage()),
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
                    'image' => (string) $product->getImage(),
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
                'image' => (string) $product->getImage(),
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

        return $this->successResponse((string) __('购物车更新成功。'), [
            'totals' => $this->buildCompactTotals($customerId),
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

        return $this->successResponse((string) __('商品已移至购物车回收站。'), [
            'totals' => $this->buildCompactTotals($customerId),
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

        return $this->successResponse((string) __('商品已恢复至购物车。'), [
            'totals' => $this->buildCompactTotals($customerId),
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
            $formattedItems[] = [
                'cart_id' => (int) ($item['cart_id'] ?? $item['id'] ?? 0),
                'product_id' => (int) ($item['product_id'] ?? 0),
                'name' => (string) ($product['name'] ?? $item[Cart::schema_fields_PRODUCT_NAME] ?? $item['product_name'] ?? ''),
                'image' => (string) ($product['image'] ?? $item[Cart::schema_fields_PRODUCT_IMAGE] ?? $item['product_image'] ?? ''),
                'price' => $price,
                'price_formatted' => $this->priceService->formatPrice($price),
                'quantity' => $quantity,
                'subtotal' => $price * $quantity,
                'subtotal_formatted' => $this->priceService->formatPrice($price * $quantity),
                'url' => (string) ($product['url'] ?? '#'),
                'options' => $item['options'] ?? null,
            ];
        }

        return $formattedItems;
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
}
