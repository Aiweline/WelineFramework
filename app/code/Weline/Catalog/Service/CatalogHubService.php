<?php

declare(strict_types=1);

namespace Weline\Catalog\Service;

use Weline\Catalog\Api\CatalogSpaceProviderInterface;
use Weline\Catalog\Exception\CatalogScopeForbiddenException;

/**
 * Thin catalog hub: resolve space provider and forward operations without business SQL.
 */
final class CatalogHubService
{
    public function __construct(
        private readonly CatalogSpaceRegistry $registry,
        private readonly CatalogScopeGuard $scopeGuard,
    ) {
    }

    /**
     * @return list<array{code:string,label:string,icon:string,sort_order:int}>
     */
    public function listSpaces(): array
    {
        return $this->registry->listSpaces();
    }

    public function provider(string $space): CatalogSpaceProviderInterface
    {
        $space = trim($space);
        $provider = $this->registry->get($space);
        if ($provider === null) {
            throw new \InvalidArgumentException((string)__(
                '未注册的分类空间：%{1}',
                [$space],
            ));
        }

        return $provider;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function execute(string $operation, array $params): mixed
    {
        $operation = trim($operation);
        $scopeContext = $this->scopeGuard->resolve($params);
        if ($scopeContext->space === '') {
            throw new \InvalidArgumentException((string)__('分类空间 space 不能为空'));
        }

        try {
            $this->scopeGuard->assertOperationAllowed($operation, $scopeContext);
            $provider = $this->provider($scopeContext->space);
            $scope = $provider->normalizeScope($params);

            return match ($operation) {
                'tree' => $provider->tree($scope),
                'view' => $provider->view($scope, max(0, (int)($params['category_id'] ?? $params['node_id'] ?? 0))),
                'save' => $provider->save($scope, $params),
                'delete' => $provider->delete($scope, max(0, (int)($params['category_id'] ?? $params['node_id'] ?? 0))),
                'reorder' => $provider->reorder($scope, $params),
                'search' => $provider->searchNodes($scope, trim((string)($params['q'] ?? $params['query'] ?? ''))),
                'readDisplaySelection' => $provider->readDisplaySelection($scope),
                'saveDisplaySelection' => $provider->saveDisplaySelection($scope, $params),
                'readAttributes' => $provider->readAttributes(
                    $scope,
                    max(0, (int)($params['category_id'] ?? $params['node_id'] ?? 0)),
                ),
                'writeAttributes' => $provider->writeAttributes(
                    $scope,
                    max(0, (int)($params['category_id'] ?? $params['node_id'] ?? 0)),
                    is_array($params['rows'] ?? null) ? $params['rows'] : [],
                ),
                'attributeCatalog' => $provider->attributeEditorCatalog(),
                default => throw new \InvalidArgumentException((string)__(
                    '分类接口不支持该操作：%{1}',
                    [$operation],
                )),
            };
        } catch (CatalogScopeForbiddenException $exception) {
            return [
                'success' => false,
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'context' => $exception->context,
            ];
        }
    }
}
