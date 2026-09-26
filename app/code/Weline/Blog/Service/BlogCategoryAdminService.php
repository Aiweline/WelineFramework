<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Model\Category;

/**
 * Blog category CRUD for Catalog space=blog (max depth 2).
 */
final class BlogCategoryAdminService
{
    public const MAX_DEPTH = 2;

    private ?BlogContentCache $contentCache = null;

    public function __construct(
        private readonly Category $categoryModel,
        private readonly BlogCategoryAttributeService $categoryAttributes,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tree(int $websiteId, string $locale = ''): array
    {
        if (!\Weline\Framework\Context::hasCurrent()) {
            return $this->buildTree($websiteId, $locale, true);
        }
        $locale = trim(str_replace('-', '_', $locale !== '' ? $locale : (string)\Weline\Framework\App\State::getLangLocal()));
        $this->contentCache ??= \Weline\Framework\Manager\ObjectManager::getInstance(BlogContentCache::class);
        return $this->contentCache->rememberForRequest('category_tree', [$websiteId, $locale], fn(): array => $this->buildTree($websiteId, $locale, true));
    }

    /**
     * Categories owned solely by this website (no website_id=0 merge).
     * Used by storefront search type scopes to prevent cross-brand taxonomy leakage.
     *
     * @return list<array<string, mixed>>
     */
    public function treeOwnedOnly(int $websiteId, string $locale = ''): array
    {
        $websiteId = max(0, $websiteId);
        if ($websiteId <= 0) {
            return $this->tree($websiteId, $locale);
        }
        if (!\Weline\Framework\Context::hasCurrent()) {
            return $this->buildTree($websiteId, $locale, false);
        }
        $locale = trim(str_replace('-', '_', $locale !== '' ? $locale : (string)\Weline\Framework\App\State::getLangLocal()));
        $this->contentCache ??= \Weline\Framework\Manager\ObjectManager::getInstance(BlogContentCache::class);
        return $this->contentCache->rememberForRequest(
            'category_tree_owned',
            [$websiteId, $locale],
            fn(): array => $this->buildTree($websiteId, $locale, false)
        );
    }

    /** @return list<array<string, mixed>> */
    private function buildTree(int $websiteId, string $locale, bool $includeGlobal = true): array
    {
        $presented = $this->enrichedRows($websiteId, $locale, $includeGlobal);
        $byParent = [];
        foreach ($presented as $row) {
            $parentId = max(0, (int)($row['parent_id'] ?? 0));
            $byParent[$parentId] ??= [];
            $byParent[$parentId][] = $row;
        }

        $walk = function (int $parentId) use (&$walk, $byParent): array {
            $nodes = [];
            foreach ($byParent[$parentId] ?? [] as $row) {
                $categoryId = (int)($row['category_id'] ?? 0);
                $node = $row;
                $node['nodes'] = $categoryId > 0 ? $walk($categoryId) : [];
                $nodes[] = $node;
            }

            return $nodes;
        };

        return $walk(0);
    }

    /** @return array<string, mixed>|null */
    public function view(int $websiteId, int $categoryId, string $locale = ''): ?array
    {
        return $this->findNode($this->tree($websiteId, $locale), $categoryId);
    }

    /**
     * @return array{category_id:int,code:string,parent_id:int}
     */
    public function save(
        int $websiteId,
        int $categoryId,
        string $name,
        string $code = '',
        string $locale = '',
        int $sortOrder = 0,
        ?string $image = null,
        ?string $banner = null,
        ?string $summary = null,
        ?string $description = null,
        int $parentId = 0,
    ): array {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException((string)__('分类名称不能为空'));
        }
        $parentId = max(0, $parentId);
        $this->assertParentAllowed($websiteId, $categoryId, $parentId);

        $slug = $this->resolveCode($code, $name);
        $now = date('Y-m-d H:i:s');
        $model = clone $this->categoryModel;

        if ($categoryId > 0) {
            $model->clearData()->reset()->load($categoryId);
            if ($model->getCategoryId() <= 0) {
                throw new \InvalidArgumentException((string)__('分类不存在'));
            }
            $model->setData(Category::schema_fields_SLUG, $slug);
            $model->setData(Category::schema_fields_NAME, $name);
            $model->setData(Category::schema_fields_PARENT_ID, $parentId);
            $model->setData(Category::schema_fields_SORT_ORDER, $sortOrder);
            $model->setData(Category::schema_fields_UPDATED_AT, $now);
            $model->save();
        } else {
            $model->clearData()->reset();
            $model->setData(Category::schema_fields_WEBSITE_ID, $websiteId);
            $model->setData(Category::schema_fields_SLUG, $slug);
            $model->setData(Category::schema_fields_NAME, $name);
            $model->setData(Category::schema_fields_PARENT_ID, $parentId);
            $model->setData(Category::schema_fields_SORT_ORDER, $sortOrder);
            $model->setData(Category::schema_fields_CREATED_AT, $now);
            $model->setData(Category::schema_fields_UPDATED_AT, $now);
            $model->save();
            $categoryId = $model->getCategoryId();
        }

        $this->categoryAttributes->writeName($websiteId, $categoryId, $name, $locale);
        $this->categoryAttributes->writeCode($websiteId, $categoryId, $slug, $locale);
        if ($image !== null) {
            $this->categoryAttributes->writeImage($websiteId, $categoryId, $image, $locale);
        }
        if ($banner !== null) {
            $this->categoryAttributes->writeBanner($websiteId, $categoryId, $banner, $locale);
        }
        if ($summary !== null) {
            $this->categoryAttributes->writeSummary($websiteId, $categoryId, $summary, $locale);
        }
        if ($description !== null) {
            $this->categoryAttributes->writeDescription($websiteId, $categoryId, $description, $locale);
        }

        return [
            'category_id' => $categoryId,
            'code' => $slug,
            'parent_id' => $parentId,
            'image' => $image !== null ? trim($image) : null,
            'banner' => $banner !== null ? trim($banner) : null,
            'summary' => $summary !== null ? trim($summary) : null,
            'description' => $description !== null ? trim($description) : null,
        ];
    }

