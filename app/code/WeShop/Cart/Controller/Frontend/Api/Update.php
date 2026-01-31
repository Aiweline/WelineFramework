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
     * @return array JSON 响应
     */
    public function index(): array
    {
        try {
            // 验证登录
            /** @var CustomerSession $session */
            $session = ObjectManager::getInstance(CustomerSession::class);
            $customer = $session->getCustomer();
            
            if (!$customer) {
                return [
                    'success' => false,
                    'message' => __('Please login first'),
                ];
            }
            
            // 获取请求参数
            $itemId = (int)$this->request->getParam('item_id', 0);
            $quantity = (int)$this->request->getParam('quantity', 1);
            
            if ($itemId <= 0) {
                return [
                    'success' => false,
                    'message' => __('Invalid cart item'),
                ];
            }
            
            if ($quantity <= 0) {
                return [
                    'success' => false,
                    'message' => __('Invalid quantity'),
                ];
            }
            
            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);
            
            // 更新数量
            $cartService->updateCart($itemId, $quantity, $customer->getId());
            
            // 获取更新后的总额
            $totals = $cartService->calculateTotals($customer->getId());
            $cartCount = $cartService->getCartItemCount($customer->getId());
            
            return [
                'success' => true,
                'message' => __('Cart updated'),
                'totals' => [
                    'subtotal' => $totals['subtotal'] ?? 0,
                    'subtotal_formatted' => $this->formatPrice($totals['subtotal'] ?? 0),
                    'total' => $totals['total'] ?? 0,
                    'total_formatted' => $this->formatPrice($totals['total'] ?? 0),
                    'count' => $cartCount,
                ],
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
