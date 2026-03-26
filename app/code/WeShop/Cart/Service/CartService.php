<?php

declare(strict_types=1);

namespace WeShop\Cart\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Model\Cart;

/**
 * 购物车服务
 */
class CartService
{
    /**
     * 获取购物车商品列表
     *
     * @param int $customerId 客户ID
     * @return array
     */
    public function getCartItems(int $customerId): array
    {
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);

        $items = $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->select()
            ->fetchArray();

        $productIds = [];
        foreach ($items as $item) {
            $productId = (int) ($item[Cart::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }

        $products = [];
        if ($productIds !== []) {
            $productRows = w_query('product', 'getProductByIds', [
                'product_ids' => \array_values(\array_unique($productIds)),
            ]);
            if (\is_array($productRows)) {
                foreach ($productRows as $product) {
                    $productId = (int) ($product['product_id'] ?? 0);
                    if ($productId > 0) {
                        $products[$productId] = $product;
                    }
                }
            }
        }

        foreach ($items as &$item) {
            $productId = (int) ($item[Cart::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId > 0 && isset($products[$productId])) {
                $item['product'] = $products[$productId];
                $item['original_price'] = (float) ($products[$productId]['original_price'] ?? $item['original_price'] ?? $item[Cart::schema_fields_PRICE] ?? 0);
                $item['special_price'] = $products[$productId]['special_price'] ?? null;
                $item['has_discount'] = (bool) ($products[$productId]['has_discount'] ?? false);
                $item['discount_amount'] = (float) ($products[$productId]['discount_amount'] ?? 0);
                $item['discount_percent'] = (int) ($products[$productId]['discount_percent'] ?? 0);
            }
        }
        unset($item);

        return $items;
    }

    /**
     * 计算购物车总价
     *
     * @param int $customerId 客户ID
     * @return array 包含 subtotal, tax, shipping, discount, total 等
     */
    public function calculateTotals(int $customerId): array
    {
        $items = $this->getCartItems($customerId);

        $subtotal = 0.0;
        foreach ($items as $item) {
            $price = (float) ($item[Cart::schema_fields_PRICE] ?? 0);
            $quantity = (int) ($item[Cart::schema_fields_QUANTITY] ?? 1);
            $subtotal += $price * $quantity;
        }

        $totals = [
            'subtotal' => $subtotal,
            'tax' => 0,
            'shipping' => 0,
            'discount' => 0,
            'total' => $subtotal,
        ];

        EventsManager::getInstance()->dispatch('WeShop_Cart::totals_collect', [
            'customer_id' => $customerId,
            'items' => $items,
            'totals' => &$totals,
        ]);

        $totals['total'] = $totals['subtotal']
            + $totals['tax']
            + $totals['shipping']
            - $totals['discount'];

        EventsManager::getInstance()->dispatch('WeShop_Cart::totals_collected', [
            'customer_id' => $customerId,
            'totals' => $totals,
        ]);

        return $totals;
    }

    /**
     * 添加到购物车
     *
     * @param int $customerId 客户ID
     * @param int $productId 商品ID
     * @param int $quantity 数量
     * @param float|null $price 价格（可选，如果不提供则从商品获取）
     * @return Cart
     */
    public function addToCart(int $customerId, int $productId, int $quantity = 1, ?float $price = null): Cart
    {
        EventsManager::getInstance()->dispatch('WeShop_Cart::add_to_cart_before', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);

        $existing = $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Cart::schema_fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();

        if ($existing && $existing->getId()) {
            $newQuantity = (int) $existing->getData(Cart::schema_fields_QUANTITY) + $quantity;
            $existing->setData(Cart::schema_fields_QUANTITY, $newQuantity);
            $existing->save();
            $cart = $existing;
        } else {
            if ($price === null) {
                $product = w_query('product', 'getProductById', [
                    'product_id' => $productId,
                ]);
                $price = \is_array($product) ? (float) ($product['price'] ?? 0) : 0.0;
            }

            $cart->clearData()
                ->setData(Cart::schema_fields_CUSTOMER_ID, $customerId)
                ->setData(Cart::schema_fields_PRODUCT_ID, $productId)
                ->setData(Cart::schema_fields_QUANTITY, $quantity)
                ->setData(Cart::schema_fields_PRICE, $price)
                ->save();
        }

        EventsManager::getInstance()->dispatch('WeShop_Cart::add_to_cart_after', [
            'cart' => $cart,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return $cart;
    }

    /**
     * 更新购物车
     *
     * @param int $cartId 购物车ID
     * @param int $quantity 数量
     * @param int $customerId 客户ID（用于验证）
     * @return bool
     */
    public function updateCart(int $cartId, int $quantity, int $customerId = 0): bool
    {
        if ($quantity <= 0) {
            return $this->removeFromCart($cartId, $customerId);
        }

        EventsManager::getInstance()->dispatch('WeShop_Cart::update_cart_before', [
            'cart_id' => $cartId,
            'quantity' => $quantity,
            'customer_id' => $customerId,
        ]);

        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);
        $cart->load($cartId);

        if ($customerId > 0 && (int) $cart->getData(Cart::schema_fields_CUSTOMER_ID) !== $customerId) {
            throw new \Exception(__('无权更新此购物车项'));
        }

        if (!$cart->getId()) {
            throw new \Exception(__('购物车项不存在'));
        }

        $cart->setData(Cart::schema_fields_QUANTITY, $quantity);
        $cart->save();

        EventsManager::getInstance()->dispatch('WeShop_Cart::update_cart_after', [
            'cart' => $cart,
            'cart_id' => $cartId,
            'quantity' => $quantity,
        ]);

        return true;
    }

    /**
     * 从购物车移除
     */
    public function removeFromCart(int $cartId, int $customerId = 0): bool
    {
        EventsManager::getInstance()->dispatch('WeShop_Cart::remove_from_cart_before', [
            'cart_id' => $cartId,
            'customer_id' => $customerId,
        ]);

        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);
        $cart->load($cartId);

        if ($customerId > 0 && (int) $cart->getData(Cart::schema_fields_CUSTOMER_ID) !== $customerId) {
            throw new \Exception(__('无权移除此购物车项'));
        }

        if (!$cart->getId()) {
            throw new \Exception(__('购物车项不存在'));
        }

        $result = $cart->delete();

        EventsManager::getInstance()->dispatch('WeShop_Cart::remove_from_cart_after', [
            'cart_id' => $cartId,
            'customer_id' => $customerId,
        ]);

        return $result;
    }

    /**
     * 清空购物车
     */
    public function clearCart(int $customerId): bool
    {
        EventsManager::getInstance()->dispatch('WeShop_Cart::clear_before', [
            'customer_id' => $customerId,
        ]);

        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);

        $result = $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->delete();

        EventsManager::getInstance()->dispatch('WeShop_Cart::clear_after', [
            'customer_id' => $customerId,
        ]);

        return $result;
    }

    /**
     * 获取购物车商品数量
     */
    public function getCartItemCount(int $customerId): int
    {
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);

        $items = $cart->clear()
            ->where(Cart::schema_fields_CUSTOMER_ID, $customerId)
            ->select()
            ->fetchArray();

        $count = 0;
        foreach ($items as $item) {
            $count += (int) ($item[Cart::schema_fields_QUANTITY] ?? 1);
        }

        return $count;
    }
}
