<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Product\Service\StorefrontProductWidgetCatalog;
use Weline\Product\Service\StorefrontSeoListingFacts;

final class BestSellers extends FrontendController
{
    public function __construct(
        private readonly StorefrontProductWidgetCatalog $widgetCatalog,
        private readonly StorefrontSeoListingFacts $listingFacts = new StorefrontSeoListingFacts(),
    ) {
    }

    public function index(): string
    {
        $title = (string)__('热销榜');
        $this->layoutType = 'best_sellers';
        $this->request->setGet('page_type', 'product_list');
        $this->request->setGet('layout_type', 'best_sellers');
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_public_route', 'best-sellers');
        $this->request->setGet('theme_page_title', $title);

        $items = $this->widgetCatalog->bestSellerCards(24);

        $this->assign('page_title', $title);
        $this->assign('storefront_best_sellers', $items);
        $this->assign('storefront_best_sellers_count', count($items));
        $this->assign('seo', [
            'page_type' => 'product_list',
            'title' => $title,
            'item_list' => $this->listingFacts->itemListFromCards($items),
            'breadcrumbs' => $this->listingFacts->withHomeBreadcrumb([
                ['name' => $title, 'url' => '/best-sellers'],
            ]),
        ]);

        return (string)$this->fetch('Weline_Product::templates/frontend/best-sellers/index.phtml');
    }
}
