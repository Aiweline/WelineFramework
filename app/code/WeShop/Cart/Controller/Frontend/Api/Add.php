<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Api;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Service\CartService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use WeShop\Product\Service\ConfigurableProductService;

/**
 * 购物车添加API控制器
 * 
 * 处理AJAX添加商品到购物车的请求
 */
class Add extends FrontendController
{
    public function __construct(
        private readonly PriceService $priceService
    ) {
    }

    /**
     * 添加商品到购物车
     * 
     * POST 参数：
     * - product_id: int 产品ID（必需）
     * - qty: int 数量（默认1）
     * - selected_options: array 选中的选项ID数组（可配置产品需要）
     * 
     * @return void
     */
    public function index(): void
    {
        header('Content-Type: application/json');
        
        try {
            // 验证请求方法
            if ($this->request->getMethod() !== 'POST') {
                $this->jsonError(__('请求方法不允许'), 405);
                return;
            }

            // 获取参数
            $productId = (int)$this->request->getPost('product_id', 0);
            $qty = (int)$this->request->getPost('qty', 1);
            $selectedOptions = $this->request->getPost('selected_options', []);
            
            if (!is_array($selectedOptions)) {
                $selectedOptions = json_decode($selectedOptions, true) ?? [];
            }

            // 验证产品ID
            if ($productId <= 0) {
                $this->jsonError(__('无效的产品ID'));
                return;
            }

            if ($qty <= 0) {
                $qty = 1;
            }

            // 获取客户信息
            /** @var CustomerSession $session */
            $session = ObjectManager::getInstance(CustomerSession::class);
            $customer = $session->getCustomer();
            
            if (!$customer || !$customer->getId()) {
                $this->jsonError(__('请先登录'), 401);
                return;
            }

            $customerId = (int)$customer->getId();

            // 加载产品
            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);
            
            if (!$product->getId()) {
                $this->jsonError(__('产品不存在'));
                return;
            }

            if ($product->getStatus() !== 1) {
                $this->jsonError(__('产品已下架'));
                return;
            }

            // 检查是否为可配置产品
            /** @var ConfigurableProductService $configurableService */
            $configurableService = ObjectManager::getInstance(ConfigurableProductService::class);
            $isConfigurable = $configurableService->isConfigurable($productId);

            $finalProductId = $productId;
            $finalPrice = $this->priceService->calculatePrice($productId);

            if ($isConfigurable) {
                // 可配置产品必须选择选项
                if (empty($selectedOptions)) {
                    // 返回需要选择选项的响应
                    $options = $configurableService->getConfigurableOptions($productId);
                    $this->jsonResponse([
                        'success' => false,
                        'requires_options' => true,
                        'options' => $options,
                        'message' => __('请选择产品规格'),
                    ]);
                    return;
                }

                // 根据选中的选项找到对应的子产品
                $variant = $configurableService->findVariantByOptions($productId, $selectedOptions);
                if (!$variant) {
                    $this->jsonError(__('所选规格组合不可用'));
                    return;
                }

                $finalProductId = (int)$variant->getId();
                $finalPrice = $this->priceService->calculatePrice($finalProductId);

                // 检查子产品库存
                if ($variant->getStock() < $qty) {
                    $this->jsonError(__('库存不足，当前库存: %1', $variant->getStock()));
                    return;
                }
            } else {
                // 简单产品检查库存
                if ($product->getStock() < $qty) {
                    $this->jsonError(__('库存不足，当前库存: %1', $product->getStock()));
                    return;
                }
            }

            // 添加到购物车
            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);
            $cart = $cartService->addToCart($customerId, $finalProductId, $qty, $finalPrice);

            // 获取购物车总数
            $cartCount = $cartService->getCartItemCount($customerId);
            $totals = $cartService->calculateTotals($customerId);

            $this->jsonResponse([
                'success' => true,
                'message' => __('已成功加入购物车'),
                'cart_item_id' => $cart->getId(),
                'cart_count' => $cartCount,
                'cart_total' => $totals['total'] ?? 0,
                'product' => [
                    'id' => $finalProductId,
                    'name' => $isConfigurable ? $variant->getName() : $product->getName(),
                    'price' => $finalPrice,
                    'qty' => $qty,
                    'image' => $isConfigurable ? ($variant->getImage() ?: $product->getImage()) : $product->getImage(),
                ],
            ]);

        } catch (\Throwable $e) {
            $this->jsonError(__('添加购物车失败: %1', $e->getMessage()), 500);
        }
    }

    /**
     * 获取可配置产品的选项信息
     * 
     * GET 参数：
     * - product_id: int 产品ID
     * 
     * @return void
     */
    public function getOptions(): void
    {
        header('Content-Type: application/json');

        try {
            $productId = (int)$this->request->getGet('product_id', 0);
            
            if ($productId <= 0) {
                $this->jsonError(__('无效的产品ID'));
                return;
            }

            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);
            
            if (!$product->getId()) {
                $this->jsonError(__('产品不存在'));
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

            $options = $configurableService->getConfigurableOptions($productId);

            $this->jsonResponse([
                'success' => true,
                'is_configurable' => true,
                'product' => [
                    'id' => $productId,
                    'name' => $product->getName(),
                    'price' => $this->priceService->calculatePrice($productId),
                    'image' => $product->getImage(),
                ],
                'options' => $options,
            ]);

        } catch (\Throwable $e) {
            $this->jsonError(__('获取产品选项失败: %1', $e->getMessage()), 500);
        }
    }

    /**
     * 返回JSON成功响应
     * 
     * @param array $data
     * @return void
     */
    private function jsonResponse(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 返回JSON错误响应
     * 
     * @param string $message
     * @param int $code
     * @return void
     */
    private function jsonError(string $message, int $code = 400): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ], JSON_UNESCAPED_UNICODE);
    }
}
