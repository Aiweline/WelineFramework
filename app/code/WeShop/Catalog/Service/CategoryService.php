<?php

declare(strict_types=1);

namespace WeShop\Catalog\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Catalog\Model\Category;

/**
 * 分类服务
 */
class CategoryService
{
    private const CATEGORY_TREE_CACHE_TTL_SECONDS = 60.0;

    /**
     * Header hooks read category navigation on every frontend page. Cache only
     * data, never rendered HTML, so request-specific header content stays safe.
     *
     * @var array<string, array{expires_at: float, data: array}>
     */
    private static array $categoryTreeCache = [];

    /**
     * @var array<string, array{expires_at: float, data: array}>
     */
    private static array $headerSearchOptionsCache = [];

    /**
     * @var array<string, array{expires_at: float, data: array}>
     */
    private static array $headerNavigationCache = [];

    /**
     * 获取分类
     * 
     * @param int $categoryId 分类ID
     * @return Category|null
     */
    public function getCategory(int $categoryId): ?Category
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        $category->load($categoryId);
        
        if ($category->getId()) {
            return $category;
        }
        
        return null;
    }

    private function buildCategoryTree(int $parentId = 0, bool $includeRightMenuOnly = false): array
    {
        $childrenByParent = [];
        foreach ($this->getAllActiveCategoriesForTree() as $category) {
            $categoryId = (int)($category[Category::schema_fields_ID] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }

            $category[Category::schema_fields_ID] = $categoryId;
            $category[Category::schema_fields_PARENT_ID] = (int)($category[Category::schema_fields_PARENT_ID] ?? 0);
            $this->attachTreeAttributes($category);
            $childrenByParent[$category[Category::schema_fields_PARENT_ID]][] = $category;
        }

        return $this->buildTreeFromGroupedChildren($childrenByParent, $parentId, $includeRightMenuOnly);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAllActiveCategoriesForTree(): array
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);

        return $category->clear()
            ->where(Category::schema_fields_IS_ACTIVE, 1)
            ->order(Category::schema_fields_PARENT_ID, 'ASC')
            ->order(Category::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();
    }

    private function attachTreeAttributes(array &$category): void
    {
        $attributeValues = $category['attribute_value'] ?? [];
        $category['is_right_menu'] = (int)($category['is_right_menu'] ?? $attributeValues['is_right_menu'] ?? 0);
        $category['icon'] = (string)($category['icon'] ?? $attributeValues['icon'] ?? '');
        $category['show_icon'] = (bool)($category['show_icon'] ?? $attributeValues['show_icon'] ?? true);
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $childrenByParent
     * @return array<int, array<string, mixed>>
     */
    private function buildTreeFromGroupedChildren(array $childrenByParent, int $parentId, bool $includeRightMenuOnly): array
    {
        $tree = [];
        foreach ($childrenByParent[$parentId] ?? [] as $category) {
            if ($includeRightMenuOnly && (int)($category['is_right_menu'] ?? 0) !== 1) {
                continue;
            }

            $category['children'] = $this->buildTreeFromGroupedChildren(
                $childrenByParent,
                (int)$category[Category::schema_fields_ID],
                $includeRightMenuOnly
            );
            $tree[] = $category;
        }

        return $tree;
    }

    public static function clearTreeCache(): void
    {
        self::$categoryTreeCache = [];
        self::$headerSearchOptionsCache = [];
        self::$headerNavigationCache = [];
    }

    private function filterRightMenuTree(array $categories): array
    {
        $filtered = [];
        foreach ($categories as $category) {
            if ((int)($category['is_right_menu'] ?? 0) !== 1) {
                continue;
            }

            if (!empty($category['children']) && is_array($category['children'])) {
                $category['children'] = $this->filterRightMenuTree($category['children']);
            }
            $filtered[] = $category;
        }

        return $filtered;
    }

    public function getHeaderSearchCategoryOptions(int $parentId = 0): array
    {
        $cacheKey = (string)$parentId;
        $now = microtime(true);
        $cached = self::$headerSearchOptionsCache[$cacheKey] ?? null;
        if ($cached && $cached['expires_at'] > $now) {
            return $cached['data'];
        }

        $options = [];
        foreach ($this->getCategoryTree($parentId) as $category) {
            $this->appendHeaderSearchOption($options, $category);
        }

        self::$headerSearchOptionsCache[$cacheKey] = [
            'expires_at' => $now + self::CATEGORY_TREE_CACHE_TTL_SECONDS,
            'data' => $options,
        ];

        return $options;
    }

    private function appendHeaderSearchOption(array &$options, array $category, int $level = 0, string $parentPath = ''): void
    {
        if (empty($category['is_active']) || (int)$category['is_active'] !== 1) {
            return;
        }

        $handle = trim((string)($category['handle'] ?? ''), '/');
        $categoryId = (string)($category['category_id'] ?? '');
        $pathSegment = $handle !== '' ? $handle : $categoryId;
        $fullPath = $parentPath !== '' ? $parentPath . '/' . $pathSegment : $pathSegment;
        $label = (string)($category['name'] ?? '');
        if ($level > 0) {
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
            $separator = $level > 1 ? ' - ' : ' > ';
            $label = $indent . $separator . $label;
        }

        $options[] = [
            'value' => $fullPath,
            'label' => $label,
            'display_label' => (string)($category['name'] ?? ''),
            'level' => $level,
            'full_path' => $fullPath,
        ];

        foreach (($category['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->appendHeaderSearchOption($options, $child, $level + 1, $fullPath);
            }
        }
    }

    public function getHeaderNavigationData(string $categoryBaseUrl, int $parentId = 0): array
    {
        $categoryBaseUrl = rtrim($categoryBaseUrl, '/') . '/';
        $cacheKey = $parentId . '|' . md5($categoryBaseUrl);
        $now = microtime(true);
        $cached = self::$headerNavigationCache[$cacheKey] ?? null;
        if ($cached && $cached['expires_at'] > $now) {
            return $cached['data'];
        }

        $navItems = [];
        foreach ($this->getCategoryTree($parentId) as $category) {
            $menuItem = $this->buildHeaderMenuItem($category, $categoryBaseUrl);
            if ($menuItem !== null) {
                $navItems[] = $menuItem;
            }
        }

        $sidebarShortcuts = [];
        foreach ($this->getRightMenuCategoryTree($parentId) as $category) {
            $shortcut = $this->buildHeaderSidebarShortcut($category, $categoryBaseUrl);
            if ($shortcut !== null) {
                $sidebarShortcuts[] = $shortcut;
            }
        }

        $data = [
            'navItems' => $navItems,
            'sidebarShortcuts' => $sidebarShortcuts,
        ];
        self::$headerNavigationCache[$cacheKey] = [
            'expires_at' => $now + self::CATEGORY_TREE_CACHE_TTL_SECONDS,
            'data' => $data,
        ];

        return $data;
    }

    private function buildHeaderMenuItem(array $category, string $categoryBaseUrl, string $parentPath = ''): ?array
    {
        if (empty($category['is_active']) || (int)$category['is_active'] !== 1) {
            return null;
        }

        $rawSlug = trim((string)($category['handle'] ?? ''), '/');
        if ($rawSlug === '') {
            return null;
        }

        $encodedSlug = rawurlencode($rawSlug);
        $fullHandle = $parentPath === '' ? $encodedSlug : $parentPath . '/' . $encodedSlug;
        $menuItem = [
            'text' => (string)($category['name'] ?? ''),
            'url' => $categoryBaseUrl . $fullHandle,
        ];

        $children = [];
        foreach (($category['children'] ?? []) as $child) {
            if (!is_array($child)) {
                continue;
            }
            $childMenuItem = $this->buildHeaderMenuItem($child, $categoryBaseUrl, $fullHandle);
            if ($childMenuItem !== null) {
                $children[] = $childMenuItem;
            }
        }
        if ($children) {
            $menuItem['children'] = $children;
        }

        return $menuItem;
    }

    private function buildHeaderSidebarShortcut(array $category, string $categoryBaseUrl): ?array
    {
        if (empty($category['is_active']) || (int)$category['is_active'] !== 1) {
            return null;
        }

        $categoryHandle = trim((string)($category['handle'] ?? ''), '/');
        if ($categoryHandle === '') {
            return null;
        }

        return [
            'text' => (string)($category['name'] ?? ''),
            'url' => $categoryBaseUrl . rawurlencode($categoryHandle),
            'icon' => (string)($category['icon'] ?? 'fas fa-circle'),
        ];
    }
    
    /**
     * 通过handle获取分类
     * 
     * @param string $handle Handle标识（支持层级路径，如 "men/shirts"）
     * @return Category|null
     */
    public function getCategoryByHandle(string $handle): ?Category
    {
        /** @var Category $categoryModel */
        $categoryModel = ObjectManager::getInstance(Category::class);
        
        $decodedHandle = urldecode($handle);

        // 1. 优先用完整 handle 精确匹配（支持层级路径已直接存入 handle 的场景）
        // 克隆模型以避免状态污染
        $category = clone $categoryModel;
        $category->clear()
            ->where(Category::schema_fields_HANDLE, $decodedHandle)
            ->where(Category::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        if ($category->getId()) {
            return $category;
        }

        // 2. 如果包含层级路径（如 men/shirts），尝试使用最后一段作为 handle 匹配
        if (str_contains($decodedHandle, '/')) {
            $leafHandle = basename($decodedHandle);
            if ($leafHandle !== '') {
                $category = clone $categoryModel;
                $category->clear()
                    ->where(Category::schema_fields_HANDLE, $leafHandle)
                    ->where(Category::schema_fields_IS_ACTIVE, 1)
                    ->find()
                    ->fetch();

                if ($category->getId()) {
                    return $category;
                }
            }
        }

        // 3. 最后退回到原始 handle 精确匹配（兼容部分场景）
        if ($decodedHandle !== $handle) {
            $category = clone $categoryModel;
            $category->clear()
                ->where(Category::schema_fields_HANDLE, $handle)
                ->where(Category::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();

            if ($category->getId()) {
                return $category;
            }
        }

        return null;
    }
    
    /**
     * 获取子分类
     * 
     * @param int $parentId 父分类ID
     * @return array
     */
    public function getChildCategories(int $parentId): array
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        
        return $category->clear()
            ->where(Category::schema_fields_PARENT_ID, $parentId)
            ->where(Category::schema_fields_IS_ACTIVE, 1)
            ->order(Category::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取分类树
     * 
     * @param int $parentId 父分类ID（默认0）
     * @param bool $includeRightMenuOnly 是否只包含右侧菜单分类（默认false）
     * @return array
     */
    public function getCategoryTree(int $parentId = 0, bool $includeRightMenuOnly = false): array
    {
        if ($includeRightMenuOnly) {
            return $this->filterRightMenuTree($this->getCategoryTree($parentId, false));
        }

        $cacheKey = $parentId . '|' . ($includeRightMenuOnly ? 'right' : 'all');
        $now = microtime(true);
        $cached = self::$categoryTreeCache[$cacheKey] ?? null;
        if ($cached && $cached['expires_at'] > $now) {
            return $cached['data'];
        }

        $categories = $this->buildCategoryTree($parentId, $includeRightMenuOnly);
        self::$categoryTreeCache[$cacheKey] = [
            'expires_at' => $now + self::CATEGORY_TREE_CACHE_TTL_SECONDS,
            'data' => $categories,
        ];

        return $categories;
    }
    
    /**
     * 获取右侧菜单分类树
     * 
     * @param int $parentId 父分类ID（默认0）
     * @return array
     */
    public function getRightMenuCategoryTree(int $parentId = 0): array
    {
        return $this->getCategoryTree($parentId, true);
    }
    
    /**
     * 获取带本地化名称的子分类（后台管理用，包含禁用分类）
     * 
     * @param int $parentId 父分类ID
     * @return array
     */
    public function getChildCategoriesWithLocal(int $parentId): array
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        
        return $category->clear()
            ->loadLocalDescription()
            ->where(Category::schema_fields_PARENT_ID, $parentId)
            ->order(Category::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 获取带本地化名称和层级的分类树（后台管理用）
     * 
     * @param int $parentId 父分类ID（默认0）
     * @param int $level 当前层级（默认1）
     * @param int $maxLevel 最大层级（默认4）
     * @return array
     */
    public function getCategoryTreeWithLocal(int $parentId = 0, int $level = 1, int $maxLevel = 4): array
    {
        $categories = $this->getChildCategoriesWithLocal($parentId);
        
        foreach ($categories as &$category) {
            $category['level'] = $level;
            $category['can_add_child'] = $level < $maxLevel;
            
            $localName = $category['local_name'] ?? '';
            if (!$localName) {
                $localName = $category['name'] ?? '';
            }
            $category['display_name'] = $localName;
            
            if ($level < $maxLevel) {
                $category['children'] = $this->getCategoryTreeWithLocal(
                    (int)$category['category_id'], 
                    $level + 1, 
                    $maxLevel
                );
            } else {
                $category['children'] = [];
            }
        }
        
        return array_values($categories);
    }
    
    /**
     * 获取所有子分类ID（递归，包括子分类的子分类）
     * 
     * @param int $parentId 父分类ID
     * @return array 所有子分类ID数组（包括传入的父分类ID本身）
     */
    public function getAllDescendantCategoryIds(int $parentId): array
    {
        $categoryIds = [$parentId]; // 包含当前分类ID
        
        // 递归获取所有子分类ID
        $getChildrenIds = function(int $catId) use (&$getChildrenIds, &$categoryIds) {
            $children = $this->getChildCategories($catId);
            foreach ($children as $child) {
                $childId = (int)($child['category_id'] ?? 0);
                if ($childId > 0 && !in_array($childId, $categoryIds)) {
                    $categoryIds[] = $childId;
                    // 递归获取子分类的子分类
                    $getChildrenIds($childId);
                }
            }
        };
        
        $getChildrenIds($parentId);
        
        return array_unique($categoryIds);
    }
    
    /**
     * 保存分类
     * 
     * @param array $categoryData 分类数据
     * @return Category
     */
    public function saveCategory(array $categoryData): Category
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        
        if (!empty($categoryData[Category::schema_fields_ID])) {
            $category->load($categoryData[Category::schema_fields_ID]);
        }
        
        foreach ($categoryData as $key => $value) {
            if ($key !== Category::schema_fields_ID) {
                $category->setData($key, $value);
            }
        }
        
        $category->save();
        self::clearTreeCache();
        
        return $category;
    }
}
