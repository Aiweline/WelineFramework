<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Api;

use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Service\CartService;
use WeShop\Customer\Session\CustomerSession;

/**
 * 购物车更新 API
 * 
 * 更新购物车商品数量
 */
class Update extends BaseController
{
    /**
     * 更新购物车商品数量
     *
     * 使用 fetchJson，确保 Content-Type 与响应体一致（FPM/WLS 均可靠解析）。
     */
    public function index(): string
    {
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
            $quantity = (int)$this->request->getParam('quantity', 1);

            if ($itemId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Invalid cart item'),
                ]);
            }

            if ($quantity <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Invalid quantity'),
                ]);
            }

            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);

            // 更新数量
            $cartService->updateCart($itemId, $quantity, $customerId);

            // 获取更新后的总额
            $totals = $cartService->calculateTotals($customerId);
            $cartCount = $cartService->getCartItemCount($customerId);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Cart updated'),
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
}
