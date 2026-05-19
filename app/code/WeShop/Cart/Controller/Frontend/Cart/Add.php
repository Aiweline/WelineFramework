<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Cart;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Service\CartIdentityService;
use WeShop\Cart\Service\CartService;
use WeShop\Product\Model\Product;

class Add extends FrontendController
{
    public function index(): void
    {
        if ($this->request->getMethod() !== 'POST') {
            $this->getMessageManager()->addError(__('请求方法不允许。'));
            $this->redirect('/cart');
            return;
        }

        $productId = (int) $this->request->getPost('product_id', 0);
        $qty = (int) $this->request->getPost('qty', 1);
        $redirectUrl = (string) $this->request->getPost('redirect', '');

        if ($productId <= 0) {
            $this->getMessageManager()->addError(__('无效的商品 ID。'));
            $this->redirectBack();
            return;
        }

        if ($qty <= 0) {
            $qty = 1;
        }

        try {
            /** @var CartIdentityService $cartIdentityService */
            $cartIdentityService = ObjectManager::getInstance(CartIdentityService::class);
            $customerId = $cartIdentityService->getCartCustomerId();

            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $product->load($productId);

            if (!$product->getId()) {
                $this->getMessageManager()->addError(__('商品不存在。'));
                $this->redirectBack();
                return;
            }

            if ($product->getStatus() !== 1) {
                $this->getMessageManager()->addError(__('商品已下架。'));
                $this->redirectBack();
                return;
            }

            if ($product->getStock() < $qty) {
                $this->getMessageManager()->addError(__('Insufficient stock. Current stock: %{1}', $product->getStock()));
                $this->redirectBack();
                return;
            }

            /** @var CartService $cartService */
            $cartService = ObjectManager::getInstance(CartService::class);
            $cartService->addToCart($customerId, $productId, $qty);

            $this->getMessageManager()->addSuccess(__('已成功加入购物车。'));

            if ($redirectUrl !== '') {
                $this->redirect($redirectUrl);
                return;
            }

            $this->redirectBack();
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError(__('加入购物车失败：%{1}', $e->getMessage()));
            $this->redirectBack();
        }
    }

    private function redirectBack(): void
    {
        $referer = (string) $this->request->getServer('HTTP_REFERER', '');
        if ($referer !== '') {
            header('Location: ' . $referer);
            exit;
        }

        $this->redirect('/cart');
    }
}