    public function delete(int $websiteId, int $categoryId): void
    {
        unset($websiteId);
        if ($categoryId <= 0) {
            throw new \InvalidArgumentException((string)__('分类 ID 不能为空'));
        }
        $model = clone $this->categoryModel;
        $model->clearData()->reset()->load($categoryId);
        if ($model->getCategoryId() <= 0) {
            throw new \InvalidArgumentException((string)__('分类不存在'));
        }
        $model->delete();
    }

    /**
     * @return array{id:int,pid:int,level:int,position:int}
     */
    public function reorder(
        int $websiteId,
        int $categoryId,
        int $parentId,
        int $level,
        int $position,
    ): array {
        unset($level);
        if ($categoryId <= 0) {
            throw new \InvalidArgumentException((string)__('分类 ID 不能为空'));
        }
        $parentId = max(0, $parentId);
        $this->assertParentAllowed($websiteId, $categoryId, $parentId);

        $model = clone $this->categoryModel;
        $model->clearData()->reset()->load($categoryId);
        if ($model->getCategoryId() <= 0) {
            throw new \InvalidArgumentException((string)__('分类不存在'));
        }
        $model->setData(Category::schema_fields_PARENT_ID, $parentId);
        $model->setData(Category::schema_fields_SORT_ORDER, max(0, $position));
        $model->setData(Category::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
        $model->save();

        return [
            'id' => $categoryId,
            'pid' => $parentId,
            'level' => $parentId > 0 ? 2 : 1,
            'position' => max(0, $position),
        ];
    }

    /**
     * @return list<int>
     */
    public function selfAndDescendantIds(int $websiteId, int $categoryId): array
    {
        $categoryId = max(0, $categoryId);
        if ($categoryId <= 0) {
            return [];
        }
        $rows = $this->listRows($websiteId);
        $byParent = [];
        foreach ($rows as $row) {
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            $parentId = max(0, (int)($row[Category::schema_fields_PARENT_ID] ?? 0));
            if ($id <= 0) {
                continue;
            }
            $byParent[$parentId] ??= [];
            $byParent[$parentId][] = $id;
        }

        $out = [$categoryId];
        $stack = [$categoryId];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ($byParent[$current] ?? [] as $childId) {
                $out[] = $childId;
                $stack[] = $childId;
            }
        }

        return array_values(array_unique($out));
    }

