<?php

declare(strict_types=1);

namespace Weline\Blog\Extends\Module\Weline_Catalog\Space;

use Weline\Blog\Model\BlogCategoryAttributeEntity;
use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogCategoryAttributeMetadataCatalog;
use Weline\Blog\Service\BlogCategoryAttributeService;
use Weline\Catalog\Api\CatalogSpaceProviderInterface;

/**
 * Blog category space — two-level tree delegated to BlogCategoryAdminService.
 */
final class BlogCatalogSpaceProvider implements CatalogSpaceProviderInterface
{
    public function __construct(
        private readonly BlogCategoryAdminService $categoryAdmin,
        private readonly BlogCategoryAttributeService $categoryAttributes,
        private readonly BlogCategoryAttributeMetadataCatalog $attributeMetadata,
    ) {
    }

    public function code(): string
    {
        return 'blog';
    }

    public function label(): string
    {
        return (string)__('博客分类');
    }

    public function sortOrder(): int
    {
        return 20;
    }

    public function icon(): string
    {
        return 'bx-book-content';
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
        $optional = static function (array $payload, string $key): ?string {
            return array_key_exists($key, $payload) ? trim((string)$payload[$key]) : null;
        };
        $result = $this->categoryAdmin->save(
            max(0, (int)($scope['website_id'] ?? 0)),
            max(0, (int)($payload['category_id'] ?? $payload['id'] ?? 0)),
            trim((string)($payload['name'] ?? '')),
            trim((string)($payload['code'] ?? $payload['slug'] ?? '')),
            (string)($scope['locale'] ?? ''),
            max(0, (int)($payload['position'] ?? $payload['sort_order'] ?? 0)),
            $optional($payload, 'image'),
            $optional($payload, 'banner'),
            $optional($payload, 'summary'),
            $optional($payload, 'description'),
            max(0, (int)($payload['parent_id'] ?? $payload['pid'] ?? 0)),
        );

        return ['success' => true] + $result;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $options
     */
    public function delete(array $scope, int $nodeId, array $options = []): void
    {
        unset($options);
        $this->categoryAdmin->delete(max(0, (int)($scope['website_id'] ?? 0)), $nodeId);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array{product_id:int,name:string,sku:string,exclusive:bool}>
     */
    public function listProductsForDelete(array $scope, int $nodeId): array
    {
        unset($scope, $nodeId);

        return [];
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
        unset($scope);

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveDisplaySelection(array $scope, array $payload): array
    {
        unset($scope, $payload);

        return ['success' => true];
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
        $slug = trim((string)($view['code'] ?? $view['slug'] ?? ''));

        return $slug !== '' ? $this->categoryAdmin->resolvePublicUrl($slug) : '';
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
        return BlogCategoryAttributeEntity::entity_code;
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
            } elseif ($code === 'image') {
                $this->categoryAttributes->writeImage(
                    $websiteId,
                    $nodeId,
                    trim((string)($row['value'] ?? '')),
                    $locale,
                );
            } elseif ($code === 'banner') {
                $this->categoryAttributes->writeBanner(
                    $websiteId,
                    $nodeId,
                    trim((string)($row['value'] ?? '')),
                    $locale,
                );
            } elseif ($code === 'summary') {
                $this->categoryAttributes->writeSummary(
                    $websiteId,
                    $nodeId,
                    trim((string)($row['value'] ?? '')),
                    $locale,
                );
            } elseif ($code === 'description') {
                $this->categoryAttributes->writeDescription(
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
        unset($externalId);

        return false;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function listExternalTaxonomyPicker(array $scope, string $query): array
    {
        unset($scope, $query);

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function invalidateAfterMutation(array $scope, string $reason, int $nodeId = 0): void
    {
        unset($scope, $reason, $nodeId);
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
            $childMatches = $this->filterTreeNodes(
                is_array($node['nodes'] ?? null) ? $node['nodes'] : [],
                $query,
            );
            if (str_contains($name, $query) || str_contains($code, $query) || $childMatches !== []) {
                $copy = $node;
                if ($childMatches !== []) {
                    $copy['nodes'] = $childMatches;
                }
                $matches[] = $copy;
            }
        }

        return $matches;
    }
}
