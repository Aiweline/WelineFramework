<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Cart;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Service\CartService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Product\Model\Product;

/**
 * 购物车添加控制器（表单提交方式）
 * 
 * 用于处理传统表单提交的加入购物车请求
 * 对于AJAX请求，请使用 Api/Add 控制器
 */
class Add extends FrontendController
{
    /**
     * 添加商品到购物车
     * 
     * POST 参数：
     * - product_id: int 产品ID（必需）
     * - qty: int 数量（默认1）
     * - redirect: string 成功后重定向URL（可选）
     * 
     * @return void
     */
    public function index(): void
    {
        // 验证请求方法
        if ($this->request->getMethod() !== 'POST') {
            $this->getMessageManager()->addError(__('请求方法不允许'));
            $this->redirect('cart/frontend/cart/index');
            return;
        }

        // 获取参数
        $productId = (int)$this->request->getPost('product_id', 0);
        $qty = (int)$this->request->getPost('qty', 1);
        $redirectUrl = $this->request->getPost('redirect', '');

        // 验证产品ID
        if ($productId <= 0) {
            $this->getMessageManager()->addError(__('无效的产品ID'));
            $this->redirectBack();
            return;
        }

        if ($qty <= 0) {
            $qty = 1;
        }

        try {
            // 获取客户信息
            /** @var CustomerSession $session */
            $session = ObjectManager::getInstance(CustomerSession::class);
            $customer = $session->getCustomer();
            
            if (!$customer || !$customer->getId()) {
                $this->getMessageManager()->addError(__('请先登录'));
                $this->redirect('customer/account/login');
                return;
            }

            $customerId = (int)$customer->getId();

            // 加载产品
            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);
            
            if (!$product->getId()) {
                $this->getMessageManager()->addError(__('产品不存在'));
                $this->redirectBack();
                return;
            }

            if ($product->getStatus() !== 1) {
                $this->getMessageManager()->addError(__('产品已下架'));
                $this->redirectBack();
                return;
            }

            // 检查库存
            if ($product->getStock() < $qty) {
                $this->getMessageManager()->addError(__('库存不足，当前库存: %{1}', $product->getStock()));
                $this->redirectBack();
                return;
            }

            // 添加到购物车
            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);
            $cartService->addToCart($customerId, $productId, $qty);

            $this->getMessageManager()->addSuccess(__('已成功加入购物车'));

            // 重定向
            if (!empty($redirectUrl)) {
                $this->redirect($redirectUrl);
            } else {
                $this->redirectBack();
            }

        } catch (\Throwable $e) {
            $this->getMessageManager()->addError(__('添加购物车失败: %{1}', $e->getMessage()));
            $this->redirectBack();
        }
    }

    /**
     * 重定向回上一页
     */
    private function redirectBack(): void
    {
        $referer = $this->request->getServer('HTTP_REFERER', '');
        if (!empty($referer)) {
            header('Location: ' . $referer);
            exit;
        }
        $this->redirect('cart/frontend/cart/index');
    }
}
