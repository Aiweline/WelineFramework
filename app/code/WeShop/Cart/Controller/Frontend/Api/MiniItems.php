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
     * 使用 fetchJson，确保 Content-Type 与 JSON 体一致。
     */
    public function index(): string
    {
        return $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').miniItems()");

        try {
            /** @var CustomerSession $session */
            $session = ObjectManager::getInstance(CustomerSession::class);
            $customerId = (int)($session->getUserId() ?? 0);

            if ($customerId <= 0) {
                return $this->fetchJson([
                    'success' => true,
                    'html' => $this->renderEmptyCart(),
                    'items' => [],
                    'totals' => [
                        'subtotal' => 0,
                        'subtotal_formatted' => $this->formatPrice(0),
                        'count' => 0,
                    ],
                ]);
            }
            
            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);
            
            // 获取购物车数据
            $items = $cartService->getCartItems($customerId);
            $totals = $cartService->calculateTotals($customerId);
            
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
            $html = $this->fetch('WeShop_Cart::templates/frontend/cart/mini-items.phtml');
            
            // 触发事件
            $eventData = [
                'data' => [
                    'customer_id' => $customerId,
                    'items' => $formattedItems,
                    'totals' => $totals,
                    'html' => $html,
                ],
            ];
            $this->getEventsManager()->dispatch('WeShop_Cart::mini_cart_loaded', $eventData);
            
            return $this->fetchJson([
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
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
                'html' => '',
                'items' => [],
                'totals' => [
                    'subtotal' => 0,
                    'subtotal_formatted' => $this->formatPrice(0),
                    'count' => 0,
                ],
            ]);
        }
    }
    
    /**
     * 渲染空购物车 HTML
     */
    private function deprecatedBrowserDirectResponse(string $replacement): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode(410);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');

        $json = \json_encode([
            'code' => 410,
            'msg' => (string)__('Direct browser cart API is deprecated. Use the frontend worker API.'),
            'data' => [
                'deprecated' => true,
                'browser_direct' => false,
                'replacement' => $replacement,
            ],
        ], JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    private function renderEmptyCart(): string
    {
        return '<div class="mini-cart-empty" id="mini-cart-empty">
            <div class="empty-state">
                <span class="material-symbols-outlined empty-icon">shopping_cart</span>
                <p class="empty-message">' . __('购物车是空的') . '</p>
                <a href="' . $this->getUrl('/') . '" class="start-shopping-link" data-action="close-mini-cart">' . __('开始购物') . '</a>
            </div>
        </div>';
    }

    private function getEventsManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }
}
