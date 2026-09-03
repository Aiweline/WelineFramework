<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Service\StorefrontCatalogSurfaceResolver;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontCategoryListingFilter;

final class Catalog extends FrontendController
{
    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
        private readonly StorefrontCategoryListingFilter $listingFilter,
        private readonly StorefrontCatalogSurfaceResolver $surfaceResolver,
        private readonly EventsManager $events,
    ) {
    }

    public function index(): string
    {
        $requestUri = (string)(
            $this->request->getServer('WELINE_ORIGIN_REQUEST_URI')
            ?? $this->request->getServer('REQUEST_URI')
            ?? $this->request->getPathInfo()
        );
        $surface = $this->surfaceResolver->resolve(
            $requestUri,
            (string)RequestContext::getWelineUserLang(),
        );

        $this->layoutType = $surface['layout_type'];
        $this->request->setGet('page_type', $surface['page_type']);
        $this->request->setGet('layout_type', $surface['layout_type']);
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_public_route', $surface['public_route']);
        $this->request->setGet('theme_page_title', $surface['title']);
        // Catalog template owns sort/count toolbar; hide layout slot placeholder.
        $this->assign('showToolbar', false);

        $offers = $this->catalog->publishedOffers();
        $priceBucket = $this->listingFilter->normalizePriceBucket((string)$this->request->getParam('price', ''));
        $sort = $this->listingFilter->normalizeSort((string)$this->request->getParam('sort', ''));
        $filteredOffers = $this->listingFilter->apply($offers, $priceBucket, $sort);
        $filterEvent = [
            'offers' => $filteredOffers,
            'query' => $this->request->getParams() ?: [],
            'surface' => (string)$surface['code'],
        ];
        $this->events->dispatch('Weline_Product::storefront_offers_filter', $filterEvent);
        $filteredOffers = is_array($filterEvent['offers'] ?? null) ? $filterEvent['offers'] : $filteredOffers;

        $productsUrl = (string)$this->getUrl($surface['public_route']);
        $productsPath = parse_url($productsUrl, PHP_URL_PATH);
        if (is_string($productsPath) && $productsPath !== '') {
            $productsUrl = $productsPath;
        }
        $page = $this->listingFilter->normalizePage($this->request->getParam('page', 1));
        $paged = $this->listingFilter->paginate($filteredOffers, $page);
        $pageOffers = $paged['items'];
        $sortOptions = [];
        foreach ([
            StorefrontCategoryListingFilter::SORT_DEFAULT => __('默认排序'),
            StorefrontCategoryListingFilter::SORT_PRICE_ASC => __('价格从低到高'),
            StorefrontCategoryListingFilter::SORT_PRICE_DESC => __('价格从高到低'),
            StorefrontCategoryListingFilter::SORT_NAME_ASC => __('名称 A-Z'),
        ] as $code => $label) {
            $params = [];
            if ($priceBucket !== '') {
                $params['price'] = $priceBucket;
            }
            if ($code !== StorefrontCategoryListingFilter::SORT_DEFAULT) {
                $params['sort'] = $code;
            }
            $sortOptions[] = [
                'code' => $code,
                'label' => (string)$label,
                'url' => $this->listingFilter->buildListingUrl($productsUrl, $params),
                'selected' => $sort === $code,
            ];
        }

        $pageOptions = [];
        if ($paged['total_pages'] > 1) {
            for ($p = 1; $p <= $paged['total_pages']; $p++) {
                $params = [];
                if ($priceBucket !== '') {
                    $params['price'] = $priceBucket;
                }
                if ($sort !== StorefrontCategoryListingFilter::SORT_DEFAULT) {
                    $params['sort'] = $sort;
                }
                if ($p > 1) {
                    $params['page'] = $p;
                }
                $pageOptions[] = [
                    'page' => $p,
                    'url' => $this->listingFilter->buildListingUrl($productsUrl, $params),
                    'selected' => $p === $paged['page'],
                ];
            }
        }

        $this->assign('page_title', $surface['title']);
        $this->assign('storefront_heading', $surface['heading']);
        $this->assign('storefront_lede', $surface['lede']);
        $this->assign('storefront_surface', $surface['code']);
        $this->assign('storefront_offers_unfiltered', $offers);
        $this->assign('storefront_offers', $pageOffers);
        $this->assign('storefront_listing_price', $priceBucket);
        $this->assign('storefront_listing_sort', $sort);
        $this->assign('storefront_listing_total', count($offers));
        $this->assign('storefront_listing_count', $paged['total']);
        $this->assign('storefront_listing_page', $paged['page']);
        $this->assign('storefront_listing_page_size', $paged['page_size']);
        $this->assign('storefront_listing_total_pages', $paged['total_pages']);
        $this->assign('storefront_listing_page_options', $pageOptions);
        $this->assign('storefront_listing_sort_options', $sortOptions);

        return (string)$this->fetch('Weline_Product::templates/frontend/catalog/index.phtml');
    }
}
