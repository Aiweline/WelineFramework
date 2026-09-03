<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Product\Service\StorefrontProductWidgetCatalog;

final class BestSellers extends FrontendController
{
    public function __construct(
        private readonly StorefrontProductWidgetCatalog $widgetCatalog,
    ) {
    }

    public function index(): string
    {
        $title = (string)__('热销榜');
        $this->layoutType = 'best_sellers';
        $this->request->setGet('page_type', 'best_sellers');
        $this->request->setGet('layout_type', 'best_sellers');
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_public_route', 'best-sellers');
        $this->request->setGet('theme_page_title', $title);

        $items = $this->widgetCatalog->bestSellerCards(24);

        $this->assign('page_title', $title);
        $this->assign('storefront_best_sellers', $items);
        $this->assign('storefront_best_sellers_count', count($items));

        return (string)$this->fetch('Weline_Product::templates/frontend/best-sellers/index.phtml');
    }
}
