<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Business;

/**
 * 愿望清单 Session
 *
 * 示例：如何创建自己的业务级 Session
 *
 * @example
 * use Weline\Framework\Session\Business\WishlistSession;
 *
 * $wishlist = new WishlistSession();
 *
 * // 添加商品到愿望清单
 * $wishlist->addItem(123);
 *
 * // 检查商品是否在愿望清单
 * if ($wishlist->hasItem(123)) {
 *     // ...
 * }
 *
 * // 获取所有商品 ID
 * $productIds = $wishlist->getItems();
 */
class WishlistSession extends AbstractBusinessSession
{
    protected const PREFIX = 'wishlist_';

    /**
     * 获取愿望清单商品 ID 列表
     *
     * @return array 商品 ID 列表
     */
    public function getItems(): array
    {
        return $this->get('items') ?? [];
    }

    /**
     * 添加商品到愿望清单
     *
     * @param int|string $productId 商品 ID
     * @return bool 是否添加成功（已存在返回 false）
     */
    public function addItem(int|string $productId): bool
    {
        $items = $this->getItems();

        if (\in_array($productId, $items, false)) {
            return false;
        }

        $items[] = $productId;
        $this->set('items', $items);

        return true;
    }

    /**
     * 从愿望清单移除商品
     *
     * @param int|string $productId 商品 ID
     * @return bool 是否移除成功
     */
    public function removeItem(int|string $productId): bool
    {
        $items = $this->getItems();
        $key = \array_search($productId, $items, false);

        if ($key === false) {
            return false;
        }

        unset($items[$key]);
        $this->set('items', \array_values($items));

        return true;
    }

    /**
     * 检查商品是否在愿望清单中
     *
     * @param int|string $productId 商品 ID
     * @return bool 是否在愿望清单中
     */
    public function hasItem(int|string $productId): bool
    {
        return \in_array($productId, $this->getItems(), false);
    }

    /**
     * 获取愿望清单商品数量
     *
     * @return int 商品数量
     */
    public function getCount(): int
    {
        return \count($this->getItems());
    }

    /**
     * 清空愿望清单
     */
    public function clearWishlist(): void
    {
        $this->clear();
    }

    /**
     * 将愿望清单商品移至购物车
     *
     * @param int|string $productId 商品 ID
     * @param CartSession|null $cart 购物车 Session（如果需要的话）
     * @return bool 是否成功
     */
    public function moveToCart(int|string $productId, ?object $cart = null): bool
    {
        if (!$this->hasItem($productId)) {
            return false;
        }

        $this->removeItem($productId);

        return true;
    }
}
