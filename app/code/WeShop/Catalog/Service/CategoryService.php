<?php

declare(strict_types=1);

namespace WeShop\Catalog\Service;

use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Server\Service\MemoryStateFacade;
use WeShop\Catalog\Model\Category;
use WeShop\Catalog\Model\Category\LocalDescription;

/**
 * 分类服务
 */
class CategoryService
{
    private const CATEGORY_TREE_CACHE_TTL_SECONDS = 600.0;
    private const CACHE_NAMESPACE = 'weline_site_runtime';

    /**
     * @var array<string, string>
     */
    private const LOCALIZED_FIELD_MAP = [
        'local_name' => Category::schema_fields_NAME,
        'local_description' => Category::schema_fields_DESCRIPTION,
        'local_meta_title' => 'meta_title',
        'local_meta_description' => 'meta_description',
        'local_meta_keywords' => 'meta_keywords',
    ];

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
     * @var array<string, array{expires_at: float, data: array}>
     */
    private static array $categoryDataCache = [];

    /**
     * @var array<string, array{expires_at: float, data: array}>
     */
    private static array $categoryIndexCache = [];

    /** @var array<string, array<string, string>> */
    private static array $moduleI18nWordsCache = [];

    private static ?MemoryStateFacade $runtimeCache = null;
    private static bool $runtimeCacheResolved = false;

