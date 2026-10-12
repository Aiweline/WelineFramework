<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Service\StorefrontCatalogSurfaceResolver;
use Weline\Product\Service\StorefrontProductWidgetCatalog;
use Weline\Product\Service\StorefrontSeoListingFacts;

final class NewArrivals extends FrontendController
{
    public function __construct(
        private readonly StorefrontProductWidgetCatalog $widgetCatalog,
        private readonly StorefrontSeoListingFacts $listingFacts = new StorefrontSeoListingFacts(),
        private readonly StorefrontCatalogSurfaceResolver $surfaceResolver = new StorefrontCatalogSurfaceResolver(),
    ) {
    }

    public function index(): string
    {
        $title = (string)__('新品上架');
        $eyebrow = $title;
        $websiteCode = (string)RequestContext::getWelineWebsiteCode();
        $surface = $this->surfaceResolver->resolve(
            '/new-arrivals',
            (string)RequestContext::getWelineUserLang(),
            $websiteCode,
        );
        $surfaceTitle = trim((string)($surface['title'] ?? ''));
        $surfaceHeading = trim((string)($surface['heading'] ?? ''));
        if ($surfaceTitle !== '') {
            $eyebrow = $surfaceTitle;
        }
        if ($surfaceHeading !== '') {
            $title = $surfaceHeading;
        }
        $this->assign('storefront_new_arrivals_lede', (string)($surface['lede'] ?? ''));
        $this->assign('storefront_new_arrivals_eyebrow', $eyebrow);

        $presentation = is_array($surface['presentation'] ?? null) ? $surface['presentation'] : [];
        if (array_key_exists('show_filters', $presentation)) {
            $this->assign('showFilters', (bool)$presentation['show_filters']);
        }
        if (array_key_exists('show_toolbar', $presentation)) {
            $this->assign('showToolbar', (bool)$presentation['show_toolbar']);
        }
        if (array_key_exists('show_recommendations', $presentation)) {
            $this->assign('showRecommendations', (bool)$presentation['show_recommendations']);
        }

        $this->layoutType = 'products';
        $this->request->setGet('page_type', 'products');
        $this->request->setGet('layout_type', 'products');
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_page_title', $title);

        $cardLimit = (int)($presentation['card_limit'] ?? 24);
        if ($cardLimit < 1) {
            $cardLimit = 24;
        }
        $items = $this->widgetCatalog->newArrivalCards($cardLimit, 3650);
        if ($items === []) {
            $items = $this->widgetCatalog->cards($cardLimit);
        }

        $this->assign('page_title', $title);
        $this->assign('storefront_new_arrivals_eyebrow', $eyebrow);
        $this->assign('storefront_new_arrivals', $items);
        $this->assign('storefront_new_arrivals_count', count($items));
        $this->assign('storefront_new_arrivals_rss_url', '/new-arrivals/rss.xml');
        $this->assign('seo', [
            'page_type' => 'products',
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
