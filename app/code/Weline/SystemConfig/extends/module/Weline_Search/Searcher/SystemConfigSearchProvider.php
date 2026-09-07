<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Extends\Module\Weline_Search\Searcher;

use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Service\AbstractSearchProvider;
use Weline\Search\Service\SearchExpression;
use Weline\SystemConfig\Service\SystemConfigNavSearchIndexService;

/**
 * Backend universal-search slot: SystemConfig field hits with config-center deeplinks.
 */
final class SystemConfigSearchProvider extends AbstractSearchProvider
{
    public function __construct(
        private readonly SystemConfigNavSearchIndexService $indexService,
    ) {
    }

    public function code(): string
    {
        return 'system_config';
    }

    public function label(): string
    {
        return (string)__('配置项');
    }

    public function sortOrder(): int
    {
        return 20;
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
        return SearchExpression::of($request)->match(['title', 'key', 'module']);
    }

    public function execute(SearchRequest $request, SearchExpression $expression): SearchResult
    {
        $needle = mb_strtolower(trim($request->q));
        if ($needle === '') {
            return new SearchResult(ok: true, type: $this->code(), hits: [], hitCount: 0);
        }

        $hits = [];
        foreach ($this->indexService->getItems() as $item) {
            $searchText = mb_strtolower((string)($item['search_text'] ?? ''));
            $label = (string)($item['label'] ?? '');
            $key = (string)($item['key'] ?? '');
            $url = (string)($item['url'] ?? '');
            if ($label === '' || $key === '' || $url === '') {
                continue;
            }
            if ($searchText === '' || !str_contains($searchText, $needle)) {
                continue;
            }
            $module = (string)($item['module'] ?? '');
            $area = (string)($item['area'] ?? '');
            $templateTitle = (string)($item['template_title'] ?? '');
            $templateCode = (string)($item['template_code'] ?? '');
            $areaLabel = match (strtolower($area)) {
                'backend' => (string)__('后台'),
                'frontend' => (string)__('前台'),
                default => $area !== '' ? $area : '',
            };
            $groupParts = array_values(array_filter([$module, $areaLabel, $templateTitle], static fn (string $part): bool => $part !== ''));
            $breadcrumb = implode(' › ', $groupParts);
            $hits[] = new SearchHit(
                indexer: $this->code(),
                entityType: 'system_config_field',
                entityId: $key,
                title: $label,
                url: $url,
                payload: [
                    'module' => $module,
                    'area' => $area,
                    'area_label' => $areaLabel,
                    'template_title' => $templateTitle,
                    'template_code' => $templateCode,
                    'key' => $key,
                    'group' => $module !== '' ? $module : (string)__('配置项'),
                    'breadcrumb' => $breadcrumb,
                    'subtitle' => $breadcrumb !== '' ? ($breadcrumb . ' · ' . $key) : $key,
                ],
                score: str_contains(mb_strtolower($label), $needle) ? 2.0 : 1.0,
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
            engine: 'system_config_nav_index',
        );
    }
}
