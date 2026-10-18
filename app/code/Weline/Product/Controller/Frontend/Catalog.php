<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StorefrontPageContext;
use Weline\Product\Helper\StorefrontPageAssignBag;
use Weline\Product\Service\StorefrontCatalogSurfaceResolver;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontCategoryViewService;
use Weline\Product\Service\StorefrontListingFpcQueryKeys;
use Weline\Product\Service\StorefrontListingShell;
use Weline\Product\Service\StorefrontSeoListingFacts;

/**
 * 店面目录列表（/products）与分类根落地（/category、/categories）。
 *
 * IA：根 /category|/categories = 分类网格落地（非全量商品 PLP）；
 * 商品列表保留 /products；叶子 PLP 在 Category（/category/{path}）。
 *
 * query_keys CSV must match StorefrontListingFpcQueryKeys::csv().
 *
 * @Extra type=fpc enabled=true ttl=31536000 namespaces=website/default/catalog public_path_patterns=/products,/category,/categories query_keys=af_available_colors,af_available_sizes,af_brand,af_color,af_hanfu_chao_dai,af_hanfu_feng_ge,af_hanfu_gong_yi,af_hanfu_han_fu_zhi_shi,af_hanfu_pin_pai,af_hanfu_shang_shi_nian_fen_ji_jie,af_hanfu_shi_he_ji_jie,af_hanfu_shi_yong_chang_he,af_hanfu_shi_yong_ji_jie,af_hanfu_shi_yong_xing_bie,af_hanfu_tu_an,af_hanfu_xing_zhi,af_hanfu_zao_xing_feng_ge,af_hanfu_zhi_wu,af_hanfu_zhi_wu_gong_yi,af_hanfu_zhi_wu_ming_cheng,af_hanfu_zhu_zhi_wu_cheng_fen,af_material,af_size,af_style_type,page,price,sort
 */
