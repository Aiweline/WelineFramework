<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Api;

use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Service\CartService;
use WeShop\Customer\Session\CustomerSession;

/**
 * 购物车移除 API
 * 
 * 从购物车移除商品
 */
class Remove extends BaseController
{
    /**
     * 从购物车移除商品
     *
     * 使用 fetchJson，确保 Content-Type 与响应体一致。
     */
    public function index(): string
    {
        return $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').remove()");

        try {
            // 验证登录
            /** @var CustomerSession $session */
            $session = ObjectManager::getInstance(CustomerSession::class);
            $customerId = (int)($session->getUserId() ?? 0);
            if ($customerId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Please login first'),
                ]);
            }

            // 获取请求参数
            $itemId = (int)$this->request->getParam('item_id', 0);

            if ($itemId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Invalid cart item'),
                ]);
            }

            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);

            // 移除商品
            $cartService->removeFromCart($itemId, $customerId);

            // 获取更新后的总额
            $totals = $cartService->calculateTotals($customerId);
            $cartCount = $cartService->getCartItemCount($customerId);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Item removed from cart'),
                'totals' => [
                    'subtotal' => $totals['subtotal'] ?? 0,
                    'subtotal_formatted' => $this->formatPrice($totals['subtotal'] ?? 0),
                    'total' => $totals['total'] ?? 0,
                    'total_formatted' => $this->formatPrice($totals['total'] ?? 0),
                    'count' => $cartCount,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

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
}
