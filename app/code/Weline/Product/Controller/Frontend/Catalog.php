<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\StorefrontCatalogViewService;

final class Catalog extends FrontendController
{
    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
    ) {
    }

    public function index(): string
    {
        $this->layoutType = 'default';
        $this->request->setGet('page_type', 'products');
        $this->request->setGet('theme_public_route', 'products');
        $this->request->setGet('theme_page_title', (string)__('商品'));
        $this->assign('page_title', __('商品'));
        $this->assign('storefront_offers', $this->catalog->publishedOffers());
        $this->assign('cart_guest_token', $this->ensureCartGuestToken());

        return (string)$this->fetch('Weline_Product::templates/frontend/catalog/index.phtml');
    }

    private function ensureCartGuestToken(): string
    {
        if (!\class_exists(\Weline\Cart\Service\CartV2Service::class)) {
            return '';
        }
        $cookieName = \Weline\Cart\Service\CartV2Service::GUEST_TOKEN_COOKIE;
        $token = \trim((string)Cookie::get($cookieName, ''));
        if ($token === '') {
            /** @var \Weline\Cart\Service\CartV2Service $cart */
            $cart = ObjectManager::getInstance(\Weline\Cart\Service\CartV2Service::class);
            $token = $cart->issueGuestToken();
            Cookie::set($cookieName, $token, 3600 * 24 * 7, [
                'path' => '/',
                'secure' => $this->request->getSsl() === 'https',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $token;
    }
}
