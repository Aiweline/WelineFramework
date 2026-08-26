<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontCategoryListingFilter;
use Weline\Product\Service\StorefrontCategoryViewService;

final class Category extends FrontendController
{
    public function __construct(
        private readonly StorefrontCategoryViewService $categories,
        private readonly StorefrontCatalogViewService $catalog,
        private readonly StorefrontCategoryListingFilter $listingFilter,
    ) {
    }

    public function index(): string
    {
        $publicPath = trim(str_replace('\\', '/', (string)$this->request->getParam('path', '')), '/');
        if ($publicPath === '') {
            $publicPath = $this->resolvePathFromRequestUri();
        }

        $page = $this->categories->resolvePage($publicPath);
        if ($page === null) {
            $page = $this->categories->synthesizePageFromPublicPath($publicPath);
        }
        if ($page === null) {
            $this->getMessageManager()->addError(__('分类不存在或当前不可用。'));
            return (string)$this->redirect($this->getUrl('products'));
        }

        $category = $page['category'];
        $name = trim((string)($category['name'] ?? ''));
        $routePath = trim(str_replace('\\', '/', (string)($category['path'] ?? $publicPath)), '/');
        $themeRoute = $routePath !== '' ? 'category/' . $routePath : 'categories';

        $this->layoutType = 'category';
        $this->request->setGet('page_type', 'category');
        $this->request->setGet('layout_type', 'category');
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_public_route', $themeRoute);
        $this->request->setGet('theme_page_title', $name !== '' ? $name : (string)__('分类'));
        // Keep path query aligned for layout hooks that resolve by request param.
        $this->request->setGet('path', $routePath);

        $offers = $this->catalog->publishedOffersForProductIds($page['product_ids'], 120);
        $priceBucket = $this->listingFilter->normalizePriceBucket((string)$this->request->getParam('price', ''));
        $sort = $this->listingFilter->normalizeSort((string)$this->request->getParam('sort', ''));
        $filteredOffers = $this->listingFilter->apply($offers, $priceBucket, $sort);

        $categoryUrl = trim((string)($category['url'] ?? ''));
        if ($categoryUrl === '') {
            $categoryUrl = $routePath !== '' ? '/category/' . $routePath : '/categories';
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
                'url' => $this->listingFilter->buildListingUrl($categoryUrl, $params),
                'selected' => $sort === $code,
            ];
        }

        $this->assign('page_title', $name !== '' ? $name : __('分类'));
        $this->assign('storefront_category', $category);
        $this->assign('storefront_category_children', $page['children']);
        $this->assign('storefront_category_siblings', $page['siblings'] ?? []);
        $this->assign('storefront_category_tree', $page['tree'] ?? []);
        $this->assign('storefront_category_active_path_ids', $page['active_path_ids'] ?? []);
        $this->assign('storefront_category_breadcrumbs', $page['breadcrumbs']);
        $this->assign('storefront_offers_unfiltered', $offers);
        $this->assign('storefront_offers', $filteredOffers);
        $this->assign('storefront_category_path', $routePath);
        $this->assign('storefront_listing_price', $priceBucket);
        $this->assign('storefront_listing_sort', $sort);
        $this->assign('storefront_listing_total', count($offers));
        $this->assign('storefront_listing_count', count($filteredOffers));
        $this->assign('storefront_listing_sort_options', $sortOptions);

        return (string)$this->fetch('Weline_Product::templates/frontend/category/index.phtml');
    }

    private function resolvePathFromRequestUri(): string
    {
        $uri = (string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? '');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '');
        $path = trim(str_replace('\\', '/', $path), '/');
        if (str_starts_with(strtolower($path), 'category/')) {
            return substr($path, strlen('category/'));
        }

        return $path;
    }
}
