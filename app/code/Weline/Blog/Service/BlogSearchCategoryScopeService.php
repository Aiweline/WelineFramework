<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Framework\Runtime\RequestContext;

/**
 * Blog categories exposed to storefront search type scopes (under type=blog).
 */
final class BlogSearchCategoryScopeService
{
    public function __construct(
        private readonly BlogCategoryAdminService $categoryAdmin,
    ) {
    }

    /**
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>
     */
    public function listForSearch(?int $websiteId = null, string $locale = ''): array
    {
        $websiteId = $websiteId ?? max(0, (int)(RequestContext::websiteId() ?? 0));
        @file_put_contents(
            BP . 'dev/tmp/blog-search-scope.log',
            date('c') . ' websiteId=' . $websiteId
                . ' code=' . (string)RequestContext::getWelineWebsiteCode()
                . "\n",
            FILE_APPEND
        );
        $fromDb = $this->listFromDatabase($websiteId, $locale);
        if ($fromDb !== []) {
            return $fromDb;
        }

        // Non-default storefronts must not inherit default-site (website_id=0) blog
        // taxonomies or generic demos — that leaks Hanfu Guide etc. onto DaoCharms.
        if ($websiteId > 0) {
            return [];
        }

        return $this->demoScopes();
    }

    /**
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>
     */
    private function listFromDatabase(int $websiteId, string $locale = ''): array
    {
        // Search type dropdown is brand-facing: only categories owned by this website.
        // Admin/content trees may still merge website_id=0 via BlogWebsiteScope.
        $tree = $websiteId > 0
            ? $this->categoryAdmin->treeOwnedOnly($websiteId, $locale)
            : $this->categoryAdmin->tree($websiteId, $locale);
        if ($tree === []) {
            return [];
        }

        return $this->mapTree($tree);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array{code:string,label:string,params:array<string,int|string|float|bool>,children:list<array<string,mixed>>}>
     */
    private function mapTree(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = max(0, (int)($node['category_id'] ?? 0));
            if ($id <= 0) {
                continue;
            }
            $label = trim((string)($node['name'] ?? ''));
            $slug = trim((string)($node['slug'] ?? $node['code'] ?? ''));
            $children = $this->mapTree(is_array($node['nodes'] ?? null) ? $node['nodes'] : []);
            $out[] = [
                'code' => 'blog_category_' . $id,
                'label' => $label !== '' ? $label : ($slug !== '' ? $slug : (string)__('未命名分类')),
                'params' => ['category_id' => $id],
                'children' => $children,
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
