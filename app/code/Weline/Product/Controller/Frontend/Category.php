<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StorefrontPageContext;
use Weline\Product\Helper\StorefrontPageAssignBag;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontCategoryViewService;
use Weline\Product\Service\StorefrontListingFpcQueryKeys;
use Weline\Product\Service\StorefrontListingShell;
use Weline\Product\Service\StorefrontSeoListingFacts;

/**
 * 店面分类列表（/category/{path…}）。
 *
 * query_keys CSV must match StorefrontListingFpcQueryKeys::csv().
 *
 * @Extra type=fpc enabled=true ttl=31536000 namespaces=website/default/catalog public_path_patterns=/category/** query_keys=af_available_colors,af_available_sizes,af_brand,af_color,af_hanfu_chao_dai,af_hanfu_feng_ge,af_hanfu_gong_yi,af_hanfu_han_fu_zhi_shi,af_hanfu_pin_pai,af_hanfu_shang_shi_nian_fen_ji_jie,af_hanfu_shi_he_ji_jie,af_hanfu_shi_yong_chang_he,af_hanfu_shi_yong_ji_jie,af_hanfu_shi_yong_xing_bie,af_hanfu_tu_an,af_hanfu_xing_zhi,af_hanfu_zao_xing_feng_ge,af_hanfu_zhi_wu,af_hanfu_zhi_wu_gong_yi,af_hanfu_zhi_wu_ming_cheng,af_hanfu_zhu_zhi_wu_cheng_fen,af_material,af_size,af_style_type,page,price,sort
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
        $listingQuery = StorefrontListingFpcQueryKeys::fromRequest($this->request);
        $bindListingQuery = $listingQuery !== [];
        if ($bindListingQuery
            && \class_exists(\Weline\Server\Security\SearchFilterOriginGate::class)
        ) {
            \Weline\Server\Security\SearchFilterOriginGate::assertHtmlExtraVariantOrTerminate($this->request);
        }
        $offers = $productIds === []
            ? []
            : $this->catalog->publishedOffersForProductIds($productIds, 120, $bindListingQuery);
        // 取回上限：候选被截断时，过滤后 0 命中不能证明「该组合无货」，
        // 否则会把有货的 facet URL 永久 301 掉（架构评审 R4）。
        $offersTruncated = \count($productIds) > 120;
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

        if ($bindListingQuery) {
            // 廉价预判定（不渲染 grid / 不补媒体）：零命中的 facet 组合直接 301，
            // 不为垃圾 URL 付整页渲染成本（CPU 饱和时这正是 503 的来源）。
            $zeroHitProbe = $this->listingShell->projectFiltered(
                $offers,
                $listingQuery,
                $categoryUrl,
                'category',
                null,
                false,
                false,
                false,
            );
            if ($offers !== []
                && !$offersTruncated
                && (int)($zeroHitProbe['count'] ?? 0) === 0
            ) {
                $canonicalRedirectUrl = $this->canonicalRedirectUrl($categoryUrl);
                RequestContext::set(
                    'weline.frontend.redirect.canonical_host',
                    (string)(parse_url($canonicalRedirectUrl, PHP_URL_HOST) ?: '')
                );
                $this->request->getResponse()->redirect($canonicalRedirectUrl, 301);
            }
            $projection = $this->listingShell->projectFiltered(
                $offers,
                $listingQuery,
                $categoryUrl,
                'category',
            );
        } else {
            $projection = $this->listingShell->projectNeutral(
                $offers,
                $categoryUrl,
                'category',
            );
        }

        if ($bindListingQuery
            && $offers !== []
            && !$offersTruncated
            && (int)($projection['count'] ?? 0) === 0
        ) {
            // 无命中的 facet 组合不再铸造可索引/可缓存变体：永久重定向回规范列表页，
            // 否则爬虫可用 af_* 组合产生无限 URL，每次都是一次冷构建。
            // 落点主机取站点主域，并打请求级 flag 让 ResponseRedirectBefore 本次不改写 host。
            $canonicalRedirectUrl = $this->canonicalRedirectUrl($categoryUrl);
            RequestContext::set(
                'weline.frontend.redirect.canonical_host',
                (string)(parse_url($canonicalRedirectUrl, PHP_URL_HOST) ?: '')
            );
            $this->request->getResponse()->redirect($canonicalRedirectUrl, 301);
        }

        if ($bindListingQuery && (int)($projection['count'] ?? 0) === 0) {
            // 零命中的 facet 组合不进索引：爬虫拿到 noindex 后不再无限铸造变体
            // （无命中的 301 已在上面处理；这里覆盖「候选被截断、无法证明无货」的 200 渲染）。
            $this->request->getResponse()->setHeader('X-Robots-Tag', 'noindex, follow');
        }

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
        // Soft JS must not BinQuery-refetch when Shared FPC already painted projectFiltered.
        $this->assign('storefront_listing_ssr_filtered', $bindListingQuery);
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
            'storefront_listing_ssr_filtered' => $bindListingQuery,
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