final class Catalog extends FrontendController
{
    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
        private readonly StorefrontCatalogSurfaceResolver $surfaceResolver,
        private readonly StorefrontCategoryViewService $categoryView,
        private readonly StorefrontListingShell $listingShell,
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
            (string)RequestContext::getWelineWebsiteCode(),
        );

        if (($surface['code'] ?? '') === 'categories') {
            return $this->renderCategoriesLanding($surface);
        }

        return $this->renderProductListing($surface);
    }

    /**
     * @param array<string, string> $surface
     */
    private function renderCategoriesLanding(array $surface): string
    {
        $this->layoutType = $surface['layout_type'];
        $this->request->setGet('page_type', $surface['page_type']);
        $this->request->setGet('layout_type', $surface['layout_type']);
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_page_title', $surface['title']);
        // Root landing is a department grid — hide PLP filter chrome.
        $this->assign('showFilters', false);
        $this->assign('showToolbar', false);

        $landing = $this->categoryView->resolveRootLanding();
        $children = is_array($landing['children'] ?? null) ? $landing['children'] : [];
        $tree = is_array($landing['tree'] ?? null) ? $landing['tree'] : [];
        $breadcrumbs = is_array($landing['breadcrumbs'] ?? null) ? $landing['breadcrumbs'] : [];

        $this->assign('page_title', $surface['title']);
        $this->assign('storefront_heading', $surface['heading']);
        $this->assign('storefront_lede', $surface['lede']);
        $this->assign('storefront_surface', $surface['code']);
        $this->assign('storefront_category_children', $children);
        $this->assign('storefront_category_tree', $tree);
        $this->assign('storefront_category_breadcrumbs', $breadcrumbs);
        $this->assign('storefront_offers', []);
        $this->assign('storefront_offers_unfiltered', []);
        StorefrontPageAssignBag::replace([
            'page_title' => $surface['title'],
            'storefront_heading' => $surface['heading'],
            'storefront_lede' => $surface['lede'],
            'storefront_surface' => $surface['code'],
            'storefront_category_children' => $children,
            'storefront_category_tree' => $tree,
            'storefront_category_breadcrumbs' => $breadcrumbs,
            'storefront_offers' => [],
            'storefront_offers_unfiltered' => [],
        ]);

        $listingFacts = new StorefrontSeoListingFacts();
        $this->assign('seo', [
            'page_type' => $surface['page_type'],
            'title' => $surface['seo_title'] !== '' ? $surface['seo_title'] : $surface['title'],
            'description' => $surface['seo_description'],
            'keywords' => $surface['seo_keywords'] ?? '',
            'image' => (string)($surface['share_image'] ?? ''),
            'image_alt' => $surface['share_image_alt'] ?? '',
            'item_list' => [],
            'item_list_total' => 0,
            'item_list_page' => 1,
            'breadcrumbs' => $listingFacts->withHomeBreadcrumb([
                [
                    'name' => $surface['title'] !== '' ? $surface['title'] : $surface['heading'],
                    'url' => '/' . ltrim((string)$surface['public_route'], '/'),
                ],
            ]),
        ]);

        return (string)$this->fetch('Weline_Product::templates/frontend/catalog/categories.phtml');
    }

    /**
     * @param array<string, string> $surface
     */
    private function renderProductListing(array $surface): string
    {
        $this->layoutType = $surface['layout_type'];
        $this->request->setGet('page_type', $surface['page_type']);
        $this->request->setGet('layout_type', $surface['layout_type']);
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_page_title', $surface['title']);
        // Catalog template owns sort/count toolbar; hide layout slot placeholder.
        $this->assign('showToolbar', false);

        // Empty query_keys hit → path-only neutral shell. Allowlisted price/sort/page/af_*
        // → facet-listing bag + projectFiltered so Shared FPC body matches the key.
        $listingQuery = StorefrontListingFpcQueryKeys::fromRequest($this->request);
        $bindListingQuery = $listingQuery !== [];
        $isInternalStorefrontChainWarmupEarly = (string)$this->request->getServer('WLS_INTERNAL_STOREFRONT_CHAIN_WARMUP') === '1';
        if ($bindListingQuery
            && !$isInternalStorefrontChainWarmupEarly
            && \class_exists(\Weline\Server\Security\SearchFilterOriginGate::class)
        ) {
            \Weline\Server\Security\SearchFilterOriginGate::assertHtmlExtraVariantOrTerminate($this->request);
        }
        $offers = $this->catalog->publishedListingCandidates(1000, $bindListingQuery);
        StorefrontPageContext::setListingOffers($offers);

        $productsUrl = (string)$this->getUrl($surface['public_route']);
        $productsPath = parse_url($productsUrl, PHP_URL_PATH);
        if (is_string($productsPath) && $productsPath !== '') {
            $productsUrl = $productsPath;
        }
        // A diagnostic chain warmup only needs to hydrate the process-local
        // router/template/slot/catalog chain. The normal storefront FPC
        // warmup deliberately keeps the public page shape so it can safely
        // publish the rendered response for later anonymous hits.
        $isInternalStorefrontChainWarmup = (string)$this->request->getServer('WLS_INTERNAL_STOREFRONT_CHAIN_WARMUP') === '1';
        if ($bindListingQuery && !$isInternalStorefrontChainWarmup) {
            $projection = $this->listingShell->projectFiltered(
                $offers,
                $listingQuery,
                $productsUrl,
                (string)$surface['code'],
            );
        } else {
            $projection = $this->listingShell->projectNeutral(
                $offers,
                $productsUrl,
                (string)$surface['code'],
                $isInternalStorefrontChainWarmup ? 1 : null,
            );
        }

        $this->assign('page_title', $surface['title']);
        $this->assign('storefront_heading', $surface['heading']);
        $this->assign('storefront_lede', $surface['lede']);
        $this->assign('storefront_surface', $surface['code']);
        $this->assign('storefront_offers_unfiltered', $offers);
        $this->assign('storefront_offers', $projection['page_offers']);
        $this->assign('storefront_listing_price', $projection['price']);
        $this->assign('storefront_listing_sort', $projection['sort']);
        $this->assign('storefront_listing_total', $projection['total']);
        $this->assign('storefront_listing_count', $projection['count']);
        $this->assign('storefront_listing_page', $projection['page']);
        $this->assign('storefront_listing_page_size', $projection['page_size']);
        $this->assign('storefront_listing_total_pages', $projection['total_pages']);
        $this->assign('storefront_listing_page_options', $projection['page_options']);
        $this->assign('storefront_listing_sort_options', $projection['sort_options']);
        $this->assign('storefront_listing_path', $this->listingShell->normalizeListingPath($productsUrl));
        // Soft JS must not BinQuery-refetch when Shared FPC already painted projectFiltered.
        $this->assign('storefront_listing_ssr_filtered', $bindListingQuery && !$isInternalStorefrontChainWarmup);
        StorefrontPageAssignBag::replace([
            'page_title' => $surface['title'],
            'storefront_heading' => $surface['heading'],
            'storefront_lede' => $surface['lede'],
            'storefront_surface' => $surface['code'],
            'storefront_offers_unfiltered' => $offers,
            'storefront_offers' => $projection['page_offers'],
            'storefront_listing_price' => $projection['price'],
            'storefront_listing_sort' => $projection['sort'],
            'storefront_listing_total' => $projection['total'],
            'storefront_listing_count' => $projection['count'],
            'storefront_listing_page' => $projection['page'],
            'storefront_listing_page_size' => $projection['page_size'],
            'storefront_listing_total_pages' => $projection['total_pages'],
            'storefront_listing_page_options' => $projection['page_options'],
            'storefront_listing_sort_options' => $projection['sort_options'],
            'storefront_listing_path' => $this->listingShell->normalizeListingPath($productsUrl),
            'storefront_listing_ssr_filtered' => $bindListingQuery && !$isInternalStorefrontChainWarmup,
        ]);

        $listingFacts = new StorefrontSeoListingFacts();
        $this->assign('seo', [
            'page_type' => $surface['page_type'],
            'title' => $surface['seo_title'] !== '' ? $surface['seo_title'] : $surface['title'],
            'description' => $surface['seo_description'],
            'keywords' => $surface['seo_keywords'] ?? '',
            'image' => (string)($surface['share_image'] ?? ''),
            'image_alt' => $surface['share_image_alt'] ?? '',
            'item_list' => $listingFacts->itemListFromOffers($projection['page_offers']),
            'item_list_total' => (int)$projection['count'],
            'item_list_page' => (int)$projection['page'],
            'breadcrumbs' => $listingFacts->withHomeBreadcrumb([
                [
                    'name' => $surface['title'] !== '' ? $surface['title'] : $surface['heading'],
                    'url' => '/' . ltrim((string)$surface['public_route'], '/'),
                ],
            ]),
        ]);

        return (string)$this->fetch('Weline_Product::templates/frontend/catalog/index.phtml');
    }
}
