<?php

declare(strict_types=1);

namespace Weline\Admin\Extends\Module\Weline_Search\Searcher;

use Weline\Admin\Service\MenuRenderService;
use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Service\AbstractSearchProvider;
use Weline\Search\Service\SearchExpression;

/**
 * 后台顶栏万能搜索：菜单项（含全启用 locale 交叉可搜词）。
 */
final class BackendMenuSearchProvider extends AbstractSearchProvider
{
    public function __construct(
        private readonly MenuRenderService $menuRenderService,
    ) {
    }

    public function code(): string
    {
        return 'backend_menu';
    }

    public function label(): string
    {
        return (string)__('菜单');
    }

    public function sortOrder(): int
    {
        return 10;
    }

    /**
     * @return list<string>
     */
    public function areas(): array
    {
        return ['backend'];
    }

    public function allowedClientParams(): array
    {
        return [];
    }

    public function expression(SearchRequest $request): SearchExpression
    {
        return SearchExpression::of($request)->match(['title', 'source_id', 'search_text']);
    }

    public function execute(SearchRequest $request, SearchExpression $expression): SearchResult
    {
        $needle = function_exists('mb_strtolower')
            ? mb_strtolower(trim($request->q))
            : strtolower(trim($request->q));
        if ($needle === '') {
            return new SearchResult(ok: true, type: $this->code(), hits: [], hitCount: 0);
        }

        $hits = [];
        foreach ($this->menuRenderService->collectNavigableMenuSearchItems() as $item) {
            $searchText = function_exists('mb_strtolower')
                ? mb_strtolower((string)($item['search_text'] ?? ''))
                : strtolower((string)($item['search_text'] ?? ''));
            $title = (string)($item['title'] ?? '');
            $url = (string)($item['url'] ?? '');
            $sourceId = (string)($item['source_id'] ?? '');
            if ($title === '' || $url === '' || $sourceId === '') {
                continue;
            }
            if ($searchText === '' || !str_contains($searchText, $needle)) {
                continue;
            }

            $titleLower = function_exists('mb_strtolower') ? mb_strtolower($title) : strtolower($title);
            $hits[] = new SearchHit(
                indexer: $this->code(),
                entityType: 'backend_menu',
                entityId: $sourceId,
                title: $title,
                url: $url,
                payload: [
                    'source_id' => $sourceId,
                    'group' => (string)__('菜单'),
                    'breadcrumb' => (string)__('后台菜单'),
                    'subtitle' => $sourceId,
                ],
                score: str_contains($titleLower, $needle) ? 2.0 : 1.0,
            );
            if (count($hits) >= $request->pageSize) {
                break;
            }
        }

        return new SearchResult(
            ok: true,
            type: $this->code(),
            hits: $hits,
            hitCount: count($hits),
            engine: 'backend_menu_live',
        );
    }
}
