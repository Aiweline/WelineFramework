<?php

declare(strict_types=1);

namespace Weline\Search\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Search\Service\SearchHubService;
use Weline\Search\Service\SearchParamException;
use Weline\Search\Service\SearchParamGuard;
use Weline\Search\Service\SearchProviderRegistry;

/**
 * @Cdn cache=false description="万能搜索结果页不边缘缓存"
 * @Attack rate_limit=60/1m challenge=managed description="搜索页同IP限流"
 */
final class Index extends FrontendController
{
    public function __construct(
        private readonly SearchHubService $hub,
        private readonly SearchParamGuard $paramGuard,
        private readonly SearchProviderRegistry $registry,
    ) {
    }

    public function index(): string
    {
        $params = [
            'q' => (string)$this->request->getParam('q', ''),
            'type' => (string)$this->request->getParam('type', 'all'),
            'page' => (string)$this->request->getParam('page', '1'),
            'page_size' => (string)$this->request->getParam('page_size', '24'),
        ];
        foreach (['category_id', 'blog_category_id'] as $extra) {
            $value = $this->request->getParam($extra);
            if ($value !== null && $value !== '') {
                $params[$extra] = (string)$value;
            }
        }

        $q = trim($params['q']);
        $type = trim($params['type']) ?: 'all';
        $categoryId = (int)($params['category_id'] ?? 0);
        $title = $q !== ''
            ? (string)__('搜索：%{1}', [$q])
            : (string)__('搜索');

        $this->layoutType = 'search';
        $this->request->setGet('page_type', 'search');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $description = $q !== ''
            ? (string)__('查看“%{1}”在本站汉服、文章与帮助中的相关结果。', [$q])
            : (string)__('在汉服商城中搜索商品、搭配灵感与帮助指南。');
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
        $this->assign('search_types', $searchTypes);
        $this->assign('search_type_labels', $this->registry->typeLabelMap($searchTypes));
        $this->assign('search_hit_templates', $this->registry->hitTemplateMap());
        $this->assign(
            'search_category_breadcrumb',
            $this->registry->resolveScopeBreadcrumb($type, $categoryId, $searchTypes),
        );

        try {
            if ($q === '') {
                // Empty keyword UI does not render result grids; skip provider fan-out
                // (product direct snapshot rebuild is multi-second without a query).
                $this->assign('search_result', [
                    'success' => true,
                    'type' => $type,
                    'hits' => [],
                    'sections' => [],
                    'hit_count' => 0,
                ]);
                $this->assign('search_hits', []);
                $this->assign('search_sections', []);
                $this->assign('search_hit_count', 0);
                $this->assign('search_error', '');
                $this->assign('search_error_code', '');
            } else {
                $result = $this->hub->search($params, autocomplete: false);
                $payload = $result->toArray();
                $this->assign('search_result', $payload);
                $this->assign('search_hits', $payload['hits'] ?? []);
                $this->assign('search_sections', $payload['sections'] ?? []);
                $this->assign('search_hit_count', (int)($payload['hit_count'] ?? 0));
                $this->assign('search_error', $result->ok ? '' : (string)($payload['message'] ?? ''));
                $this->assign('search_error_code', $result->ok ? '' : (string)($payload['error_code'] ?? ''));
            }
        } catch (SearchParamException $exception) {
            $this->assign('search_result', [
                'success' => false,
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]);
            $this->assign('search_hits', []);
            $this->assign('search_sections', []);
            $this->assign('search_hit_count', 0);
            $this->assign('search_error', $exception->getMessage());
            $this->assign('search_error_code', $exception->errorCode);
        }

        return (string)$this->fetch('Weline_Search::templates/frontend/index.phtml');
    }
}
