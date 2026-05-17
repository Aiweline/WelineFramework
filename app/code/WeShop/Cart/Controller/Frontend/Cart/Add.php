<?php

declare(strict_types=1);

namespace WeShop\Cart\Controller\Frontend\Cart;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Service\CartIdentityService;
use WeShop\Cart\Service\CartService;
use WeShop\Product\Model\Product;

class Add extends FrontendController
{
    public function index(): void
    {
        if ($this->request->getMethod() !== 'POST') {
            $this->getMessageManager()->addError(__('Request method is not allowed.'));
            $this->redirect('weshop/cart');
            return;
        }

        $productId = (int) $this->request->getPost('product_id', 0);
        $qty = (int) $this->request->getPost('qty', 1);
        $redirectUrl = (string) $this->request->getPost('redirect', '');

        if ($productId <= 0) {
            $this->getMessageManager()->addError(__('Invalid product ID.'));
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
                $this->getMessageManager()->addError(__('Product does not exist.'));
                $this->redirectBack();
                return;
            }

            if ($product->getStatus() !== 1) {
                $this->getMessageManager()->addError(__('Product is disabled.'));
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

            $this->getMessageManager()->addSuccess(__('Added to cart successfully.'));

            if ($redirectUrl !== '') {
                $this->redirect($redirectUrl);
                return;
            }

            $this->redirectBack();
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError(__('Add to cart failed: %{1}', $e->getMessage()));
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

        $this->redirect('weshop/cart');
    }
}
