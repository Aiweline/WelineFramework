<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Api;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Service\CartIdentityService;
use WeShop\Cart\Service\CartService;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use WeShop\Product\Service\ConfigurableProductService;

class Add extends FrontendController
{
    public function __construct(
        private readonly PriceService $priceService
    ) {
    }

    public function index(): void
    {
        $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').add()");
        return;

        header('Content-Type: application/json');

        try {
            if ($this->request->getMethod() !== 'POST') {
                $this->jsonError(__('请求方法不允许。'), 405);
                return;
            }

            /** @var CartIdentityService $cartIdentityService */
            $cartIdentityService = ObjectManager::getInstance(CartIdentityService::class);
            $customerId = $cartIdentityService->getCartCustomerId();

            $productId = (int) $this->request->getPost('product_id', 0);
            $qty = (int) $this->request->getPost('qty', 1);
            $selectedOptions = $this->request->getPost('selected_options', []);

            if (!is_array($selectedOptions)) {
                $selectedOptions = json_decode((string) $selectedOptions, true) ?? [];
            }

            if ($productId <= 0) {
                $this->jsonError(__('无效的商品 ID。'));
                return;
            }

            if ($qty <= 0) {
                $qty = 1;
            }

            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);

            if (!$product->getId()) {
                $this->jsonError(__('商品不存在。'));
                return;
            }

            if ($product->getStatus() !== 1) {
                $this->jsonError(__('商品已下架。'));
                return;
            }

            /** @var ConfigurableProductService $configurableService */
            $configurableService = ObjectManager::getInstance(ConfigurableProductService::class);
            $isConfigurable = $configurableService->isConfigurable($productId);

            $finalProductId = $productId;
            $finalPrice = $this->priceService->calculatePrice($productId, $customerId, $qty);
            $variant = null;

            if ($isConfigurable) {
                if ($selectedOptions === []) {
                    $this->jsonResponse([
                        'success' => false,
                        'requires_options' => true,
                        'options' => $configurableService->getConfigurableOptions($productId),
                        'message' => __('请选择商品规格。'),
                    ]);
                    return;
                }

                $variant = $configurableService->findVariantByOptions($productId, $selectedOptions);
                if (!$variant) {
                    $this->jsonError(__('所选商品规格组合不可用。'));
                    return;
                }

                $finalProductId = (int) $variant->getId();
                $finalPrice = $this->priceService->calculatePrice($finalProductId, $customerId, $qty);

                if ($variant->getStock() < $qty) {
                    $this->jsonError(__('库存不足，当前库存：%{1}', $variant->getStock()));
                    return;
                }
            } elseif ($product->getStock() < $qty) {
                $this->jsonError(__('库存不足，当前库存：%{1}', $product->getStock()));
                return;
            }

            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);
            $cart = $cartService->addToCart($customerId, $finalProductId, $qty, $finalPrice);
            $cartItemId = (int) $cart->getId();
            if ($cartItemId <= 0) {
                $cartItemId = $cartService->findCartItemId($customerId, $finalProductId);
                if ($cartItemId > 0) {
                    $cart->setId($cartItemId);
                }
            }

            $cartCount = $cartService->getCartItemCount($customerId);
            $totals = $cartService->calculateTotals($customerId);
            $productForResponse = $isConfigurable && $variant ? $variant : $product;

            $this->jsonResponse([
                'success' => true,
                'message' => __('已成功加入购物车。'),
                'cart_item_id' => $cartItemId,
                'cart_count' => $cartCount,
                'cart_total' => $totals['total'] ?? 0,
                'product' => [
                    'id' => $finalProductId,
                    'name' => $productForResponse->getName(),
                    'price' => $finalPrice,
                    'qty' => $qty,
                    'image' => $productForResponse->getImage() ?: $product->getImage(),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->jsonError(__('加入购物车失败：%{1}', [$e->getMessage()]), 500);
        }
    }

    public function getOptions(): void
    {
        $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').options()");
        return;

        header('Content-Type: application/json');

        try {
            $productId = (int) $this->request->getGet('product_id', 0);

            if ($productId <= 0) {
                $this->jsonError(__('无效的商品 ID。'));
                return;
            }

            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);

            if (!$product->getId()) {
                $this->jsonError(__('商品不存在。'));
                return;
            }

            /** @var ConfigurableProductService $configurableService */
            $configurableService = ObjectManager::getInstance(ConfigurableProductService::class);

            if (!$configurableService->isConfigurable($productId)) {
                $this->jsonResponse([
                    'success' => true,
                    'is_configurable' => false,
                    'options' => null,
                ]);
                return;
            }

            $this->jsonResponse([
                'success' => true,
                'is_configurable' => true,
                'product' => [
                    'id' => $productId,
                    'name' => $product->getName(),
                    'price' => $this->priceService->calculatePrice($productId),
                    'image' => $product->getImage(),
                ],
                'options' => $configurableService->getConfigurableOptions($productId),
            ]);
        } catch (\Throwable $e) {
            $this->jsonError(__('无法加载商品规格：%{1}', [$e->getMessage()]), 500);
        }
    }

    private function jsonResponse(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function jsonError(string $message, int $code = 400): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function deprecatedBrowserDirectResponse(string $replacement): void
    {
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'code' => 410,
            'msg' => (string)__('浏览器直连购物车 API 已弃用，请使用前台 Worker API。'),
            'data' => [
                'deprecated' => true,
                'browser_direct' => false,
                'replacement' => $replacement,
            ],
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