    /**
     * 获取分类
     * 
     * @param int $categoryId 分类ID
     * @return Category|null
     */
    public function getCategory(int $categoryId): ?Category
    {
        $row = $this->getActiveCategoryIndexes()['by_id'][$categoryId] ?? null;
        if (is_array($row)) {
            return $this->categoryFromRow($row);
        }

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
        $locale = State::getLangLocal();

        return $this->categoryDataCacheRemember('category.active_rows.' . $locale, function () use ($locale): array {
            /** @var Category $category */
            $category = ObjectManager::getInstance(Category::class);

            return $this->normalizeCategoryRows($category->clear()
                ->loadLocalDescription($locale)
                ->fields($this->localizedCategorySelectFields())
                ->where(Category::schema_fields_IS_ACTIVE, 1)
                ->order(Category::schema_fields_PARENT_ID, 'ASC')
                ->order(Category::schema_fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetchArray());
        });
    }

    private function localizedCategorySelectFields(): string
    {
        return implode(',', [
            'main_table.*',
            'local.' . LocalDescription::schema_fields_name . ' AS local_name',
            'local.' . LocalDescription::schema_fields_DESCRIPTION . ' AS local_description',
            'local.' . LocalDescription::schema_fields_META_TITLE . ' AS local_meta_title',
            'local.' . LocalDescription::schema_fields_META_DESCRIPTION . ' AS local_meta_description',
            'local.' . LocalDescription::schema_fields_META_KEYWORDS . ' AS local_meta_keywords',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mergeLocalizedCategoryRow(array $row): array
    {
        foreach (self::LOCALIZED_FIELD_MAP as $localField => $field) {
            $localizedValue = trim((string)($row[$localField] ?? ''));
            if ($localizedValue !== '') {
                $row[$field] = $localizedValue;
            }
        }

        return $row;
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
        self::$categoryDataCache = [];
        self::$categoryIndexCache = [];
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
        $cacheKey = $parentId . '|' . $this->categoryDisplayLocaleCacheContext();
        $now = microtime(true);
        $cached = self::$headerSearchOptionsCache[$cacheKey] ?? null;
        if ($cached && $cached['expires_at'] > $now) {
            return $cached['data'];
        }
        $runtimeCacheKey = 'category.header_search_options.' . sha1($cacheKey);
        $runtimeCached = $this->runtimeCacheGet($runtimeCacheKey);
        if (is_array($runtimeCached)) {
            self::$headerSearchOptionsCache[$cacheKey] = [
                'expires_at' => $now + $this->categoryTreeCacheTtl(),
                'data' => $runtimeCached,
            ];
            return $runtimeCached;
        }

        $options = [];
        foreach ($this->getCategoryTree($parentId) as $category) {
            $this->appendHeaderSearchOption($options, $category);
        }

        $ttl = $this->categoryTreeCacheTtl();
        self::$headerSearchOptionsCache[$cacheKey] = [
            'expires_at' => $now + $ttl,
            'data' => $options,
        ];
        $this->runtimeCacheSet($runtimeCacheKey, $options, $ttl);

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
        $label = $this->localizeCategoryDisplayName((string)($category['name'] ?? ''), $category);
        if ($level > 0) {
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
            $separator = $level > 1 ? ' - ' : ' > ';
            $label = $indent . $separator . $label;
        }

        $options[] = [
            'value' => $fullPath,
            'label' => $label,
            'display_label' => $this->localizeCategoryDisplayName((string)($category['name'] ?? ''), $category),
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
        $cacheKey = $parentId . '|' . md5($categoryBaseUrl) . '|' . $this->categoryDisplayLocaleCacheContext();
        $now = microtime(true);
        $cached = self::$headerNavigationCache[$cacheKey] ?? null;
        if ($cached && $cached['expires_at'] > $now) {
            return $cached['data'];
        }
        $runtimeCacheKey = 'category.header_navigation.' . sha1($cacheKey);
        $runtimeCached = $this->runtimeCacheGet($runtimeCacheKey);
        if (is_array($runtimeCached)) {
            self::$headerNavigationCache[$cacheKey] = [
                'expires_at' => $now + $this->categoryTreeCacheTtl(),
                'data' => $runtimeCached,
            ];
            return $runtimeCached;
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
        $ttl = $this->categoryTreeCacheTtl();
        self::$headerNavigationCache[$cacheKey] = [
            'expires_at' => $now + $ttl,
            'data' => $data,
        ];
        $this->runtimeCacheSet($runtimeCacheKey, $data, $ttl);

        return $data;
    }

    public function buildCategoryPublicPath(array $category, string $parentPath = ''): string
    {
        $handle = trim((string)($category['handle'] ?? ''), '/');
        if ($handle === '') {
            return 'catalog/category/view';
        }

        $normalizedParentPath = trim($parentPath, '/');
        $segments = $normalizedParentPath === '' ? [] : array_values(array_filter(explode('/', $normalizedParentPath), static fn (string $segment): bool => $segment !== ''));
        $handleSegments = array_values(array_filter(explode('/', $handle), static fn (string $segment): bool => trim($segment) !== ''));
        $maxOverlap = min(count($segments), count($handleSegments));
        $overlap = 0;
        for ($length = $maxOverlap; $length > 0; $length--) {
            if (array_slice($segments, -$length) === array_slice($handleSegments, 0, $length)) {
                $overlap = $length;
                break;
            }
        }
        $segments = array_merge($segments, array_slice($handleSegments, $overlap));

        return 'catalog/category/' . implode('/', array_map('rawurlencode', $segments));
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
     * 前台分类展示名（顶栏、面包屑、侧栏、卡片等），与数据库存储名解耦。
     *
     * 优先级：LocalDescription（已在分类行 merge）> i18n 词典 > 源文案。
     */
    public function localizeCategoryDisplayName(string $name, array $category = []): string
    {
        $localName = trim((string)($category['local_name'] ?? ''));
        if ($localName !== '') {
            return $localName;
        }

        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $map = [
            'Consumer Electronics' => '消费电子',
            'Smart Devices' => '智能设备',
            'Everyday Apparel' => '日常服饰',
            'Daily Wear' => '日常穿搭',
            'Home Living' => '家居生活',
            'Living Space' => '生活空间',
            'Empty Category Demo' => '演示分类',
            'Prime Video' => '会员视频',
            'Gift Cards' => '礼品卡',
            'Gift Registry' => '礼品心愿单',
            'Customer Service' => '客户服务',
            'Today\'s Deals' => '今日特价',
            'Best Sellers' => '热销榜',
            'New Arrivals' => '新品上市',
            'Shop by Category' => '按分类选购',
        ];

        $phraseKey = $map[$name] ?? $name;
        $lang = State::getLangLocal();
        foreach (['WeShop_Catalog', 'Weline_Theme'] as $moduleName) {
            $fromModule = $this->resolveModuleI18nWord($moduleName, $phraseKey, $lang);
            if ($fromModule !== null) {
                return $fromModule;
            }
        }

        $translated = (string) \__($phraseKey);
        if ($translated !== $phraseKey) {
            return $translated;
        }

        return $phraseKey;
    }

    /**
     * 递归本地化分类记录中的 name / breadcrumbs / children，供分类页模板使用。
     */
    public function localizeCategoryRecordForStorefront(array $record): array
    {
        if (isset($record['name'])) {
            $record['name'] = $this->localizeCategoryDisplayName((string) $record['name'], $record);
        }

        if (!empty($record['breadcrumbs']) && is_array($record['breadcrumbs'])) {
            foreach ($record['breadcrumbs'] as $index => $breadcrumb) {
                if (is_array($breadcrumb)) {
                    $record['breadcrumbs'][$index] = $this->localizeCategoryRecordForStorefront($breadcrumb);
                }
            }
        }

        foreach (['children', 'children_tree'] as $childrenKey) {
            if (empty($record[$childrenKey]) || !is_array($record[$childrenKey])) {
                continue;
            }
            foreach ($record[$childrenKey] as $index => $child) {
                if (is_array($child)) {
                    $record[$childrenKey][$index] = $this->localizeCategoryRecordForStorefront($child);
                }
            }
        }

        return $record;
    }

    private function resolveModuleI18nWord(string $moduleName, string $word, string $lang): ?string
    {
        $cacheKey = $lang . '|' . $moduleName;
        if (!isset(self::$moduleI18nWordsCache[$cacheKey])) {
            self::$moduleI18nWordsCache[$cacheKey] = $this->loadModuleI18nCsv($moduleName, $lang);
        }

        $translate = self::$moduleI18nWordsCache[$cacheKey][$word] ?? null;
        if (!is_string($translate) || $translate === '' || $translate === $word) {
            return null;
        }

        return $translate;
    }

    /**
     * @return array<string, string>
     */
    private function loadModuleI18nCsv(string $moduleName, string $lang): array
    {
        $words = [];
        try {
            $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
            $csvFile = ($moduleInfo['base_path'] ?? '') . '/i18n/' . $lang . '.csv';
            if (!is_file($csvFile)) {
                return $words;
            }

            $handle = @fopen($csvFile, 'r');
            if ($handle === false) {
                return $words;
            }

            while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                if (!isset($data[0], $data[1])) {
                    continue;
                }
                $source = trim((string) $data[0]);
                $target = trim((string) $data[1]);
                if ($source === '' || $target === '' || $target === $source) {
                    continue;
                }
                $words[$source] = $target;
            }

            fclose($handle);
        } catch (\Throwable) {
        }

        return $words;
    }

    private function categoryDisplayLocaleCacheContext(): string
    {
        return KeyBuilder::environmentHash([
            'scope' => 'catalog-category-display',
        ]);
    }
    
    /**
     * 通过handle获取分类
     * 
     * @param string $handle Handle标识（支持层级路径，如 "men/shirts"）
     * @return Category|null
     */
    public function getCategoryByHandle(string $handle): ?Category
    {
        $decodedHandle = urldecode($handle);
        $row = $this->findActiveCategoryRowByHandle($decodedHandle, $handle);
        if (is_array($row)) {
            return $this->categoryFromRow($row);
        }

        /** @var Category $categoryModel */
        $categoryModel = ObjectManager::getInstance(Category::class);

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
        $children = $this->getActiveCategoryIndexes()['children_by_parent'][$parentId] ?? null;
        return is_array($children) ? $children : [];
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

        $cacheKey = $parentId . '|' . ($includeRightMenuOnly ? 'right' : 'all') . '|' . State::getLangLocal();
        $now = microtime(true);
        $cached = self::$categoryTreeCache[$cacheKey] ?? null;
        if ($cached && $cached['expires_at'] > $now) {
            return $cached['data'];
        }
        $runtimeCacheKey = 'category.tree.' . $cacheKey;
        $runtimeCached = $this->runtimeCacheGet($runtimeCacheKey);
        if (is_array($runtimeCached)) {
            self::$categoryTreeCache[$cacheKey] = [
                'expires_at' => $now + $this->categoryTreeCacheTtl(),
                'data' => $runtimeCached,
            ];
            return $runtimeCached;
        }

        $categories = $this->buildCategoryTree($parentId, $includeRightMenuOnly);
        $ttl = $this->categoryTreeCacheTtl();
        self::$categoryTreeCache[$cacheKey] = [
            'expires_at' => $now + $ttl,
            'data' => $categories,
        ];
        $this->runtimeCacheSet($runtimeCacheKey, $categories, $ttl);

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
        
        return $this->normalizeCategoryRows($category->clear()
            ->loadLocalDescription(State::getLangLocal())
            ->fields($this->localizedCategorySelectFields())
            ->where(Category::schema_fields_PARENT_ID, $parentId)
            ->order(Category::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetchArray());
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
        $childrenByParent = $this->getActiveCategoryIndexes()['children_by_parent'] ?? [];
        
        // 递归获取所有子分类ID
        $getChildrenIds = function(int $catId) use (&$getChildrenIds, &$categoryIds, $childrenByParent) {
            $children = $childrenByParent[$catId] ?? [];
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

    private function runtimeCacheGet(string $key): mixed
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return null;
        }

        try {
            return $cache->get(self::CACHE_NAMESPACE, $key);
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
            return null;
        }
    }

    private function runtimeCacheSet(string $key, mixed $value, int $ttl): void
    {
        $cache = self::runtimeCache();
        if ($cache === null) {
            return;
        }

        try {
            $cache->set(self::CACHE_NAMESPACE, $key, $value, max(1, $ttl));
        } catch (\Throwable) {
            self::$runtimeCache = null;
            self::$runtimeCacheResolved = true;
        }
    }

    private static function runtimeCache(): ?MemoryStateFacade
    {
        if (self::$runtimeCacheResolved) {
            return self::$runtimeCache;
        }
        self::$runtimeCacheResolved = true;

        if (!class_exists(Runtime::class, false) || !Runtime::isPersistent() || !class_exists(MemoryStateFacade::class)) {
            return null;
        }

        try {
            /** @var RuntimeCachePolicy $policy */
            $policy = ObjectManager::getInstance(RuntimeCachePolicy::class);
            self::$runtimeCache = new MemoryStateFacade($policy->memoryOptions([
                'consumer_code' => self::CACHE_NAMESPACE,
                'prefer_direct_connect' => true,
                'persistent' => true,
                'lazy_connect' => true,
            ]));
        } catch (\Throwable) {
            self::$runtimeCache = null;
        }

        return self::$runtimeCache;
    }

    private function categoryTreeCacheTtl(): int
    {
        try {
            /** @var RuntimeCachePolicy $policy */
            $policy = ObjectManager::getInstance(RuntimeCachePolicy::class);
            return $policy->ttl('site.category_tree_ttl', (int)self::CATEGORY_TREE_CACHE_TTL_SECONDS);
        } catch (\Throwable) {
            return (int)self::CATEGORY_TREE_CACHE_TTL_SECONDS;
        }
    }

    /**
     * @return array{by_id: array<int, array<string, mixed>>, by_handle: array<string, array<string, mixed>>, children_by_parent: array<int, array<int, array<string, mixed>>>}
     */
    private function getActiveCategoryIndexes(): array
    {
        $cacheKey = 'active_indexes|' . State::getLangLocal();
        $now = microtime(true);
        $cached = self::$categoryIndexCache[$cacheKey] ?? null;
        if (is_array($cached) && ($cached['expires_at'] ?? 0.0) >= $now && is_array($cached['data'] ?? null)) {
            return $cached['data'];
        }

        $byId = [];
        $byHandle = [];
        $childrenByParent = [];

        foreach ($this->getAllActiveCategoriesForTree() as $category) {
            if (!is_array($category)) {
                continue;
            }

            $categoryId = (int)($category[Category::schema_fields_ID] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }

            $category[Category::schema_fields_ID] = $categoryId;
            $category[Category::schema_fields_PARENT_ID] = (int)($category[Category::schema_fields_PARENT_ID] ?? 0);
            $this->attachTreeAttributes($category);

            $byId[$categoryId] = $category;

            $handle = trim((string)($category[Category::schema_fields_HANDLE] ?? ''), '/');
            if ($handle !== '') {
                $byHandle[$handle] = $category;
            }

            $childrenByParent[$category[Category::schema_fields_PARENT_ID]][] = $category;
        }

        $indexes = [
            'by_id' => $byId,
            'by_handle' => $byHandle,
            'children_by_parent' => $childrenByParent,
        ];

        self::$categoryIndexCache[$cacheKey] = [
            'expires_at' => $now + $this->categoryTreeCacheTtl(),
            'data' => $indexes,
        ];

        return $indexes;
    }

    /**
     * @param callable(): array<int, array<string, mixed>> $loader
     * @return array<int, array<string, mixed>>
     */
    private function categoryDataCacheRemember(string $key, callable $loader): array
    {
        $now = microtime(true);
        $cached = self::$categoryDataCache[$key] ?? null;
        if (is_array($cached) && ($cached['expires_at'] ?? 0.0) >= $now && is_array($cached['data'] ?? null)) {
            return $cached['data'];
        }

        $runtimeCached = $this->runtimeCacheGet($key);
        if (is_array($runtimeCached)) {
            $rows = $this->normalizeCategoryRows($runtimeCached);
            self::$categoryDataCache[$key] = [
                'expires_at' => $now + $this->categoryTreeCacheTtl(),
                'data' => $rows,
            ];
            return $rows;
        }

        $rows = $loader();
        $ttl = $this->categoryTreeCacheTtl();
        self::$categoryDataCache[$key] = [
            'expires_at' => $now + $ttl,
            'data' => $rows,
        ];
        $this->runtimeCacheSet($key, $rows, $ttl);

        return $rows;
    }

    /**
     * @param mixed $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCategoryRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $this->mergeLocalizedCategoryRow($row);
            }
        }

        return $normalized;
    }

    private function findActiveCategoryRowByHandle(string $decodedHandle, string $rawHandle): ?array
    {
        $byHandle = $this->getActiveCategoryIndexes()['by_handle'];
        $candidates = [];

        foreach ([$decodedHandle, $rawHandle] as $handle) {
            $handle = trim($handle, '/');
            if ($handle !== '') {
                $candidates[] = $handle;
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($byHandle[$candidate])) {
                return $byHandle[$candidate];
            }
        }

        foreach ($candidates as $candidate) {
            if (!str_contains($candidate, '/')) {
                continue;
            }
            $leaf = basename($candidate);
            if ($leaf !== '' && isset($byHandle[$leaf])) {
                return $byHandle[$leaf];
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate === '' || str_contains($candidate, '/')) {
                continue;
            }

            foreach ($byHandle as $handle => $row) {
                if (str_ends_with($handle, '/' . $candidate)) {
                    return $row;
                }
            }
        }

        return null;
    }

    private function categoryFromRow(array $row): Category
    {
        /** @var Category $categoryModel */
        $categoryModel = ObjectManager::getInstance(Category::class);
        $category = clone $categoryModel;
        $category->clear();
        $category->setData($row);

        return $category;
    }
}
