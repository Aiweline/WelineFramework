<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;

/**
 * Universal search hub: param guard, provider fan-out, timing, analytics.
 */
final class SearchHubService
{
    private const ALL_SECTION_SIZE = 8;

    public function __construct(
        private readonly SearchParamGuard $paramGuard,
        private readonly SearchProviderRegistry $registry,
        private readonly SearchEngineResolver $engineResolver,
        private readonly SearchAnalyticsService $analytics,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function search(array $params, bool $autocomplete = false): SearchResult
    {
        $started = hrtime(true);
        try {
            $request = $this->paramGuard->guardSearch($params, $this->registry, $autocomplete);
        } catch (SearchParamException $exception) {
            return SearchResult::fail(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->context + ['type' => (string)($params['type'] ?? 'all')],
            );
        }

        try {
            $engineCode = $this->engineResolver->resolve()->code();
            if (trim($request->q) === '') {
                $elapsed = (hrtime(true) - $started) / 1e6;
                $empty = new SearchResult(
                    ok: true,
                    type: $request->isAllTypes() ? 'all' : $request->type,
                    hits: [],
                    hitCount: 0,
                    sections: [],
                    meta: ['empty_query' => true],
                    elapsedMs: round($elapsed, 2),
                    engine: $engineCode,
                );
                $this->analytics->recordQuery($request, $empty);

                return $empty;
            }

            $result = $request->isAllTypes()
                ? $this->searchAll($request, $autocomplete)
                : $this->searchSingle($request);
            $elapsed = (hrtime(true) - $started) / 1e6;
            $result = new SearchResult(
                ok: $result->ok,
                type: $result->type,
                hits: $result->hits,
                hitCount: $result->hitCount,
                sections: $result->sections,
                errorCode: $result->errorCode,
                message: $result->message,
                meta: $result->meta,
                elapsedMs: round($elapsed, 2),
                engine: $engineCode,
            );
            $this->analytics->recordQuery($request, $result);

            return $result;
        } catch (\Throwable $exception) {
            $elapsed = (hrtime(true) - $started) / 1e6;
            $fail = SearchResult::fail(
                'search_failed',
                (string)__('搜索暂时不可用'),
                ['type' => $request->type, 'reason' => $exception->getMessage()],
            );
            $fail = new SearchResult(
                ok: false,
                type: $request->type,
                hits: [],
                hitCount: 0,
                errorCode: $fail->errorCode,
                message: $fail->message,
                meta: $fail->meta,
                elapsedMs: round($elapsed, 2),
                engine: $this->engineResolver->configuredCode(),
            );
            $this->analytics->recordQuery($request, $fail);

            return $fail;
        }
    }

    private function searchSingle(SearchRequest $request): SearchResult
    {
        $area = $this->requestArea($request);
        $provider = $this->registry->get($request->type, $area);
        if ($provider === null) {
            return SearchResult::fail(
                SearchParamGuard::ERROR_PARAMS,
                (string)__('未知搜索类型：%{1}', [$request->type]),
                ['type' => $request->type, 'area' => $area],
            );
        }

        $expression = $provider->expression($request);
        $result = $provider->execute($request, $expression);
        if (!$result->ok) {
            return $result;
        }

        return new SearchResult(
            ok: true,
            type: $provider->code(),
            hits: $result->hits,
            hitCount: $result->hitCount > 0 ? $result->hitCount : count($result->hits),
            meta: $result->meta,
            elapsedMs: $result->elapsedMs,
            engine: $result->engine !== '' ? $result->engine : $this->engineResolver->configuredCode(),
        );
    }

    private function searchAll(SearchRequest $request, bool $autocomplete): SearchResult
    {
        $sectionSize = $autocomplete
            ? min($request->pageSize, self::ALL_SECTION_SIZE)
            : min($request->pageSize, self::ALL_SECTION_SIZE);
        $area = $this->requestArea($request);

        $sections = [];
        $totalHits = 0;
        foreach ($this->registry->all(area: $area) as $code => $provider) {
            $sectionRequest = new SearchRequest(
                q: $request->q,
                type: $code,
                page: 1,
                pageSize: $sectionSize,
                websiteId: $request->websiteId,
                storeId: $request->storeId,
                channelId: $request->channelId,
                locale: $request->locale,
                currency: $request->currency,
                extras: ['__area' => $area],
            );
            $expression = $provider->expression($sectionRequest);
            $providerResult = $provider->execute($sectionRequest, $expression);
            if ($providerResult->hits === []) {
                // Commerce primary: keep an empty product section so the UI can show「商品 0」
                // instead of silently omitting products while blog/FAQ still inflate hitCount.
                if ($code === 'product' && !$autocomplete) {
                    $sections[$code] = [];
                }
                continue;
            }
            $sections[$code] = $providerResult->hits;
            $totalHits += count($providerResult->hits);
        }

        return new SearchResult(
            ok: true,
            type: 'all',
            hits: [],
            hitCount: $totalHits,
            sections: $sections,
        );
    }

    /**
     * @return list<array{code:string,label:string,children?:list<array<string,mixed>>}>
     */
    public function listTypes(?string $area = null): array
    {
        return $this->registry->listTypes(area: $area);
    }

    private function requestArea(SearchRequest $request): string
    {
        $area = strtolower(trim((string)($request->extras['__area'] ?? 'frontend')));

        return in_array($area, ['frontend', 'backend'], true) ? $area : 'frontend';
    }
}