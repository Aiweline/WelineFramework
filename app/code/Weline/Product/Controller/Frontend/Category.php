<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Event\EventsManager;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontCategoryListingFilter;
use Weline\Product\Service\StorefrontCategoryViewService;

final class Category extends FrontendController
{
    public function __construct(
        private readonly StorefrontCategoryViewService $categories,
        private readonly StorefrontCatalogViewService $catalog,
        private readonly StorefrontCategoryListingFilter $listingFilter,
        private readonly EventsManager $events,
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

        $productIds = $page['product_ids'];
        // WLS request params also include values injected into the request context
        // by routing/layout hooks. Use the raw query string for filter projection
        // decisions so only an actual af_* URL filter expands listing details.
        $queryParams = method_exists($this->request, 'getQueryParams')
            ? (array)($this->request->getQueryParams() ?? [])
            : ($this->request->getParams() ?: []);
        $includeListingDetails = false;
        foreach ($queryParams as $queryKey => $queryValue) {
            $queryKey = strtolower(trim((string)$queryKey));
            $queryValue = is_array($queryValue) ? (string)reset($queryValue) : (string)$queryValue;
            if (str_starts_with($queryKey, 'af_') && trim($queryValue) !== '') {
                $includeListingDetails = true;
                break;
            }
        }
        $offers = $productIds === []
            ? []
            : $this->catalog->publishedOffersForProductIds($productIds, 120, $includeListingDetails);
        $priceBucket = $this->listingFilter->normalizePriceBucket((string)$this->request->getParam('price', ''));
        $sort = $this->listingFilter->normalizeSort((string)$this->request->getParam('sort', ''));
        $filteredOffers = $this->listingFilter->apply($offers, $priceBucket, $sort);
        $filterEvent = [
            'offers' => $filteredOffers,
            'query' => $queryParams,
            'surface' => 'category',
        ];
        $this->events->dispatch('Weline_Product::storefront_offers_filter', $filterEvent);
        $filteredOffers = is_array($filterEvent['offers'] ?? null) ? $filterEvent['offers'] : $filteredOffers;

        $categoryUrl = trim((string)($category['url'] ?? ''));
        if ($categoryUrl === '') {
            $categoryUrl = (string)$this->getUrl($routePath !== '' ? 'category/' . $routePath : 'categories');
        } else {
            $pathOnly = (string)(parse_url($categoryUrl, PHP_URL_PATH) ?: $categoryUrl);
            $categoryUrl = (string)$this->getUrl(ltrim($pathOnly, '/'));
        }
        $pageNum = $this->listingFilter->normalizePage($this->request->getParam('page', 1));
        $paged = $this->listingFilter->paginate($filteredOffers, $pageNum);
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
                'url' => $this->listingFilter->buildListingUrl($categoryUrl, $params),
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
                    'url' => $this->listingFilter->buildListingUrl($categoryUrl, $params),
                    'selected' => $p === $paged['page'],
                ];
            }
        }

        $this->assign('page_title', $name !== '' ? $name : __('分类'));
        $this->assign('storefront_category', $category);
        $this->assign('storefront_category_children', $page['children']);
        $this->assign('storefront_category_siblings', $page['siblings'] ?? []);
        $this->assign('storefront_category_tree', $page['tree'] ?? []);
        $this->assign('storefront_category_active_path_ids', $page['active_path_ids'] ?? []);
        $this->assign('storefront_category_breadcrumbs', $page['breadcrumbs']);
        $this->assign('storefront_offers_unfiltered', $offers);
        $this->assign('storefront_offers', $pageOffers);
        $this->assign('storefront_category_path', $routePath);
        $this->assign('storefront_listing_price', $priceBucket);
        $this->assign('storefront_listing_sort', $sort);
        $this->assign('storefront_listing_total', count($offers));
        $this->assign('storefront_listing_count', $paged['total']);
        $this->assign('storefront_listing_page', $paged['page']);
        $this->assign('storefront_listing_page_size', $paged['page_size']);
        $this->assign('storefront_listing_total_pages', $paged['total_pages']);
        $this->assign('storefront_listing_page_options', $pageOptions);
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
