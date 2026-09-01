<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Model\Category;
use Weline\Framework\Runtime\RequestContext;

/**
 * Blog categories exposed to storefront search type scopes (under type=blog).
 */
final class BlogSearchCategoryScopeService
{
    public function __construct(
        private readonly Category $categoryModel,
        private readonly BlogCategoryAttributeService $categoryAttributes,
    ) {
    }

    /**
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>
     */
    public function listForSearch(?int $websiteId = null, string $locale = ''): array
    {
        $websiteId = $websiteId ?? max(0, (int)(RequestContext::websiteId() ?? 0));
        $fromDb = $this->listFromDatabase($websiteId, $locale);
        if ($fromDb !== []) {
            return $fromDb;
        }

        return $this->demoScopes();
    }

    /**
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>
     */
    private function listFromDatabase(int $websiteId, string $locale = ''): array
    {
        $model = clone $this->categoryModel;
        $query = $model->clearData()->reset();
        $websiteIds = BlogWebsiteScope::websiteIdsForQuery($websiteId);
        $query->where(Category::schema_fields_WEBSITE_ID, $websiteIds, 'IN');
        $rows = $query
            ->order(Category::schema_fields_SORT_ORDER, 'ASC')
            ->order(Category::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $ids = array_values(array_filter(array_map(
            static fn($row): int => is_array($row) ? (int)($row[Category::schema_fields_ID] ?? 0) : 0,
            $rows,
        )));
        $localizedNames = $this->categoryAttributes->readNameMap($websiteId, $ids, $locale);

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = max(0, (int)($row[Category::schema_fields_ID] ?? 0));
            if ($id <= 0) {
                continue;
            }
            $fallbackName = trim((string)($row[Category::schema_fields_NAME] ?? ''));
            $slug = trim((string)($row[Category::schema_fields_SLUG] ?? ''));
            $label = $localizedNames[$id]
                ?? $this->categoryAttributes->resolveDisplayName($websiteId, $id, $locale, $fallbackName);
            $out[] = [
                'code' => 'blog_category_' . $id,
                'label' => $label !== '' ? $label : ($slug !== '' ? $slug : (string)__('未命名分类')),
                'params' => ['category_id' => $id],
                'children' => [],
            ];
        }

        return $out;
    }

    /**
     * Demo scopes when no blog categories exist yet (sidebar parity with Product).
     *
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>
     */
    private function demoScopes(): array
    {
        $items = [
            ['id' => 9101, 'slug' => 'tech', 'label' => (string)__('技术分享')],
            ['id' => 9102, 'slug' => 'product-news', 'label' => (string)__('产品动态')],
            ['id' => 9103, 'slug' => 'company', 'label' => (string)__('公司新闻')],
        ];
        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'code' => 'demo_blog_' . $item['slug'],
                'label' => $item['label'],
                'params' => [
                    'category_id' => $item['id'],
                    'is_demo' => 1,
                ],
                'children' => [],
            ];
        }

        return $out;
    }
}
