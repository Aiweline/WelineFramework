<?php

declare(strict_types=1);

namespace Weline\Catalog\Extends\Module\Weline_Framework\Query;

use Weline\Catalog\Controller\Backend\Category;
use Weline\Framework\Service\Query\AdminControllerBridge;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * Backend browser Resource for the universal catalog category tree editor.
 */
final class CatalogCategoryAdminQueryProvider implements QueryProviderInterface
{
    public const ACL_SOURCE = 'Weline_Catalog::commerce:universal-catalog:categories';

    public function getProviderName(): string
    {
        return 'catalog_category_admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'categoryAdminSave' => $this->categoryAdminSave($params),
            'categoryAdminDelete' => $this->categoryAdminDelete($params),
            'categoryAdminReorder' => $this->categoryAdminReorder($params),
            'categoryAdminView' => $this->categoryAdminView($params),
            default => throw new \InvalidArgumentException(
                (string)__('万能分类后台 Resource 不支持操作：%{1}', [$operation]),
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => (string)__('万能分类后台'),
            'description' => (string)__('维护分类树、保存、删除与拖拽排序。'),
            'module' => 'Weline_Catalog',
            'operations' => [
                $this->operation('categoryAdminView', (string)__('读取分类详情'), 'read', [
                    ['name' => 'space', 'type' => 'string', 'required' => true],
                    ['name' => 'scope_level', 'type' => 'string', 'required' => true],
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ['name' => 'id', 'type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->operation('categoryAdminSave', (string)__('保存分类'), 'write', [
                    ['name' => 'space', 'type' => 'string', 'required' => true],
                    ['name' => 'scope_level', 'type' => 'string', 'required' => true],
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ['name' => 'id', 'type' => 'int', 'required' => false, 'min' => 0],
                    ['name' => 'pid', 'type' => 'int', 'required' => false, 'min' => 0],
                    ['name' => 'name', 'type' => 'string', 'required' => true, 'max_length' => 120],
                    ['name' => 'code', 'type' => 'string', 'required' => false, 'max_length' => 120],
                    ['name' => 'is_active', 'type' => 'int', 'required' => false],
                ]),
                $this->operation('categoryAdminDelete', (string)__('删除分类'), 'write', [
                    ['name' => 'space', 'type' => 'string', 'required' => true],
                    ['name' => 'scope_level', 'type' => 'string', 'required' => true],
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ['name' => 'id', 'type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->operation('categoryAdminReorder', (string)__('拖拽排序分类'), 'write', [
                    ['name' => 'space', 'type' => 'string', 'required' => true],
                    ['name' => 'scope_level', 'type' => 'string', 'required' => true],
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ['name' => 'id', 'type' => 'int', 'required' => true, 'min' => 1],
                    ['name' => 'pid', 'type' => 'int', 'required' => false, 'min' => 0],
                    ['name' => 'level', 'type' => 'int', 'required' => false, 'min' => 1],
                    ['name' => 'position', 'type' => 'int', 'required' => false, 'min' => 1],
                ]),
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function categoryAdminView(array $params): mixed
    {
        return AdminControllerBridge::invoke(
            Category::class,
            ['getCategoryView'],
            [
                'space' => $this->space($params),
                'scope_level' => $this->scopeLevel($params),
                'website_id' => $this->websiteId($params),
                'id' => max(0, (int)($params['id'] ?? 0)),
            ],
            [],
            'GET',
        );
    }

    /** @param array<string,mixed> $params */
    private function categoryAdminSave(array $params): mixed
    {
        return AdminControllerBridge::invoke(
            Category::class,
            ['postCategoryPost'],
            [],
            [
                'space' => $this->space($params),
                'scope_level' => $this->scopeLevel($params),
                'website_id' => $this->websiteId($params),
                'id' => max(0, (int)($params['id'] ?? 0)),
                'pid' => max(0, (int)($params['pid'] ?? 0)),
                'name' => trim((string)($params['name'] ?? '')),
                'code' => trim((string)($params['code'] ?? '')),
                'is_active' => !empty($params['is_active']) ? 1 : 0,
            ],
            'POST',
        );
    }

    /** @param array<string,mixed> $params */
    private function categoryAdminDelete(array $params): mixed
    {
        return AdminControllerBridge::invoke(
            Category::class,
            ['postCategoryDelete'],
            [],
            [
                'space' => $this->space($params),
                'scope_level' => $this->scopeLevel($params),
                'website_id' => $this->websiteId($params),
                'id' => max(0, (int)($params['id'] ?? 0)),
            ],
            'POST',
        );
    }

    /** @param array<string,mixed> $params */
    private function categoryAdminReorder(array $params): mixed
    {
        return AdminControllerBridge::invoke(
            Category::class,
            ['postCategoryUpdateOrder'],
            [],
            [
                'space' => $this->space($params),
                'scope_level' => $this->scopeLevel($params),
                'website_id' => $this->websiteId($params),
                'id' => max(0, (int)($params['id'] ?? 0)),
                'pid' => max(0, (int)($params['pid'] ?? 0)),
                'level' => max(1, (int)($params['level'] ?? 1)),
                'position' => max(1, (int)($params['position'] ?? 1)),
            ],
            'POST',
        );
    }

    /** @param array<string,mixed> $params */
    private function space(array $params): string
    {
        $space = trim((string)($params['space'] ?? 'product'));
        if ($space === '') {
            throw new \InvalidArgumentException('catalog_category_admin_space_invalid');
        }

        return $space;
    }

    /** @param array<string,mixed> $params */
    private function scopeLevel(array $params): string
    {
        $scopeLevel = strtolower(trim((string)($params['scope_level'] ?? 'website')));
        if (!in_array($scopeLevel, ['website', 'store', 'channel'], true)) {
            throw new \InvalidArgumentException('catalog_category_admin_scope_invalid');
        }

        return $scopeLevel;
    }

    /** @param array<string,mixed> $params */
    private function websiteId(array $params): int
    {
        $websiteId = (int)($params['website_id'] ?? -1);
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('catalog_category_admin_website_invalid');
        }

        return $websiteId;
    }

    /** @return array<string,mixed> */
    private function operation(string $name, string $description, string $mode, array $params): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'frontend' => true,
            'backend' => true,
            'external' => false,
            'auth' => 'backend',
            'backend_acl' => [
                'kind' => 'source',
                'source_id' => self::ACL_SOURCE,
            ],
            'mode' => $mode,
            'graph' => false,
            'cost' => $mode === 'write' ? 3 : 1,
            'params' => $params,
            'returns' => ['type' => 'map'],
        ];
    }
}
