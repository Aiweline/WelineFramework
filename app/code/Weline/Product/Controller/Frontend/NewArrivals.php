<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Product\Service\StorefrontProductWidgetCatalog;
use Weline\Product\Service\StorefrontSeoListingFacts;

final class NewArrivals extends FrontendController
{
    public function __construct(
        private readonly StorefrontProductWidgetCatalog $widgetCatalog,
        private readonly StorefrontSeoListingFacts $listingFacts = new StorefrontSeoListingFacts(),
    ) {
    }

    public function index(): string
    {
        $title = (string)__('新品上架');

        $this->layoutType = 'product_list';
        $this->request->setGet('page_type', 'product_list');
        $this->request->setGet('layout_type', 'product_list');
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_public_route', 'new-arrivals');
        $this->request->setGet('theme_page_title', $title);

        $items = $this->widgetCatalog->newArrivalCards(24, 3650);
        if ($items === []) {
            $items = $this->widgetCatalog->cards(24);
        }

        $this->assign('page_title', $title);
        $this->assign('storefront_new_arrivals', $items);
        $this->assign('storefront_new_arrivals_count', count($items));
        $this->assign('storefront_new_arrivals_rss_url', '/new-arrivals/rss.xml');
        $this->assign('seo', [
            'page_type' => 'product_list',
            'title' => $title,
            'item_list' => $this->listingFacts->itemListFromCards($items),
            'breadcrumbs' => $this->listingFacts->withHomeBreadcrumb([
                ['name' => $title, 'url' => '/new-arrivals'],
            ]),
            'feeds' => [[
                'type' => 'application/rss+xml',
                'title' => (string)__('新品 RSS'),
                'href' => '/new-arrivals/rss.xml',
            ]],
        ]);

        return (string)$this->fetch('Weline_Product::templates/frontend/new-arrivals/index.phtml');
    }
}
