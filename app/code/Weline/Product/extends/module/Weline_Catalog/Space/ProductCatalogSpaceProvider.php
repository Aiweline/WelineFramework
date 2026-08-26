<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Catalog\Space;

use Weline\Catalog\Api\CatalogSpaceProviderInterface;
use Weline\Product\Model\CategoryAttributeEntity;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductCategoryAttributeMetadataCatalog;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Product\Service\StorefrontCategoryTreeIndex;

/**
 * Product category space — delegates structure CRUD to ProductCategoryAdminService (S1).
 */
final class ProductCatalogSpaceProvider implements CatalogSpaceProviderInterface
{
    public function __construct(
        private readonly ProductCategoryAdminService $categoryAdmin,
        private readonly ProductCategoryAttributeService $categoryAttributes,
        private readonly ProductCategoryAttributeMetadataCatalog $attributeMetadata,
        private readonly StorefrontCategoryTreeIndex $categoryTreeIndex,
        private readonly StorefrontCatalogCacheCoordinator $catalogCache,
    ) {
    }

    public function code(): string
    {
        return 'product';
    }

    public function label(): string
    {
        return (string)__('产品分类');
    }

    public function sortOrder(): int
    {
        return 10;
    }

    public function icon(): string
    {
        return 'bx-category';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function normalizeScope(array $params): array
    {
        return [
            'space' => $this->code(),
            'scope_level' => strtolower(trim((string)($params['scope_level'] ?? 'website'))),
            'website_id' => max(0, (int)($params['website_id'] ?? 0)),
            'store_id' => max(0, (int)($params['store_id'] ?? 0)),
            'channel_id' => max(0, (int)($params['channel_id'] ?? 0)),
            'locale' => trim((string)($params['locale'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function tree(array $scope): array
    {
        return $this->categoryAdmin->tree(
            max(0, (int)($scope['website_id'] ?? 0)),
            (string)($scope['locale'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function view(array $scope, int $nodeId): ?array
    {
        return $this->categoryAdmin->view(
            max(0, (int)($scope['website_id'] ?? 0)),
            $nodeId,
            (string)($scope['locale'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $scope, array $payload): array
    {
        $websiteId = max(0, (int)($scope['website_id'] ?? 0));
        $result = $this->categoryAdmin->save(
            $websiteId,
            max(0, (int)($payload['category_id'] ?? $payload['id'] ?? 0)),
            max(0, (int)($payload['parent_id'] ?? $payload['pid'] ?? 0)),
            trim((string)($payload['name'] ?? '')),
            !empty($payload['is_active']) || (string)($payload['status'] ?? 'active') !== 'inactive'
                ? 'active'
                : 'inactive',
            trim((string)($payload['code'] ?? '')),
            (string)($scope['locale'] ?? ''),
        );

        return ['success' => true] + $result;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function delete(array $scope, int $nodeId): void
    {
        $this->categoryAdmin->delete(max(0, (int)($scope['website_id'] ?? 0)), $nodeId);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function reorder(array $scope, array $payload): array
    {
        $result = $this->categoryAdmin->reorder(
            max(0, (int)($scope['website_id'] ?? 0)),
            max(0, (int)($payload['category_id'] ?? $payload['id'] ?? 0)),
            max(0, (int)($payload['parent_id'] ?? $payload['pid'] ?? 0)),
            max(1, (int)($payload['level'] ?? 1)),
            max(1, (int)($payload['position'] ?? 1)),
        );

        return ['success' => true] + $result;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function readDisplaySelection(array $scope): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveDisplaySelection(array $scope, array $payload): array
    {
        return [
            'success' => true,
            'saved' => 0,
            'scope_level' => (string)($scope['scope_level'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function searchNodes(array $scope, string $query): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        return $this->filterTreeNodes($this->tree($scope), $query);
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function resolveNodeUrl(array $scope, int $nodeId): string
    {
        $view = $this->view($scope, $nodeId);
        $path = trim((string)($view['path'] ?? ''), '/');

        return $path !== '' ? '/category/' . $path : '';
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function listNavCandidates(array $scope): array
    {
        return $this->tree($scope);
    }

    public function eavEntityCode(): string
    {
        return CategoryAttributeEntity::entity_code;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attributeEditorCatalog(): array
    {
        return $this->attributeMetadata->editorCatalog();
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function readAttributes(array $scope, int $nodeId): array
    {
        if ($nodeId <= 0) {
            return [];
        }

        return $this->categoryAttributes->listExplicitRows(
            max(0, (int)($scope['website_id'] ?? 0)),
            [$nodeId],
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function writeAttributes(array $scope, int $nodeId, array $rows): array
    {
        if ($nodeId <= 0) {
            throw new \InvalidArgumentException((string)__('分类 ID 不能为空'));
        }
        $websiteId = max(0, (int)($scope['website_id'] ?? 0));
        $locale = (string)($scope['locale'] ?? '');
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row['attribute_code'] ?? ''));
            if ($code === 'name') {
                $this->categoryAttributes->writeName(
                    $websiteId,
                    $nodeId,
                    trim((string)($row['value'] ?? '')),
                    $locale,
                );
            } elseif ($code === 'code') {
                $this->categoryAttributes->writeCode(
                    $websiteId,
                    $nodeId,
                    trim((string)($row['value'] ?? '')),
                    $locale,
                );
            }
        }

        return ['success' => true, 'category_id' => $nodeId];
    }

    public function externalTaxonomyRequired(): bool
    {
        return false;
    }

    public function validateExternalTaxonomyId(string $externalId): bool
    {
        return trim($externalId) !== '';
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function listExternalTaxonomyPicker(array $scope, string $query): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function invalidateAfterMutation(array $scope, string $reason, int $nodeId = 0): void
    {
        $websiteId = max(0, (int)($scope['website_id'] ?? 0));
        $this->categoryTreeIndex->invalidate($websiteId);
        $this->catalogCache->notifyCategoryChanged($websiteId, $reason, $nodeId);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function filterTreeNodes(array $nodes, string $query): array
    {
        $matches = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $name = mb_strtolower(trim((string)($node['name'] ?? '')));
            $code = mb_strtolower(trim((string)($node['code'] ?? '')));
            $hit = str_contains($name, $query) || str_contains($code, $query);
            $children = is_array($node['nodes'] ?? null) ? $this->filterTreeNodes($node['nodes'], $query) : [];
            if ($hit) {
                $copy = $node;
                $copy['nodes'] = $children;
                $matches[] = $copy;
            } elseif ($children !== []) {
                $matches = array_merge($matches, $children);
            }
        }

        return $matches;
    }
}
