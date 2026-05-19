<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Cart;

use Weline\Framework\App\Controller\FrontendController;
use WeShop\Cart\Service\CartIdentityService;
use WeShop\Cart\Service\CartService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 更新购物车控制器
 */
class Update extends FrontendController
{
    /**
     * 更新购物车
     */
    public function index(): string
    {
        return $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').update()");

        try {
            /** @var CartIdentityService $cartIdentityService */
            $cartIdentityService = ObjectManager::getInstance(CartIdentityService::class);
            
            $cartId = (int)($this->request->getParam('cart_id') ?? 0);
            $quantity = (int)($this->request->getParam('quantity') ?? 1);
            
            if (!$cartId) {
                return $this->fetchJson(['success' => false, 'message' => __('购物车ID不能为空')]);
            }
            
            if ($quantity <= 0) {
                return $this->fetchJson(['success' => false, 'message' => __('数量必须大于0')]);
            }
            
            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);
            $cartService->updateCart($cartId, $quantity, $cartIdentityService->getCartCustomerId());
            
            return $this->fetchJson(['success' => true, 'message' => __('购物车更新成功')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
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
            'msg' => (string)__('浏览器直连购物车 API 已弃用，请使用前台 Worker API。'),
            'data' => [
                'deprecated' => true,
                'browser_direct' => false,
                'replacement' => $replacement,
            ],
        ], JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
