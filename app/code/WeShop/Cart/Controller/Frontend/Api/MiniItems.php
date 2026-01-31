<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Api;

use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Service\CartService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Event\EventsManager;

/**
 * MiniCart 商品列表 API
 * 
 * 返回迷你购物车的商品列表 HTML 和统计数据
 */
class MiniItems extends BaseController
{
    /**
     * 获取 MiniCart 商品列表
     * 
     * @return array JSON 响应
     */
    public function index(): array
    {
        try {
            /** @var CustomerSession $session */
            $session = ObjectManager::getInstance(CustomerSession::class);
            $customer = $session->getCustomer();
            
            if (!$customer) {
                return [
                    'success' => true,
                    'html' => $this->renderEmptyCart(),
                    'items' => [],
                    'totals' => [
                        'subtotal' => 0,
                        'subtotal_formatted' => $this->formatPrice(0),
                        'count' => 0,
                    ],
                ];
            }
            
            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);
            
            // 获取购物车数据
            $items = $cartService->getCartItems($customer->getId());
            $totals = $cartService->calculateTotals($customer->getId());
            
            // 格式化商品数据
            $formattedItems = [];
            $cartCount = 0;
            
            foreach ($items as $item) {
                $quantity = (int)($item['quantity'] ?? 1);
                $price = (float)($item['price'] ?? 0);
                
                $formattedItems[] = [
                    'cart_id' => $item['cart_id'] ?? $item['id'] ?? 0,
                    'product_id' => $item['product_id'] ?? 0,
                    'name' => $item['product']['name'] ?? __('Product') . ' #' . ($item['product_id'] ?? 0),
                    'image' => $item['product']['image'] ?? '',
                    'price' => $price,
                    'price_formatted' => $this->formatPrice($price),
                    'quantity' => $quantity,
                    'subtotal' => $price * $quantity,
                    'subtotal_formatted' => $this->formatPrice($price * $quantity),
                    'url' => $item['product']['url'] ?? '#',
                    'options' => $item['options'] ?? null,
                ];
                
                $cartCount += $quantity;
            }
            
            // 渲染商品列表 HTML
            $this->assign('items', $formattedItems);
            $html = $this->fetch('WeShop_Cart::frontend/cart/mini-items.phtml');
            
            // 触发事件
            $eventData = [
                'data' => [
                    'customer_id' => $customer->getId(),
                    'items' => $formattedItems,
                    'totals' => $totals,
                    'html' => $html,
                ],
            ];
            EventsManager::getInstance()->dispatch('WeShop_Cart::mini_cart_loaded', $eventData);
            
            return [
                'success' => true,
                'html' => $html,
                'items' => $formattedItems,
                'totals' => [
                    'subtotal' => $totals['subtotal'] ?? 0,
                    'subtotal_formatted' => $this->formatPrice($totals['subtotal'] ?? 0),
                    'shipping' => $totals['shipping'] ?? 0,
                    'shipping_formatted' => $this->formatPrice($totals['shipping'] ?? 0),
                    'tax' => $totals['tax'] ?? 0,
                    'tax_formatted' => $this->formatPrice($totals['tax'] ?? 0),
                    'discount' => $totals['discount'] ?? 0,
                    'discount_formatted' => $this->formatPrice($totals['discount'] ?? 0),
                    'total' => $totals['total'] ?? 0,
                    'total_formatted' => $this->formatPrice($totals['total'] ?? 0),
                    'count' => $cartCount,
                ],
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'html' => '',
                'items' => [],
                'totals' => [
                    'subtotal' => 0,
                    'subtotal_formatted' => $this->formatPrice(0),
                    'count' => 0,
                ],
            ];
        }
    }
    
    /**
     * 渲染空购物车 HTML
     */
    private function renderEmptyCart(): string
    {
        return '<div class="mini-cart-empty" id="mini-cart-empty">
            <div class="empty-state">
                <span class="material-symbols-outlined empty-icon">shopping_cart</span>
                <p class="empty-message">' . __('Your cart is empty') . '</p>
                <a href="' . $this->getUrl('/') . '" class="start-shopping-link" data-action="close-mini-cart">' . __('Start Shopping') . '</a>
            </div>
        </div>';
    }
}
