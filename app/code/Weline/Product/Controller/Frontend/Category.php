<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Runtime\StorefrontPageContext;
use Weline\Product\Helper\StorefrontPageAssignBag;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontCategoryViewService;
use Weline\Product\Service\StorefrontListingShell;
use Weline\Product\Service\StorefrontSeoListingFacts;

/**
 * 店面分类列表（/category/{path…}）。
 *
 * @Extra type=fpc enabled=true ttl=31536000 namespaces=website/default/catalog public_path_patterns=/category/**
 */
final class Category extends FrontendController
{
    public function __construct(
        private readonly StorefrontCategoryViewService $categories,
        private readonly StorefrontCatalogViewService $catalog,
        private readonly StorefrontListingShell $listingShell,
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

        $this->layoutType = 'category';
        $this->request->setGet('page_type', 'category');
        $this->request->setGet('layout_type', 'category');
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_page_title', $name !== '' ? $name : (string)__('分类'));
        // Keep path query aligned for layout hooks that resolve by request param.
        $this->request->setGet('path', $routePath);

        $productIds = $page['product_ids'];
        // Path-only FPC: SSR is always the neutral default listing (no af_/price/sort/page).
        $offers = $productIds === []
            ? []
            : $this->catalog->publishedOffersForProductIds($productIds, 120, false);
        StorefrontPageContext::setListingOffers($offers);

        $categoryUrl = trim((string)($category['url'] ?? ''));
        if ($categoryUrl === '') {
            $categoryUrl = (string)$this->getUrl($routePath !== '' ? 'category/' . $routePath : 'categories');
        } else {
            $pathOnly = (string)(parse_url($categoryUrl, PHP_URL_PATH) ?: $categoryUrl);
            $categoryUrl = (string)$this->getUrl(ltrim($pathOnly, '/'));
        }
        $categoryPath = parse_url($categoryUrl, PHP_URL_PATH);
        if (is_string($categoryPath) && $categoryPath !== '') {
            $categoryUrl = $categoryPath;
        }

        $projection = $this->listingShell->projectNeutral(
            $offers,
            $categoryUrl,
            'category',
        );

        $pageTitle = $name !== '' ? $name : __('分类');
        $this->assign('page_title', $pageTitle);
        $this->assign('storefront_category', $category);
        $this->assign('storefront_category_children', $page['children']);
        $this->assign('storefront_category_siblings', $page['siblings'] ?? []);
        $this->assign('storefront_category_tree', $page['tree'] ?? []);
        $this->assign('storefront_category_active_path_ids', $page['active_path_ids'] ?? []);
        $this->assign('storefront_category_breadcrumbs', $page['breadcrumbs']);
        $this->assign('storefront_offers_unfiltered', $offers);
        $this->assign('storefront_offers', $projection['page_offers']);
        $this->assign('storefront_category_path', $routePath);
        $this->assign('storefront_listing_price', $projection['price']);
        $this->assign('storefront_listing_sort', $projection['sort']);
        $this->assign('storefront_listing_total', $projection['total']);
        $this->assign('storefront_listing_count', $projection['count']);
        $this->assign('storefront_listing_page', $projection['page']);
        $this->assign('storefront_listing_page_size', $projection['page_size']);
        $this->assign('storefront_listing_total_pages', $projection['total_pages']);
        $this->assign('storefront_listing_page_options', $projection['page_options']);
        $this->assign('storefront_listing_sort_options', $projection['sort_options']);
        $this->assign('storefront_listing_path', $this->listingShell->normalizeListingPath($categoryUrl));
        StorefrontPageAssignBag::replace([
            'page_title' => $pageTitle,
            'storefront_category' => $category,
            'storefront_category_children' => $page['children'],
            'storefront_category_siblings' => $page['siblings'] ?? [],
            'storefront_category_tree' => $page['tree'] ?? [],
            'storefront_category_active_path_ids' => $page['active_path_ids'] ?? [],
            'storefront_category_breadcrumbs' => $page['breadcrumbs'],
            'storefront_offers_unfiltered' => $offers,
            'storefront_offers' => $projection['page_offers'],
            'storefront_category_path' => $routePath,
            'storefront_listing_price' => $projection['price'],
            'storefront_listing_sort' => $projection['sort'],
            'storefront_listing_total' => $projection['total'],
            'storefront_listing_count' => $projection['count'],
            'storefront_listing_page' => $projection['page'],
            'storefront_listing_page_size' => $projection['page_size'],
            'storefront_listing_total_pages' => $projection['total_pages'],
            'storefront_listing_page_options' => $projection['page_options'],
            'storefront_listing_sort_options' => $projection['sort_options'],
            'storefront_listing_path' => $this->listingShell->normalizeListingPath($categoryUrl),
        ]);

        $listingFacts = new StorefrontSeoListingFacts();
        $breadcrumbs = $listingFacts->withHomeBreadcrumb(
            $listingFacts->normalizeBreadcrumbs(is_array($page['breadcrumbs'] ?? null) ? $page['breadcrumbs'] : [])
        );
        $this->assign('seo', [
            'page_type' => 'category',
            'title' => $name !== '' ? $name : (string)__('分类'),
            'description' => trim((string)($category['meta_description'] ?? $category['description'] ?? '')),
            'image' => trim((string)($category['image'] ?? $category['banner'] ?? '')),
            'category' => $category,
            'item_list' => $listingFacts->itemListFromOffers($projection['page_offers']),
            'item_list_total' => (int)$projection['count'],
            'item_list_page' => (int)$projection['page'],
            'breadcrumbs' => $breadcrumbs,
        ]);

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