    public function resolvePublicUrl(string $slug): string
    {
        return BlogNamespace::categoryPublicPath($slug);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function enrichedRows(int $websiteId, string $locale, bool $includeGlobal = true): array
    {
        $rows = $this->listRows($websiteId, $includeGlobal);
        $ids = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row[Category::schema_fields_ID] ?? 0),
            $rows,
        )));
        $attributes = $this->categoryAttributes->readDisplayMaps($websiteId, $ids, $locale);
        $names = $attributes['name'] ?? [];
        $images = $attributes['image'] ?? [];
        $banners = $attributes['banner'] ?? [];
        $summaries = $attributes['summary'] ?? [];
        $descriptions = $attributes['description'] ?? [];
        $levelById = $this->levelMap($rows);

        $nodes = [];
        foreach ($rows as $row) {
            $categoryId = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            $slug = trim((string)($row[Category::schema_fields_SLUG] ?? ''));
            $fallbackName = trim((string)($row[Category::schema_fields_NAME] ?? ''));
            $parentId = max(0, (int)($row[Category::schema_fields_PARENT_ID] ?? 0));
            $nodes[] = [
                'category_id' => $categoryId,
                'id' => $categoryId,
                'parent_id' => $parentId,
                'pid' => $parentId,
                'path' => $slug !== '' ? '/' . $slug : '',
                'code' => $slug,
                'slug' => $slug,
                'position' => (int)($row[Category::schema_fields_SORT_ORDER] ?? 0),
                'sort_order' => (int)($row[Category::schema_fields_SORT_ORDER] ?? 0),
                'status' => 'active',
                'is_active' => 1,
                'name' => $names[$categoryId]
                    ?? $this->categoryAttributes->resolveFallbackName($fallbackName),
                'image' => (string)($images[$categoryId] ?? ''),
                'banner' => (string)($banners[$categoryId] ?? ''),
                'summary' => (string)($summaries[$categoryId] ?? ''),
                'description' => (string)($descriptions[$categoryId] ?? ''),
                'level' => $levelById[$categoryId] ?? 1,
                'nodes' => [],
            ];
        }

        return $nodes;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function levelMap(array $rows): array
    {
        $parentById = [];
        foreach ($rows as $row) {
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $parentById[$id] = max(0, (int)($row[Category::schema_fields_PARENT_ID] ?? 0));
        }

        $levels = [];
        foreach ($parentById as $id => $parentId) {
            $depth = 1;
            $cursor = $parentId;
            $guard = 0;
            while ($cursor > 0 && $guard < self::MAX_DEPTH + 2) {
                ++$depth;
                $cursor = $parentById[$cursor] ?? 0;
                ++$guard;
            }
            $levels[$id] = $depth;
        }

        return $levels;
    }

    private function assertParentAllowed(int $websiteId, int $categoryId, int $parentId): void
    {
        if ($parentId <= 0) {
            return;
        }
        if ($categoryId > 0 && $categoryId === $parentId) {
            throw new \InvalidArgumentException((string)__('不能将自己作为父分类'));
        }

        $parent = clone $this->categoryModel;
        $parent->clearData()->reset()->load($parentId);
        if ($parent->getCategoryId() <= 0) {
            throw new \InvalidArgumentException((string)__('父分类不存在'));
        }
        $grandParentId = max(0, (int)$parent->getData(Category::schema_fields_PARENT_ID));
        if ($grandParentId > 0) {
            throw new \InvalidArgumentException((string)__('博客分类最多支持两级'));
        }

        if ($categoryId > 0) {
            $childIds = $this->selfAndDescendantIds($websiteId, $categoryId);
            if (in_array($parentId, $childIds, true)) {
                throw new \InvalidArgumentException((string)__('不能将分类移动到其子孙节点下'));
            }
            if (count($childIds) > 1 && $parentId > 0) {
                throw new \InvalidArgumentException((string)__('含子分类的节点只能放在顶级'));
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return array<string, mixed>|null
     */
    private function findNode(array $nodes, int $categoryId): ?array
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ((int)($node['category_id'] ?? 0) === $categoryId) {
                return $node;
            }
            $found = $this->findNode(
                is_array($node['nodes'] ?? null) ? $node['nodes'] : [],
                $categoryId,
            );
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listRows(int $websiteId, bool $includeGlobal = true): array
    {
        $model = clone $this->categoryModel;
        $query = $model->clearData()->reset();
        if ($websiteId > 0) {
            $ids = $includeGlobal
                ? BlogWebsiteScope::websiteIdsForQuery($websiteId)
                : [$websiteId];
            $query->where(Category::schema_fields_WEBSITE_ID, $ids, 'IN');
        }
        $rows = $query
            ->order(Category::schema_fields_SORT_ORDER, 'ASC')
            ->order(Category::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return is_array($rows) ? array_values(array_filter($rows, static fn($row): bool => is_array($row))) : [];
    }

    private function resolveCode(string $code, string $name): string
    {
        $code = trim(strtolower($code));
        if ($code !== '') {
            return $code;
        }
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '';
        $slug = trim(strtolower($slug), '-');

        return $slug !== '' ? $slug : 'category';
    }
}
