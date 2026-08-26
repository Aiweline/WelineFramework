<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Search\Dto\SearchRequest;

/**
 * Shared allowlist for GET /search and QueryBin search.search params.
 */
final class SearchParamGuard
{
    public const ERROR_PARAMS = 'search_params_invalid';
    public const ERROR_SCOPE = 'search_scope_invalid';

    public const PUBLIC_KEYS = ['q', 'type', 'page', 'page_size'];

    public function guardSearch(
        array $params,
        SearchProviderRegistry $registry,
        bool $autocomplete = false,
    ): SearchRequest {
        $typeRaw = \trim((string)($params['type'] ?? 'all'));
        if ($typeRaw === '') {
            $typeRaw = 'all';
        }

        $allowed = self::PUBLIC_KEYS;
        if ($typeRaw !== 'all') {
            $provider = $registry->get($typeRaw);
            if ($provider === null) {
                throw new SearchParamException(
                    self::ERROR_PARAMS,
                    (string)__('未知搜索类型：%{1}', [$typeRaw]),
                    ['type' => $typeRaw],
                );
            }
            $allowed = \array_values(\array_unique(\array_merge(
                self::PUBLIC_KEYS,
                \array_keys($provider->allowedClientParams()),
            )));
        }

        $unknown = \array_diff(\array_keys($params), $allowed);
        if ($unknown !== []) {
            throw new SearchParamException(
                self::ERROR_PARAMS,
                (string)__('搜索不接受未知参数'),
                ['unknown_params' => \array_values($unknown)],
            );
        }

        $q = \trim((string)($params['q'] ?? ''));
        if (\mb_strlen($q) > 255) {
            throw new SearchParamException(
                self::ERROR_PARAMS,
                (string)__('搜索关键词过长'),
                ['max_length' => 255],
            );
        }

        $page = (int)($params['page'] ?? 1);
        if ($page < 1 || $page > 100) {
            throw new SearchParamException(
                self::ERROR_PARAMS,
                (string)__('搜索页码超出范围'),
                ['page' => $page],
            );
        }

        $pageSize = (int)($params['page_size'] ?? 24);
        if ($pageSize < 1 || $pageSize > 48) {
            throw new SearchParamException(
                self::ERROR_PARAMS,
                (string)__('搜索每页数量超出范围'),
                ['page_size' => $pageSize],
            );
        }
        if ($autocomplete) {
            $pageSize = \min($pageSize, 8);
        }

        $extras = [];
        if ($typeRaw !== 'all') {
            $provider = $registry->get($typeRaw);
            $declared = $provider?->allowedClientParams() ?? [];
            foreach ($declared as $name => $rule) {
                if (!\array_key_exists($name, $params)) {
                    if (!empty($rule['required'])) {
                        throw new SearchParamException(
                            self::ERROR_PARAMS,
                            (string)__('缺少搜索参数：%{1}', [$name]),
                            ['missing' => $name],
                        );
                    }
                    continue;
                }
                $extras[$name] = $this->castExtra($name, $params[$name], $rule);
            }
        }

        $scope = RequestContext::scopeMetadata();
        if (!\is_array($scope)
            || ($scope['scope_kind'] ?? '') !== 'channel'
            || (int)($scope['store_id'] ?? 0) < 1
            || (int)($scope['channel_id'] ?? 0) < 1
            || \trim((string)($scope['locale'] ?? '')) === ''
            || \trim((string)($scope['currency'] ?? '')) === ''
        ) {
            throw new SearchParamException(
                self::ERROR_SCOPE,
                (string)__('当前 storefront 请求没有冻结完整商城 Scope'),
            );
        }

        return new SearchRequest(
            q: $q,
            type: $typeRaw === 'all' ? 'all' : $typeRaw,
            page: $page,
            pageSize: $pageSize,
            websiteId: (int)$scope['website_id'],
            storeId: (int)$scope['store_id'],
            channelId: (int)$scope['channel_id'],
            locale: (string)$scope['locale'],
            currency: (string)$scope['currency'],
            extras: $extras,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public function guardHotWords(array $params): int
    {
        $unknown = \array_diff(\array_keys($params), ['limit']);
        if ($unknown !== []) {
            throw new SearchParamException(
                self::ERROR_PARAMS,
                (string)__('热搜接口不接受未知参数'),
                ['unknown_params' => \array_values($unknown)],
            );
        }
        $limit = (int)($params['limit'] ?? 8);
        if ($limit < 1 || $limit > 20) {
            throw new SearchParamException(
                self::ERROR_PARAMS,
                (string)__('热搜数量超出范围'),
                ['limit' => $limit],
            );
        }

        return $limit;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function guardTypes(array $params): void
    {
        if ($params !== []) {
            throw new SearchParamException(
                self::ERROR_PARAMS,
                (string)__('搜索类型列表不接受参数'),
                ['unknown_params' => \array_keys($params)],
            );
        }
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function castExtra(string $name, mixed $raw, array $rule): mixed
    {
        $type = (string)($rule['type'] ?? 'string');
        if ($type === 'int') {
            if (!\is_numeric($raw)) {
                throw new SearchParamException(
                    self::ERROR_PARAMS,
                    (string)__('搜索参数类型无效：%{1}', [$name]),
                    ['param' => $name],
                );
            }
            $value = (int)$raw;
            if (isset($rule['min']) && $value < (int)$rule['min']) {
                throw new SearchParamException(
                    self::ERROR_PARAMS,
                    (string)__('搜索参数过小：%{1}', [$name]),
                    ['param' => $name],
                );
            }
            if (isset($rule['max']) && $value > (int)$rule['max']) {
                throw new SearchParamException(
                    self::ERROR_PARAMS,
                    (string)__('搜索参数过大：%{1}', [$name]),
                    ['param' => $name],
                );
            }

            return $value;
        }

        return \trim((string)$raw);
    }
}
