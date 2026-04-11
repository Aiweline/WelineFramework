<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Test\Unit\Service;

use GuoLaiRen\Blog\Service\BlogPageResolver;
use GuoLaiRen\PageBuilder\Model\Page;
use PHPUnit\Framework\TestCase;

final class BlogPageResolverTest extends TestCase
{
    public function testGetDetailPagePrefersDetailPageSharingBlogListHeaderFooterSource(): void
    {
        $resolver = $this->createResolver([
            $this->pageRow(42, Page::TYPE_HOME, 1, 0),
            $this->pageRow(49, Page::TYPE_BLOG_LIST, 1, 42),
            $this->pageRow(51, Page::TYPE_BLOG, 1, 42),
            $this->pageRow(52, Page::TYPE_HOME, 1, 0),
            $this->pageRow(61, Page::TYPE_BLOG, 1, 52),
        ]);

        $page = $resolver->getDetailPage(1);

        $this->assertInstanceOf(Page::class, $page);
        $this->assertSame(51, $page?->getId());
    }

    public function testGetCategoryPagePrefersCategoryPageSharingBlogListHeaderFooterSource(): void
    {
        $resolver = $this->createResolver([
            $this->pageRow(42, Page::TYPE_HOME, 1, 0),
            $this->pageRow(49, Page::TYPE_BLOG_LIST, 1, 42),
            $this->pageRow(50, Page::TYPE_BLOG_CATEGORY, 1, 42),
            $this->pageRow(52, Page::TYPE_HOME, 1, 0),
            $this->pageRow(60, Page::TYPE_BLOG_CATEGORY, 1, 52),
        ]);

        $page = $resolver->getCategoryPage(1);

        $this->assertInstanceOf(Page::class, $page);
        $this->assertSame(50, $page?->getId());
    }

    public function testGetDetailPageFallsBackToSiteBlogListBeforeGlobalDetailPage(): void
    {
        $resolver = $this->createResolver([
            $this->pageRow(42, Page::TYPE_HOME, 1, 0),
            $this->pageRow(49, Page::TYPE_BLOG_LIST, 1, 42),
            $this->pageRow(301, Page::TYPE_BLOG, 0, 0),
        ]);

        $page = $resolver->getDetailPage(1);

        $this->assertInstanceOf(Page::class, $page);
        $this->assertSame(49, $page?->getId());
    }

    public function testResolveThemeWebsiteIdPrefersCurrentRequestWebsite(): void
    {
        $resolver = $this->createResolver([]);

        $this->assertSame(88, $resolver->resolveThemeWebsiteId(88, 12));
    }

    public function testResolveThemeWebsiteIdFallsBackToContentWebsiteWhenRequestWebsiteMissing(): void
    {
        $resolver = $this->createResolver([]);

        $this->assertSame(12, $resolver->resolveThemeWebsiteId(0, 12));
        $this->assertSame(0, $resolver->resolveThemeWebsiteId(0, -7));
    }

    public function testResolveContentWebsiteIdPrefersResolvedBlogPageWebsite(): void
    {
        $resolver = $this->createResolver([
            $this->pageRow(49, Page::TYPE_BLOG_LIST, 66, 42),
        ]);

        $page = $resolver->getListPage(66);

        $this->assertSame(66, $resolver->resolveContentWebsiteId($page, 12, 9));
    }

    public function testResolveContentWebsiteIdFallsBackToRequestThenExplicitFallback(): void
    {
        $resolver = $this->createResolver([]);

        $this->assertSame(12, $resolver->resolveContentWebsiteId(null, 12, 9));
        $this->assertSame(9, $resolver->resolveContentWebsiteId(null, 0, 9));
        $this->assertSame(0, $resolver->resolveContentWebsiteId(null, 0, -9));
    }

    public function testGetDetailPageDoesNotFallBackToGlobalPagesInStrictMode(): void
    {
        $resolver = $this->createResolver([
            $this->pageRow(301, Page::TYPE_BLOG, 0, 0),
            $this->pageRow(302, Page::TYPE_BLOG_LIST, 0, 0),
        ]);

        $page = $resolver->getDetailPage(66, false);

        $this->assertNull($page);
    }

    public function testGetListPageDoesNotFallBackToGlobalPagesInStrictMode(): void
    {
        $resolver = $this->createResolver([
            $this->pageRow(302, Page::TYPE_BLOG_LIST, 0, 0),
        ]);

        $page = $resolver->getListPage(66, false);

        $this->assertNull($page);
    }

