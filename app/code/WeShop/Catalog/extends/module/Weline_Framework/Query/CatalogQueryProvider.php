<?php
declare(strict_types=1);

namespace WeShop\Catalog\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\Catalog\Model\Category;

/**
 * 分类查询器
 *
 * 提供 getCategoryById、getCategoryByHandle 等能力，
 * 供其他模块通过 w_query('catalog', ...) 调用。
 */
class CatalogQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly Category $categoryModel
    ) {
    }

    public function getProviderName(): string
    {
        return 'catalog';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getCategoryById' => $this->getCategoryById($params),
            'getCategoryByHandle' => $this->getCategoryByHandle($params),
            'getCategoryNames' => $this->getCategoryNames($params),
            'getAllDescendantCategoryIds' => $this->getAllDescendantCategoryIds($params),
            default => throw new \InvalidArgumentException(
                (string)__('Catalog 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function getCategoryById(array $params): ?array
    {
        $categoryId = (int)($params['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return null;
        }
        $category = clone $this->categoryModel;
        $category->load($categoryId);
        if (!$category->getId()) {
            return null;
        }
        return $this->categoryToArray($category);
    }

    private function getCategoryByHandle(array $params): ?array
    {
        $handle = trim((string)($params['handle'] ?? ''));
        if ($handle === '') {
            return null;
        }
        $decodedHandle = urldecode($handle);
        $category = clone $this->categoryModel;
        $category->clear()
            ->where(Category::schema_fields_HANDLE, $decodedHandle)
            ->where(Category::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        if (!$category->getId()) {
            if (str_contains($decodedHandle, '/')) {
                $leafHandle = basename($decodedHandle);
                if ($leafHandle !== '') {
                    $category = clone $this->categoryModel;
                    $category->clear()
                        ->where(Category::schema_fields_HANDLE, $leafHandle)
                        ->where(Category::schema_fields_IS_ACTIVE, 1)
                        ->find()
                        ->fetch();
                }
            }
        }
        if (!$category->getId()) {
            return null;
        }
        return $this->categoryToArray($category);
    }

    /**
     * 根据分类 ID 列表获取分类名称
     */
    private function getCategoryNames(array $params): array
    {
        $categoryIds = $params['category_ids'] ?? [];
        if (!is_array($categoryIds) || empty($categoryIds)) {
            return [];
        }
        $categoryIds = array_filter(array_map('intval', $categoryIds));
        if (empty($categoryIds)) {
            return [];
        }
        $category = clone $this->categoryModel;
        $category->clear()
            ->fields([Category::schema_fields_ID, Category::schema_fields_NAME])
            ->where(Category::schema_fields_ID, $categoryIds, 'in');
        $rows = $category->select()->fetch()->getItems();
        $names = [];
        foreach ($rows as $row) {
            $id = is_object($row) ? $row->getData(Category::schema_fields_ID) : ($row[Category::schema_fields_ID] ?? null);
            $name = is_object($row) ? $row->getData(Category::schema_fields_NAME) : ($row[Category::schema_fields_NAME] ?? '');
            if ($id !== null && $name !== null && $name !== '') {
                $names[(int)$id] = (string)$name;
            }
        }
        return array_values($names);
    }

    /**
     * 获取分类及其所有子孙分类的 ID 列表
     */
    private function getAllDescendantCategoryIds(array $params): array
    {
        $parentId = (int)($params['category_id'] ?? 0);
        if ($parentId <= 0) {
            return [];
        }
        $categoryIds = [$parentId];
        $getChildrenIds = function (int $catId) use (&$getChildrenIds, &$categoryIds): void {
            $category = clone $this->categoryModel;
            $category->clear()
                ->fields(Category::schema_fields_ID)
                ->where(Category::schema_fields_PARENT_ID, $catId)
                ->where(Category::schema_fields_IS_ACTIVE, 1);
            $rows = $category->select()->fetch()->getItems();
            foreach ($rows as $row) {
                $childId = is_object($row) ? (int)$row->getData(Category::schema_fields_ID) : (int)($row[Category::schema_fields_ID] ?? 0);
                if ($childId > 0 && !in_array($childId, $categoryIds, true)) {
                    $categoryIds[] = $childId;
                    $getChildrenIds($childId);
                }
            }
        };
        $getChildrenIds($parentId);
        return array_values(array_unique($categoryIds));
    }

    private function categoryToArray($category): array
    {
        return [
            'category_id' => (int)$category->getId(),
            'parent_id' => (int)($category->getData(Category::schema_fields_PARENT_ID) ?? 0),
            'name' => $category->getData(Category::schema_fields_NAME),
            'handle' => $category->getData(Category::schema_fields_HANDLE),
            'description' => $category->getData(Category::schema_fields_DESCRIPTION),
            'image' => $category->getData(Category::schema_fields_IMAGE),
            'sort_order' => (int)($category->getData(Category::schema_fields_SORT_ORDER) ?? 0),
            'is_active' => (int)($category->getData(Category::schema_fields_IS_ACTIVE) ?? 1),
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'catalog',
            'name' => __('分类查询'),
            'description' => __('提供分类信息查询能力'),
            'module' => 'WeShop_Catalog',
            'operations' => [
                [
                    'name' => 'getCategoryById',
                    'description' => __('根据 ID 获取分类信息'),
                    'params' => [['name' => 'category_id', 'type' => 'int', 'required' => true]],
                ],
                [
                    'name' => 'getCategoryByHandle',
                    'description' => __('根据 handle 获取分类信息'),
                    'params' => [['name' => 'handle', 'type' => 'string', 'required' => true]],
                ],
                [
                    'name' => 'getCategoryNames',
                    'description' => __('根据分类 ID 列表获取分类名称'),
                    'params' => [['name' => 'category_ids', 'type' => 'array', 'required' => true]],
                ],
                [
                    'name' => 'getAllDescendantCategoryIds',
                    'description' => __('获取分类及其所有子孙分类的 ID 列表'),
                    'params' => [['name' => 'category_id', 'type' => 'int', 'required' => true]],
                ],
            ],
        ];
    }
}
