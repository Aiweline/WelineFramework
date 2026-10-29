<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
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
        // 取回上限：候选被截断时，过滤后 0 命中不能证明「该组合无货」，
        // 否则会把有货的 facet URL 永久 301 掉（架构评审 R4）。
        $offersTruncated = \count($offers) >= 1000;
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
            // 廉价预判定（不渲染 grid / 不补媒体）：零命中的 facet 组合直接 301，
            // 不为垃圾 URL 付整页渲染成本（CPU 饱和时这正是 503 的来源）。
            $zeroHitProbe = $this->listingShell->projectFiltered(
                $offers,
                $listingQuery,
                $productsUrl,
                (string)$surface['code'],
                null,
                false,
                false,
                false,
            );
            if ($offers !== []
                && !$offersTruncated
                && (int)($zeroHitProbe['count'] ?? 0) === 0
            ) {
                $canonicalRedirectUrl = $this->canonicalRedirectUrl($productsUrl);
                RequestContext::set(
                    'weline.frontend.redirect.canonical_host',
                    (string)(parse_url($canonicalRedirectUrl, PHP_URL_HOST) ?: '')
                );
                $this->request->getResponse()->redirect($canonicalRedirectUrl, 301);
            }
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

        if ($bindListingQuery
            && !$isInternalStorefrontChainWarmup
            && $offers !== []
            && !$offersTruncated
            && (int)($projection['count'] ?? 0) === 0
        ) {
            // 无命中的 facet 组合不再铸造可索引/可缓存变体：永久重定向回规范列表页，
            // 否则爬虫可用 af_* 组合产生无限 URL，每次都是一次冷构建。
            // 落点主机取站点主域，并打请求级 flag 让 ResponseRedirectBefore 本次不改写 host。
            $canonicalRedirectUrl = $this->canonicalRedirectUrl($productsUrl);
            RequestContext::set(
                'weline.frontend.redirect.canonical_host',
                (string)(parse_url($canonicalRedirectUrl, PHP_URL_HOST) ?: '')
            );
            $this->request->getResponse()->redirect($canonicalRedirectUrl, 301);
        }

        if ($bindListingQuery
            && !$isInternalStorefrontChainWarmup
            && (int)($projection['count'] ?? 0) === 0
        ) {
            // 零命中的 facet 组合不进索引（301 已在上面处理；这里覆盖「候选被截断、
            // 无法证明无货」的 200 渲染，避免爬虫把无限变体抓进索引）。
            $this->request->getResponse()->setHeader('X-Robots-Tag', 'noindex, follow');
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

    private function canonicalRedirectUrl(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $host = $this->canonicalPublicHost();

        return $host === '' ? $path : 'https://' . $host . $path;
    }

    /**
     * 站点主域取值顺序（与 ManagedNginxConfigWriter::resolvePublicHost() 同源意图：
     * Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php:1500-1518）：
     *   1) var/server/config/{instance}.json 的 public_host
     *   2) var/server/instances/{instance}.json 的 public_host
     *   3) env wls.edge.nginx.public_host
     *   4) env wls.public_host
     *   5) 当前请求 Host
     *
     * TODO(changanhanfu-503-fix/next): 下一批做 (a) 全局归一（ResponseRedirectBefore 直接读
     *   wls.public_host 规范化）后，删除本 helper、canonicalRedirectUrl() 与
     *   RequestContext 'weline.frontend.redirect.canonical_host' flag。
     */
    private function canonicalPublicHost(): string
    {
        $valid = static fn(string $host): bool => preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/i', $host) === 1;
        $instanceName = '';
        try {
            $env = Env::getInstance();
            $instanceName = trim((string)$env->getConfig('wls.instance', $env->getConfig('wls.instance_name', 'default')));
        } catch (\Throwable) {
            $instanceName = '';
        }
        if ($instanceName === '') {
            $instanceName = 'default';
        }
        $varRoot = defined('VAR_DIR')
            ? (string)\constant('VAR_DIR')
            : (defined('BP') ? rtrim((string)\constant('BP'), '/\\') . '/var/' : '');
        foreach ([
            $varRoot . 'server/config/' . $instanceName . '.json',
            $varRoot . 'server/instances/' . $instanceName . '.json',
        ] as $path) {
            if ($varRoot === '' || !is_file($path)) {
                continue;
            }
            $data = json_decode((string)@file_get_contents($path), true);
            $host = is_array($data) ? strtolower(trim((string)($data['public_host'] ?? ''))) : '';
            if ($host !== '' && $valid($host)) {
                return $host;
            }
        }
        try {
            $env = Env::getInstance();
            foreach (['wls.edge.nginx.public_host', 'wls.public_host'] as $key) {
                $host = strtolower(trim((string)$env->getConfig($key, '')));
                if ($host !== '' && $valid($host)) {
                    return $host;
                }
            }
        } catch (\Throwable) {
        }
        $requestHost = strtolower((string)strtok((string)($this->request->getServer('HTTP_HOST') ?? ''), ':'));

        return $valid($requestHost) ? $requestHost : '';
    }
}
