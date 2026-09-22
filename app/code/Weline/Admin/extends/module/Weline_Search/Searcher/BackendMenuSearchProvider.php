<?php

declare(strict_types=1);

namespace Weline\Admin\Extends\Module\Weline_Search\Searcher;

use Weline\Admin\Service\BackendMenuSearchIndexDocumentBuilder;
use Weline\Admin\Service\MenuRenderService;
use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Service\AbstractSearchProvider;
use Weline\Search\Service\SearchExpression;
use Weline\Search\Service\SearchProviderIndexService;

/**
 * 后台顶栏 / 侧栏菜单搜索：DB 索引交叉语种词，execute 时按当前角色 ACL 过滤。
 */
final class BackendMenuSearchProvider extends AbstractSearchProvider
{
    public function __construct(
        private readonly MenuRenderService $menuRenderService,
        private readonly BackendMenuSearchIndexDocumentBuilder $indexBuilder,
        private readonly SearchProviderIndexService $indexService,
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
        return SearchExpression::of($request)->match(['title', 'keywords', 'payload']);
    }

    public function documentsForIndex(SearchRequest $request): array
    {
        return $this->indexBuilder->buildForRequest($request);
    }

    public function execute(SearchRequest $request, SearchExpression $expression): SearchResult
    {
        $needle = function_exists('mb_strtolower')
            ? mb_strtolower(trim($request->q))
            : strtolower(trim($request->q));
        if ($needle === '') {
            return new SearchResult(ok: true, type: $this->code(), hits: [], hitCount: 0);
        }

        // Cross-locale keywords live on locale='' documents; ignore request locale for lookup.
        $indexRequest = new SearchRequest(
            q: $request->q,
            type: $request->type,
            page: $request->page,
            pageSize: max($request->pageSize, 48),
            websiteId: $request->websiteId,
            storeId: $request->storeId,
            channelId: $request->channelId,
            locale: '',
            currency: $request->currency,
            extras: $request->extras,
        );

        $indexed = $this->indexService->search($indexRequest, $expression, $this);
        $allowed = $this->allowedSourceIds();
        $hits = [];
        foreach ($indexed->hits as $hit) {
            if ($hit->indexer !== '' && $hit->indexer !== $this->code()) {
                continue;
            }
            $sourceId = trim($hit->entityId !== '' ? $hit->entityId : (string)($hit->payload['source_id'] ?? ''));
            if ($sourceId === '' || !str_contains($sourceId, '::') || ($allowed !== null && !isset($allowed[$sourceId]))) {
                continue;
            }

            $sourceName = (string)($hit->payload['source_name'] ?? $hit->title);
            $route = trim((string)($hit->payload['route'] ?? ''));
            $title = $this->menuRenderService->resolveDisplayTitle($sourceName, $sourceId);
            $url = $route !== ''
                ? $this->menuRenderService->formatMenuUrl([
                    'route' => $route,
                    'is_backend' => true,
                ])
                : (string)$hit->url;
            if ($title === '' || $url === '') {
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
                    'route' => $route,
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
            engine: 'backend_menu_index',
        );
    }

    /**
     * null = no ACL context (should not expose); empty array = no menus; map = allowed.
     *
     * @return array<string, true>|null
     */
    private function allowedSourceIds(): ?array
    {
        $user = $this->menuRenderService->getCurrentUser();
        if ($user === null || !$user->getId() || !$user->getRoleId()) {
            return [];
        }

        $allowed = [];
        $this->collectSourceIds($this->menuRenderService->getMenuTree(), $allowed);

        return $allowed;
    }

    /**
     * @param array<int, array<string, mixed>> $menus
     * @param array<string, true> $allowed
     */
    private function collectSourceIds(array $menus, array &$allowed): void
    {
        foreach ($menus as $menu) {
            if (($menu['type'] ?? '') !== 'menus') {
                continue;
            }
            $sourceId = trim((string)($menu['source_id'] ?? ''));
            if ($sourceId !== '') {
                $allowed[$sourceId] = true;
            }
            $nodes = is_array($menu['nodes'] ?? null) ? $menu['nodes'] : [];
            if ($nodes !== []) {
                $this->collectSourceIds($nodes, $allowed);
            }
        }
    }
}
