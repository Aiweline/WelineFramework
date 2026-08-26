<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontCategoryListingFilter;

final class Catalog extends FrontendController
{
    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
        private readonly StorefrontCategoryListingFilter $listingFilter,
    ) {
    }

    public function index(): string
    {
        $this->layoutType = 'product_list';
        $this->request->setGet('page_type', 'products');
        $this->request->setGet('layout_type', 'product_list');
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_public_route', 'products');
        $this->request->setGet('theme_page_title', (string)__('商品'));
        // Catalog template owns sort/count toolbar; hide layout slot placeholder.
        $this->assign('showToolbar', false);

        $offers = $this->catalog->publishedOffers();
        $priceBucket = $this->listingFilter->normalizePriceBucket((string)$this->request->getParam('price', ''));
        $sort = $this->listingFilter->normalizeSort((string)$this->request->getParam('sort', ''));
        $filteredOffers = $this->listingFilter->apply($offers, $priceBucket, $sort);

        $productsUrl = (string)$this->getUrl('products');
        $productsPath = parse_url($productsUrl, PHP_URL_PATH);
        if (is_string($productsPath) && $productsPath !== '') {
            $productsUrl = $productsPath;
        }
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

        $this->assign('page_title', __('商品'));
        $this->assign('storefront_offers_unfiltered', $offers);
        $this->assign('storefront_offers', $filteredOffers);
        $this->assign('storefront_listing_price', $priceBucket);
        $this->assign('storefront_listing_sort', $sort);
        $this->assign('storefront_listing_total', count($offers));
        $this->assign('storefront_listing_count', count($filteredOffers));
        $this->assign('storefront_listing_sort_options', $sortOptions);

        return (string)$this->fetch('Weline_Product::templates/frontend/catalog/index.phtml');
    }
}
