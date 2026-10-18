<?php

declare(strict_types=1);

namespace Weline\Search\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Search\Service\SearchHubService;
use Weline\Search\Service\SearchProviderRegistry;
use Weline\Search\Service\StorefrontSearchFpcQueryKeys;

/**
 * Storefront search — Extra FPC with explicit query_keys.
 * Empty allowlist hit → path-only shell; q/type/page/… present → SearchHub SSR
 * so Shared body matches the cache identity (JS may skip BinQuery when SSR ready).
 *
 * query_keys CSV must match StorefrontSearchFpcQueryKeys::csv().
 *
 * @Cdn cache=false description="万能搜索结果页不边缘缓存"
 * @Attack rate_limit=60/1m challenge=managed description="搜索页同IP限流"
 * @Extra type=fpc ttl=3600 namespaces=website/default/search public_path_patterns=/search,/search/** query_keys=blog_category_id,category_id,page,page_size,q,type
 */
final class Index extends FrontendController
{
    public function __construct(
        private readonly SearchProviderRegistry $registry,
        private readonly SearchHubService $hub,
    ) {
    }

    public function index(): string
    {
        $listingQuery = StorefrontSearchFpcQueryKeys::fromRequest($this->request);
        if ($listingQuery !== []
            && \class_exists(\Weline\Server\Security\SearchFilterOriginGate::class)
        ) {
            \Weline\Server\Security\SearchFilterOriginGate::assertHtmlExtraVariantOrTerminate($this->request);
        }
        $q = (string)($listingQuery['q'] ?? '');
        $type = (string)($listingQuery['type'] ?? 'all');
        if ($type === '') {
            $type = 'all';
        }
        $page = \max(1, (int)($listingQuery['page'] ?? 1));
        $pageSize = \max(1, \min(48, (int)($listingQuery['page_size'] ?? 24)));
        $categoryId = \max(0, (int)($listingQuery['category_id'] ?? 0));
        $blogCategoryId = \max(0, (int)($listingQuery['blog_category_id'] ?? 0));

        $title = $q !== ''
            ? (string)__('搜索：%{1}', [$q])
            : (string)__('搜索');
        $description = (string)__('搜索本站商品、灵感文章与帮助指南。');

        $this->layoutType = 'search';
        $this->request->setGet('page_type', 'search');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('seo', [
            'page_type' => 'search',
            'title' => $title,
            'description' => $description,
            'robots' => 'noindex,follow',
            'canonical_url' => $this->getUrl('search'),
        ]);
        $searchTypes = $this->registry->listTypes(area: 'frontend');
        $this->assign('search_query', $q);
        $this->assign('search_type', $type);
        $this->assign('search_category_id', $categoryId);
        $this->assign('search_page', $page);
        $this->assign('search_page_size', $pageSize);
        $this->assign('search_types', $searchTypes);
        $this->assign('search_type_labels', $this->registry->typeLabelMap($searchTypes));
        $this->assign('search_hit_templates', $this->registry->hitTemplateMap());
        $this->assign(
            'search_category_breadcrumb',
            $this->registry->resolveScopeBreadcrumb($type, $categoryId, $searchTypes),
        );

        $ssrPayload = null;
        if ($q !== '') {
            $hubParams = [
                'q' => $q,
                'type' => $type,
                'page' => $page,
                'page_size' => $pageSize,
                'area' => 'frontend',
            ];
            if ($categoryId > 0) {
                $hubParams['category_id'] = $categoryId;
            }
            if ($blogCategoryId > 0) {
                $hubParams['blog_category_id'] = $blogCategoryId;
            }
            $result = $this->hub->search($hubParams, autocomplete: false);
            $ssrPayload = $result->toArray();
            $this->assign('search_result', ['success' => $result->ok] + $ssrPayload);
            $this->assign('search_hits', $ssrPayload['hits'] ?? []);
            $this->assign('search_sections', $ssrPayload['sections'] ?? []);
            $this->assign('search_hit_count', (int)($ssrPayload['hit_count'] ?? 0));
            $this->assign('search_error', (string)($ssrPayload['message'] ?? ''));
            $this->assign('search_error_code', (string)($ssrPayload['error_code'] ?? ''));
            // SSR filled Shared body; keep async hook for soft refresh / type switches.
            $this->assign('search_async', true);
            $this->assign('search_ssr_ready', true);
            $this->assign('search_ssr_payload', $ssrPayload);
        } else {
            // Path-only empty shell — never bake empty-q hits into Shared.
            $this->assign('search_result', [
                'success' => true,
                'type' => 'all',
                'hits' => [],
                'sections' => [],
                'hit_count' => 0,
            ]);
            $this->assign('search_hits', []);
            $this->assign('search_sections', []);
            $this->assign('search_hit_count', 0);
            $this->assign('search_error', '');
            $this->assign('search_error_code', '');
            $this->assign('search_async', true);
            $this->assign('search_ssr_ready', false);
            $this->assign('search_ssr_payload', null);
        }

        return (string)$this->fetch('Weline_Search::templates/frontend/index.phtml');
    }
}
