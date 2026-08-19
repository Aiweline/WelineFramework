<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Product\Service\StorefrontCatalogViewService;

final class Catalog extends FrontendController
{
    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
    ) {
    }

    public function index(): string
    {
        $this->layoutType = 'product_list';
        $this->request->setGet('page_type', 'products');
        $this->request->setGet('theme_public_route', 'products');
        $this->request->setGet('theme_page_title', (string)__('商品'));
        $this->assign('page_title', __('商品'));
        $this->assign('storefront_offers', $this->catalog->publishedOffers());

        return (string)$this->fetch('Weline_Product::templates/frontend/catalog/index.phtml');
    }
}
