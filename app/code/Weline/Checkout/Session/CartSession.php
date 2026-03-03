<?php

declare(strict_types=1);

namespace Weline\Checkout\Session;

use Weline\Framework\Session\Business\AbstractBusinessSession;
use Weline\Framework\Session\SessionInterface;

/**
 * 购物车 Session
 *
 * 提供购物车数据的命名空间隔离存储。
 * 所有购物车数据自动添加 'cart_' 前缀，与其他业务数据隔离。
 *
 * @example
 * use Weline\Checkout\Session\CartSession;
 *
 * $cart = new CartSession();
 *
 * // 添加商品
 * $cart->addItem(['product_id' => 1, 'qty' => 2, 'price' => 99.00]);
 *
 * // 获取所有商品
 * $items = $cart->getItems();
 *
 * // 获取总数
 * $total = $cart->getItemCount();
 *
 * // 清空购物车
 * $cart->clearCart();
 */
class CartSession extends AbstractBusinessSession
{
    protected const PREFIX = 'cart_';

    /**
     * 获取购物车商品列表
     *
     * @return array 商品列表
     */
    public function getItems(): array
    {
        return $this->get('items') ?? [];
    }

    /**
     * 设置购物车商品列表
     *
     * @param array $items 商品列表
     */
    public function setItems(array $items): void
    {
        $this->set('items', $items);
        $this->recalculate();
    }

    /**
     * 添加商品到购物车
     *
     * @param array $item 商品数据，应包含 product_id, qty, price 等
     * @return bool 是否添加成功
     */
    public function addItem(array $item): bool
    {
        if (!isset($item['product_id'])) {
            return false;
        }

        $items = $this->getItems();
        $productId = $item['product_id'];
        $found = false;

        foreach ($items as &$existingItem) {
            if (($existingItem['product_id'] ?? null) === $productId) {
                $existingItem['qty'] = ($existingItem['qty'] ?? 1) + ($item['qty'] ?? 1);
                $found = true;
                break;
            }
        }
        unset($existingItem);

        if (!$found) {
            $item['qty'] = $item['qty'] ?? 1;
            $items[] = $item;
        }

        $this->setItems($items);
        return true;
    }

    /**
     * 更新购物车商品数量
     *
     * @param int|string $productId 商品 ID
     * @param int $qty 新数量
     * @return bool 是否更新成功
     */
    public function updateItemQty(int|string $productId, int $qty): bool
    {
        $items = $this->getItems();

        foreach ($items as $key => &$item) {
            if (($item['product_id'] ?? null) == $productId) {
                if ($qty <= 0) {
                    unset($items[$key]);
                } else {
                    $item['qty'] = $qty;
                }
                $this->setItems(\array_values($items));
                return true;
            }
        }

        return false;
    }

    /**
     * 从购物车移除商品
     *
     * @param int|string $productId 商品 ID
     * @return bool 是否移除成功
     */
    public function removeItem(int|string $productId): bool
    {
        return $this->updateItemQty($productId, 0);
    }

    /**
     * 获取购物车商品总数
     *
     * @return int 商品总数
     */
    public function getItemCount(): int
    {
        $items = $this->getItems();
        $count = 0;

        foreach ($items as $item) {
            $count += (int)($item['qty'] ?? 1);
        }

        return $count;
    }

    /**
     * 获取购物车总金额
     *
     * @return float 总金额
     */
    public function getSubtotal(): float
    {
        return (float)($this->get('subtotal') ?? 0.0);
    }

    /**
     * 获取购物车总额（含税、运费等）
     *
     * @return float 总额
     */
    public function getGrandTotal(): float
    {
        return (float)($this->get('grand_total') ?? $this->getSubtotal());
    }

    /**
     * 设置运费
     *
     * @param float $amount 运费金额
     */
    public function setShippingAmount(float $amount): void
    {
        $this->set('shipping_amount', $amount);
        $this->recalculate();
    }

    /**
     * 获取运费
     *
     * @return float 运费金额
     */
    public function getShippingAmount(): float
    {
        return (float)($this->get('shipping_amount') ?? 0.0);
    }

    /**
     * 设置税额
     *
     * @param float $amount 税额
     */
    public function setTaxAmount(float $amount): void
    {
        $this->set('tax_amount', $amount);
        $this->recalculate();
    }

    /**
     * 获取税额
     *
     * @return float 税额
     */
    public function getTaxAmount(): float
    {
        return (float)($this->get('tax_amount') ?? 0.0);
    }

    /**
     * 设置优惠券码
     *
     * @param string $code 优惠券码
     */
    public function setCouponCode(string $code): void
    {
        $this->set('coupon_code', $code);
    }

    /**
     * 获取优惠券码
     *
     * @return string|null 优惠券码
     */
    public function getCouponCode(): ?string
    {
        return $this->get('coupon_code');
    }

    /**
     * 设置折扣金额
     *
     * @param float $amount 折扣金额
     */
    public function setDiscountAmount(float $amount): void
    {
        $this->set('discount_amount', $amount);
        $this->recalculate();
    }

    /**
     * 获取折扣金额
     *
     * @return float 折扣金额
     */
    public function getDiscountAmount(): float
    {
        return (float)($this->get('discount_amount') ?? 0.0);
    }

    /**
     * 检查购物车是否为空
     *
     * @return bool 是否为空
     */
    public function isEmpty(): bool
    {
        return empty($this->getItems());
    }

    /**
     * 清空购物车
     */
    public function clearCart(): void
    {
        $this->clear();
    }

    /**
     * 重新计算金额
     */
    private function recalculate(): void
    {
        $items = $this->getItems();
        $subtotal = 0.0;

        foreach ($items as $item) {
            $price = (float)($item['price'] ?? 0.0);
            $qty = (int)($item['qty'] ?? 1);
            $subtotal += $price * $qty;
        }

        $this->set('subtotal', $subtotal);

        $grandTotal = $subtotal
            + $this->getShippingAmount()
            + $this->getTaxAmount()
            - $this->getDiscountAmount();

        $this->set('grand_total', \max(0.0, $grandTotal));
    }
}
