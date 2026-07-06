<?php
declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Session\CartSession;
use Weline\Framework\App\State;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;

class CartService
{
    private const CART_COUNT_COOKIE = 'weline_cart_item_count';
    private const ITEM_SOURCE_STRING_LIMITS = [
        'source_app' => 80,
        'source_module' => 100,
        'business_module' => 100,
        'business_code' => 100,
        'business_name' => 160,
        'product_type' => 80,
    ];

    private readonly CartItemSnapshotProviderRegistry $snapshotProviderRegistry;

    public function __construct(
        private readonly CartSession $cartSession,
        ?CartItemSnapshotProviderRegistry $snapshotProviderRegistry = null
    ) {
        $this->snapshotProviderRegistry = $snapshotProviderRegistry
            ?? ObjectManager::getInstance(CartItemSnapshotProviderRegistry::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array
    {
        $items = $this->cartSession->getItems();
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $normalizedItem = $this->normalizeItem($item);
            if ((int)$normalizedItem['product_id'] <= 0 || (int)$normalizedItem['qty'] <= 0) {
                continue;
            }
            $normalized[] = $normalizedItem;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function add(array $params): array
    {
        $productId = (int)($params['product_id'] ?? $params['id'] ?? 0);
        $qty = $this->normalizeQty($params['qty'] ?? 1);
        if ($productId <= 0) {
            return $this->summary(false, (string)__('请选择要加入购物车的商品。'));
        }

        $selectedOptions = $params['selected_options'] ?? $params['options'] ?? [];
        $selectedOptions = \is_array($selectedOptions) ? $this->sanitizeOptions($selectedOptions) : [];
        $snapshot = $this->resolveItemSnapshot($productId, $params);
        if (($snapshot['found'] ?? true) === false) {
            return $this->summary(false, (string)($snapshot['message'] ?? __('商品不存在或已下架。')));
        }
        if (($snapshot['sellable'] ?? true) === false) {
            return $this->summary(false, (string)($snapshot['message'] ?? __('该商品暂不可售。')));
        }

        $requestedQty = $qty;
        $stock = array_key_exists('stock', $snapshot) ? \max(0, (int)$snapshot['stock']) : null;
        if (array_key_exists('qty', $snapshot)) {
            $qty = $this->normalizeQty($snapshot['qty']);
        } elseif ($stock !== null) {
            if ($stock <= 0) {
                return $this->summary(false, (string)($snapshot['message'] ?? __('该商品暂时缺货。')));
            }
            $qty = \min($qty, $stock);
        }

        $itemKey = $this->buildItemKey($productId, $selectedOptions);
        $items = $this->getItems();
        $found = false;

        foreach ($items as &$item) {
            if ((string)($item['item_id'] ?? '') !== $itemKey) {
                continue;
            }
            if ($stock !== null) {
                $availableQty = $stock - (int)$item['qty'];
                if ($availableQty <= 0) {
                    return $this->summary(false, (string)__('库存不足，购物车中该商品数量已达到当前可售库存。'));
                }
                $qty = \min($qty, $availableQty);
            }
            $cartItemData = \array_replace($params, $snapshot);
            foreach (['name', 'sku', 'image', 'price'] as $key) {
                if (array_key_exists($key, $cartItemData)) {
                    $item[$key] = $key === 'price'
                        ? $this->normalizePrice($cartItemData[$key])
                        : $this->limitString((string)$cartItemData[$key], $key === 'image' ? 512 : ($key === 'name' ? 160 : 80));
                }
            }
            foreach ($this->sourceFieldsFrom($cartItemData) as $key => $value) {
                $item[$key] = $value;
            }
            $item['qty'] = $this->normalizeQty((int)$item['qty'] + $qty);
            $item['row_total'] = $this->rowTotal($item);
            $found = true;
            break;
        }
        unset($item);

        if (!$found) {
            $cartItemData = \array_replace($params, $snapshot);
            $items[] = $this->normalizeItem([
                'item_id' => $itemKey,
                'product_id' => $productId,
                'name' => trim((string)($cartItemData['name'] ?? '')) ?: (string)__('商品 #%{1}', $productId),
                'sku' => trim((string)($cartItemData['sku'] ?? '')),
                'image' => trim((string)($cartItemData['image'] ?? '')),
                'price' => $this->normalizePrice($cartItemData['price'] ?? 0),
                'qty' => $qty,
                'selected_options' => $selectedOptions,
            ] + $this->sourceFieldsFrom($cartItemData));
        }

        $this->setItems($items);

        $summary = $this->summary(true, (string)__('已加入购物车。'));
        if ($qty !== $requestedQty) {
            $summary['quantity_adjusted'] = true;
            $summary['requested_quantity'] = $requestedQty;
            $summary['adjusted_quantity'] = $qty;
            $summary['message'] = (string)__('库存不足，已按当前可售数量加入购物车。');
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function update(array $params): array
    {
        $target = $this->targetFromParams($params);
        $qty = $this->normalizeQty($params['qty'] ?? 1, allowZero: true);
        if ($target === '') {
            return $this->summary(false, (string)__('请选择要更新的购物车商品。'));
        }

        $items = $this->getItems();
        $updated = false;
        foreach ($items as $index => &$item) {
            if (!$this->itemMatchesTarget($item, $target)) {
                continue;
            }
            if ($qty <= 0) {
                unset($items[$index]);
            } else {
                $item['qty'] = $qty;
                $item['row_total'] = $this->rowTotal($item);
            }
            $updated = true;
            break;
        }
        unset($item);

        if (!$updated) {
            return $this->summary(false, (string)__('未找到要更新的购物车商品。'));
        }

        $this->setItems(\array_values($items));

        return $this->summary(true, (string)__('购物车已更新。'));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function remove(array $params): array
    {
        $target = $this->targetFromParams($params);
        if ($target === '') {
            return $this->summary(false, (string)__('请选择要移除的购物车商品。'));
        }

        $next = [];
        $removed = false;
        foreach ($this->getItems() as $item) {
            if ($this->itemMatchesTarget($item, $target)) {
                $removed = true;
                continue;
            }
            $next[] = $item;
        }

        if (!$removed) {
            return $this->summary(false, (string)__('未找到要移除的购物车商品。'));
        }

        $this->setItems($next);

        return $this->summary(true, (string)__('商品已从购物车移除。'));
    }

    /**
     * @return array<string, mixed>
     */
    public function clear(): array
    {
        $this->cartSession->clearCart();
        $this->syncCountCookie(0);

        return $this->summary(true, (string)__('购物车已清空。'));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(bool $success = true, string $message = ''): array
    {
        $items = $this->getItems();
        $subtotal = 0.0;
        $count = 0;

        foreach ($items as &$item) {
            $item['row_total'] = $this->rowTotal($item);
            $subtotal += (float)$item['row_total'];
            $count += (int)$item['qty'];
        }
        unset($item);

        $summary = [
            'success' => $success,
            'message' => $message,
            'items' => $items,
            'cart_count' => $count,
            'item_count' => $count,
            'distinct_count' => \count($items),
            'subtotal' => \round($subtotal, 2),
            'grand_total' => \round($subtotal, 2),
            'currency' => (string)State::getCurrency(),
            'is_empty' => $items === [],
        ];

        $this->syncCountCookie($count);

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function setItems(array $items): void
    {
        $normalized = [];
        foreach ($items as $item) {
            $normalizedItem = $this->normalizeItem($item);
            if ((int)$normalizedItem['product_id'] <= 0 || (int)$normalizedItem['qty'] <= 0) {
                continue;
            }
            $normalized[] = $normalizedItem;
        }

        $this->cartSession->setItems($normalized);
        $this->syncCountCookie(\array_sum(\array_map(static fn(array $item): int => (int)$item['qty'], $normalized)));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resolveItemSnapshot(int $productId, array $params): array
    {
        try {
            $snapshot = $this->snapshotProviderRegistry->resolve($productId, $params);
        } catch (\Throwable $throwable) {
            if (function_exists('w_log_error')) {
                w_log_error('购物车商品快照解析失败：' . $throwable->getMessage());
            }

            return [];
        }

        return \is_array($snapshot) ? $snapshot : [];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        $productId = (int)($item['product_id'] ?? $item['id'] ?? 0);
        $options = $item['selected_options'] ?? [];
        $options = \is_array($options) ? $this->sanitizeOptions($options) : [];
        $itemId = trim((string)($item['item_id'] ?? ''));
        if ($itemId === '' && $productId > 0) {
            $itemId = $this->buildItemKey($productId, $options);
        }

        $normalized = [
            'item_id' => $itemId,
            'product_id' => $productId,
            'name' => $this->limitString((string)($item['name'] ?? ''), 160),
            'sku' => $this->limitString((string)($item['sku'] ?? ''), 80),
            'image' => $this->limitString((string)($item['image'] ?? ''), 512),
            'price' => $this->normalizePrice($item['price'] ?? 0),
            'qty' => $this->normalizeQty($item['qty'] ?? 1),
            'selected_options' => $options,
        ];
        if ($normalized['name'] === '' && $productId > 0) {
            $normalized['name'] = (string)__('商品 #%{1}', $productId);
        }
        $normalized += $this->sourceFieldsFrom($item);
        $normalized['row_total'] = $this->rowTotal($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, string>
     */
    private function sourceFieldsFrom(array $item): array
    {
        $fields = [];
        foreach (self::ITEM_SOURCE_STRING_LIMITS as $key => $limit) {
            if (!array_key_exists($key, $item)) {
                continue;
            }
            $value = $this->limitString((string)$item[$key], $limit);
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, scalar|null>
     */
    private function sanitizeOptions(array $options): array
    {
        $safe = [];
        foreach ($options as $key => $value) {
            if (!\is_scalar($value) && $value !== null) {
                continue;
            }
            $safe[$this->limitString((string)$key, 80)] = \is_string($value)
                ? $this->limitString($value, 160)
                : $value;
            if (\count($safe) >= 50) {
                break;
            }
        }

        return $safe;
    }

    /**
     * @param array<string, mixed> $selectedOptions
     */
    private function buildItemKey(int $productId, array $selectedOptions): string
    {
        \ksort($selectedOptions);
        $optionHash = \md5(\json_encode($selectedOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return 'p' . $productId . '-' . \substr($optionHash, 0, 12);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function targetFromParams(array $params): string
    {
        $target = trim((string)($params['item_id'] ?? $params['cart_item_id'] ?? ''));
        if ($target !== '') {
            return $target;
        }

        $productId = (int)($params['product_id'] ?? $params['id'] ?? 0);
        return $productId > 0 ? 'product:' . $productId : '';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemMatchesTarget(array $item, string $target): bool
    {
        if (\str_starts_with($target, 'product:')) {
            return (int)$item['product_id'] === (int)\substr($target, 8);
        }

        return (string)($item['item_id'] ?? '') === $target;
    }

    private function normalizeQty(mixed $qty, bool $allowZero = false): int
    {
        $qty = (int)$qty;
        $min = $allowZero ? 0 : 1;

        return \max($min, \min(999, $qty));
    }

    private function normalizePrice(mixed $price): float
    {
        return \round(\max(0.0, (float)$price), 2);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function rowTotal(array $item): float
    {
        return \round((float)($item['price'] ?? 0) * (int)($item['qty'] ?? 1), 2);
    }

    private function limitString(string $value, int $length): string
    {
        $value = \trim($value);
        if (\strlen($value) <= $length) {
            return $value;
        }

        return \substr($value, 0, $length);
    }

    private function syncCountCookie(int $count): void
    {
        Cookie::set(self::CART_COUNT_COOKIE, (string)\max(0, $count), 3600 * 24 * 30, [
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}
