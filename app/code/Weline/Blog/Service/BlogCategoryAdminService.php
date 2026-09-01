<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Model\Category;

/**
 * Flat blog category CRUD for Catalog space=blog.
 */
final class BlogCategoryAdminService
{
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
        $rows = $this->listRows($websiteId);
        $ids = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row[Category::schema_fields_ID] ?? 0),
            $rows,
        )));
        $names = $this->categoryAttributes->readNameMap($websiteId, $ids, $locale);
        $images = $this->categoryAttributes->readImageMap($websiteId, $ids, $locale);
        $banners = $this->categoryAttributes->readBannerMap($websiteId, $ids, $locale);
        $summaries = $this->categoryAttributes->readSummaryMap($websiteId, $ids, $locale);
        $descriptions = $this->categoryAttributes->readDescriptionMap($websiteId, $ids, $locale);

        $nodes = [];
        foreach ($rows as $row) {
            $categoryId = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            $slug = trim((string)($row[Category::schema_fields_SLUG] ?? ''));
            $fallbackName = trim((string)($row[Category::schema_fields_NAME] ?? ''));
            $nodes[] = [
                'category_id' => $categoryId,
                'id' => $categoryId,
                'parent_id' => 0,
                'pid' => 0,
                'path' => $slug !== '' ? '/' . $slug : '',
                'code' => $slug,
                'slug' => $slug,
                'position' => (int)($row[Category::schema_fields_SORT_ORDER] ?? 0),
                'sort_order' => (int)($row[Category::schema_fields_SORT_ORDER] ?? 0),
                'status' => 'active',
                'is_active' => 1,
                'name' => $names[$categoryId]
                    ?? $this->categoryAttributes->resolveDisplayName($websiteId, $categoryId, $locale, $fallbackName),
                'image' => (string)($images[$categoryId] ?? ''),
                'banner' => (string)($banners[$categoryId] ?? ''),
                'summary' => (string)($summaries[$categoryId] ?? ''),
                'description' => (string)($descriptions[$categoryId] ?? ''),
                'level' => 1,
                'nodes' => [],
            ];
        }

        return $nodes;
    }

    /** @return array<string, mixed>|null */
    public function view(int $websiteId, int $categoryId, string $locale = ''): ?array
    {
        foreach ($this->tree($websiteId, $locale) as $node) {
            if ((int)($node['category_id'] ?? 0) === $categoryId) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return array{category_id:int,code:string}
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
    ): array {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException((string)__('分类名称不能为空'));
        }
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
            $model->setData(Category::schema_fields_SORT_ORDER, $sortOrder);
            $model->setData(Category::schema_fields_UPDATED_AT, $now);
            $model->save();
        } else {
            $model->clearData()->reset();
            $model->setData(Category::schema_fields_WEBSITE_ID, $websiteId);
            $model->setData(Category::schema_fields_SLUG, $slug);
            $model->setData(Category::schema_fields_NAME, $name);
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
            'image' => $image !== null ? trim($image) : null,
            'banner' => $banner !== null ? trim($banner) : null,
            'summary' => $summary !== null ? trim($summary) : null,
            'description' => $description !== null ? trim($description) : null,
        ];
    }

    public function delete(int $websiteId, int $categoryId): void
    {
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
        unset($websiteId, $parentId, $level);
        if ($categoryId <= 0) {
            throw new \InvalidArgumentException((string)__('分类 ID 不能为空'));
        }
        $model = clone $this->categoryModel;
        $model->clearData()->reset()->load($categoryId);
        if ($model->getCategoryId() <= 0) {
            throw new \InvalidArgumentException((string)__('分类不存在'));
        }
        $model->setData(Category::schema_fields_SORT_ORDER, max(0, $position));
        $model->setData(Category::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
        $model->save();

        return [
            'id' => $categoryId,
            'pid' => 0,
            'level' => 1,
            'position' => max(0, $position),
        ];
    }

    public function resolvePublicUrl(string $slug): string
    {
        return BlogNamespace::categoryPublicPath($slug);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listRows(int $websiteId): array
    {
        $model = clone $this->categoryModel;
        $query = $model->clearData()->reset();
        if ($websiteId > 0) {
            $query->where(Category::schema_fields_WEBSITE_ID, BlogWebsiteScope::websiteIdsForQuery($websiteId), 'IN');
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
