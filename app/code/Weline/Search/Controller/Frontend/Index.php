<?php

declare(strict_types=1);

namespace Weline\Search\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Search\Service\SearchProviderRegistry;

/**
 * Storefront search shell — path-only Extra FPC guest HTML.
 * Query (q/type/page/…) is hydrated client-side from the URL; results via BinQuery.
 *
 * @Cdn cache=false description="万能搜索结果页不边缘缓存"
 * @Attack rate_limit=60/1m challenge=managed description="搜索页同IP限流"
 * @Extra type=fpc ttl=3600 namespaces=website/default/search public_path_patterns=/search,/search/**
 */
final class Index extends FrontendController
{
    public function __construct(
        private readonly SearchProviderRegistry $registry,
    ) {
    }

    public function index(): string
    {
        // Path-only FPC: never bake request q/type into the shared HTML body.
        $title = (string)__('搜索');
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
        $this->assign('search_query', '');
        $this->assign('search_type', 'all');
        $this->assign('search_category_id', 0);
        $this->assign('search_page', 1);
        $this->assign('search_page_size', 24);
        $this->assign('search_types', $searchTypes);
        $this->assign('search_type_labels', $this->registry->typeLabelMap($searchTypes));
        $this->assign('search_hit_templates', $this->registry->hitTemplateMap());
        $this->assign(
            'search_category_breadcrumb',
            $this->registry->resolveScopeBreadcrumb('all', 0, $searchTypes),
        );

        // Shared FPC shell only: never SSR SearchHub hits (async BinQuery fills the slot).
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
        // Always async-capable: JS reads location.search and loads when q present.
        $this->assign('search_async', true);

        return (string)$this->fetch('Weline_Search::templates/frontend/index.phtml');
    }
}