    /**
     * @param list<array<string, int|string|null>> $rows
     */
    private function createResolver(array $rows): BlogPageResolver
    {
        $registry = new FakeBlogPageRegistry();
        $records = [];

        foreach ($rows as $row) {
            $record = new FakeBlogPageRecord($row, $registry);
            $registry->register($record);
            $records[] = $record;
        }

        return new BlogPageResolver(new FakeBlogPageCollection($records));
    }

    /**
     * @return array<string, int|string|null>
     */
    private function pageRow(int $pageId, string $type, int $websiteId, int $parentId): array
    {
        return [
            Page::schema_fields_ID => $pageId,
            Page::schema_fields_TYPE => $type,
            Page::schema_fields_WEBSITE_ID => $websiteId,
            Page::schema_fields_PARENT_ID => $parentId,
            Page::schema_fields_STATUS => Page::STATUS_PUBLISHED,
            Page::schema_fields_LAYOUT_PAGE_ID => null,
            Page::schema_fields_HANDLE => 'page-' . $pageId,
            Page::schema_fields_TITLE => 'Page ' . $pageId,
        ];
    }
}

final class FakeBlogPageCollection extends Page
{
    /**
     * @param list<FakeBlogPageRecord> $records
     */
    public function __construct(
        private readonly array $records
    ) {
    }

    /** @var list<array{field: string, value: mixed, condition: string}> */
    private array $filters = [];

    /** @var list<array{field: string, direction: string}> */
    private array $orders = [];

    public function clear(bool $with_query = true): static
    {
        $this->filters = [];
        $this->orders = [];

        return $this;
    }

    public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
    {
        if (is_string($field)) {
            $this->filters[] = [
                'field' => $field,
                'value' => $value,
                'condition' => strtoupper($condition),
            ];
        }

        return $this;
    }

    public function order(string $field, string $direction = 'ASC'): static
    {
        $this->orders[] = [
            'field' => $field,
            'direction' => strtoupper($direction),
        ];

        return $this;
    }

    public function select(string $fields = ''): static
    {
        return $this;
    }

    public function fetch(): static
    {
        return $this;
    }

    /**
     * @return list<FakeBlogPageRecord>
     */
    public function getItems(): array
    {
        $items = array_values(array_filter(
            $this->records,
            function (FakeBlogPageRecord $record): bool {
                foreach ($this->filters as $filter) {
                    $actual = $record->getData($filter['field']);
                    $expected = $filter['value'];

                    if ($filter['condition'] === 'IN') {
                        if (!is_array($expected) || !in_array($actual, $expected, true)) {
                            return false;
                        }
                        continue;
                    }

                    if ($actual !== $expected) {
                        return false;
                    }
                }

                return true;
            }
        ));

        if ($this->orders !== []) {
            usort($items, function (FakeBlogPageRecord $left, FakeBlogPageRecord $right): int {
                foreach ($this->orders as $order) {
                    $leftValue = $left->getData($order['field']);
                    $rightValue = $right->getData($order['field']);
                    if ($leftValue === $rightValue) {
                        continue;
                    }

                    $comparison = $leftValue <=> $rightValue;

                    return $order['direction'] === 'DESC' ? -$comparison : $comparison;
                }

                return 0;
            });
        }

        return $items;
    }
}

final class FakeBlogPageRegistry
{
    /** @var array<int, FakeBlogPageRecord> */
    private array $records = [];

    public function register(FakeBlogPageRecord $record): void
    {
        $this->records[$record->getId()] = $record;
    }

    public function get(int $pageId): ?FakeBlogPageRecord
    {
        return $this->records[$pageId] ?? null;
    }
}

final class FakeBlogPageRecord extends Page
{
    /**
     * @param array<string, int|string|null> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly FakeBlogPageRegistry $registry
    ) {
    }

    public function getId(mixed $default = 0): mixed
    {
        return (int)($this->data[Page::schema_fields_ID] ?? $default);
    }

    public function getData(string $key = '', $index = null): mixed
    {
        if ($key === '') {
            return $this->data;
        }

        return $this->data[$key] ?? null;
    }

    public function getParentPage(): ?Page
    {
        $parentId = (int)($this->data[Page::schema_fields_PARENT_ID] ?? 0);
        if ($parentId <= 0) {
            return null;
        }

        return $this->registry->get($parentId);
    }

    public function getHeaderFooterInheritSourcePage(): Page
    {
        return $this->getParentPage() ?: $this;
    }
}
