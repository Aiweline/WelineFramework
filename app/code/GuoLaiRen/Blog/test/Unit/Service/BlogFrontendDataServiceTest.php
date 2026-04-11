<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Test\Unit\Service;

use GuoLaiRen\Blog\Model\Category;
use GuoLaiRen\Blog\Model\Post;
use GuoLaiRen\Blog\Service\BlogFrontendDataService;
use GuoLaiRen\Blog\Service\BlogPageResolver;
use GuoLaiRen\PageBuilder\Model\Page;
use PHPUnit\Framework\TestCase;

final class BlogFrontendDataServiceTest extends TestCase
{
    public function testGetListViewDataReturnsOnlyPublishedPostsFromCurrentSite(): void
    {
        $service = $this->createService(
            [
                $this->postRow(1, 66, 9, 'site-66-published', Post::STATUS_PUBLISHED, '2026-04-01 10:00:00'),
                $this->postRow(2, 66, 9, 'site-66-draft', Post::STATUS_DRAFT, '2026-04-02 10:00:00'),
                $this->postRow(3, 77, 9, 'site-77-published', Post::STATUS_PUBLISHED, '2026-04-03 10:00:00'),
            ],
            [
                $this->categoryRow(9, 66, 'Current Site Category'),
                $this->categoryRow(10, 77, 'Other Site Category'),
            ]
        );

        $data = $service->getListViewData(66, 1, 12);

        $this->assertSame(['site-66-published'], array_column($data['blog_posts'], 'slug'));
        $this->assertSame(['Current Site Category'], array_column($data['blog_categories'], 'name'));
        $this->assertSame(['site-66-published'], array_column($data['recent_posts'], 'slug'));
    }

    public function testGetDetailViewDataReturnsNullWhenSlugBelongsToDifferentSite(): void
    {
        $service = $this->createService(
            [
                $this->postRow(1, 77, 9, 'shared-slug', Post::STATUS_PUBLISHED, '2026-04-01 10:00:00'),
            ],
            [
                $this->categoryRow(9, 77, 'Other Site Category'),
            ]
        );

        $this->assertNull($service->getDetailViewData(66, 'shared-slug'));
    }

    /**
     * @param list<array<string, int|string|null>> $postRows
     * @param list<array<string, int|string|null>> $categoryRows
     */
    private function createService(array $postRows, array $categoryRows): BlogFrontendDataService
    {
        $posts = array_map(static fn (array $row): FakeFrontendPostRecord => new FakeFrontendPostRecord($row), $postRows);
        $categories = array_map(static fn (array $row): FakeFrontendCategoryRecord => new FakeFrontendCategoryRecord($row), $categoryRows);

        return new BlogFrontendDataService(
            new FakeFrontendPostCollection($posts),
            new FakeFrontendCategoryCollection($categories),
            new BlogPageResolver(new FakeFrontendPageCollection())
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    private function postRow(
        int $postId,
        int $siteId,
        int $categoryId,
        string $slug,
        int $status,
        string $publishedAt
    ): array {
        return [
            Post::schema_fields_ID => $postId,
            Post::schema_fields_SITE_ID => $siteId,
            Post::schema_fields_CATEGORY_ID => $categoryId,
            Post::schema_fields_SLUG => $slug,
            Post::schema_fields_TITLE => 'Post ' . $postId,
            Post::schema_fields_SUMMARY => 'Summary ' . $postId,
            Post::schema_fields_CONTENT => 'Content ' . $postId,
            Post::schema_fields_COVER_IMAGE => '/media/' . $postId . '.jpg',
            Post::schema_fields_AUTHOR => 'Author ' . $postId,
            Post::schema_fields_TAGS => 'alpha,beta',
            Post::schema_fields_VIEW_COUNT => 5,
            Post::schema_fields_STATUS => $status,
            Post::schema_fields_IS_FEATURED => 0,
            Post::schema_fields_PUBLISHED_AT => $publishedAt,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function categoryRow(int $categoryId, int $siteId, string $name): array
    {
        return [
            Category::schema_fields_ID => $categoryId,
            Category::schema_fields_SITE_ID => $siteId,
            Category::schema_fields_NAME => $name,
            Category::schema_fields_SLUG => 'cat-' . $categoryId,
            Category::schema_fields_DESCRIPTION => 'Description ' . $categoryId,
            Category::schema_fields_COVER_IMAGE => '',
            Category::schema_fields_STATUS => Category::STATUS_ENABLED,
            Category::schema_fields_SORT_ORDER => $categoryId,
            Category::schema_fields_META_TITLE => '',
            Category::schema_fields_META_DESCRIPTION => '',
        ];
    }
}

final class FakeFrontendPostCollection extends Post
{
    /**
     * @param list<FakeFrontendPostRecord> $records
     */
    public function __construct(
        private readonly array $records = []
    ) {
    }

    /** @var list<array{field:string,value:mixed,condition:string}> */
    private array $filters = [];

    /** @var list<array{field:string,direction:string}> */
    private array $orders = [];

    private int $limitValue = 0;
    private int $pageNumber = 1;
    private int $pageSize = 0;
    public array $pagination = [];
    public string $paginationHtml = '';

    public function clear(bool $with_query = true): static
    {
        $this->filters = [];
        $this->orders = [];
        $this->limitValue = 0;
        $this->pageNumber = 1;
        $this->pageSize = 0;
        $this->pagination = [];

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

    public function limit(int $limit): static
    {
        $this->limitValue = max(0, $limit);

        return $this;
    }

    public function page(int $page = 1, int $pageSize = 10, array $params = [], int $max_limit = 99999, ?int $total = null): static
    {
        $this->pageNumber = max(1, $page);
        $this->pageSize = max(1, $pageSize);

        return $this;
    }

    public function pagination(int $page = 1, int $pageSize = 10, array $params = [], int $max_limit = 99999, ?int $total = null): static
    {
        $this->pageNumber = max(1, $page);
        $this->pageSize = max(1, $pageSize);

        return $this;
    }

    public function select(string $fields = ''): static
    {
        return $this;
    }

    public function find(): static
    {
        return $this;
    }

    public function fetch(): static
    {
        $items = $this->filteredRecords();
        $total = count($items);

        if ($this->pageSize > 0) {
            $offset = ($this->pageNumber - 1) * $this->pageSize;
            $items = array_slice($items, $offset, $this->pageSize);
            $this->pagination = [
                'current_page' => $this->pageNumber,
                'page_size' => $this->pageSize,
                'total' => $total,
            ];
            $this->paginationHtml = json_encode($this->pagination, JSON_UNESCAPED_UNICODE) ?: '';
        } elseif ($this->limitValue > 0) {
            $items = array_slice($items, 0, $this->limitValue);
        }

        $this->matchedItems = array_values($items);

        return $this;
    }

    /** @var list<FakeFrontendPostRecord> */
    private array $matchedItems = [];

    /**
     * @return list<FakeFrontendPostRecord>
     */
    public function getItems(): array
    {
        return $this->matchedItems;
    }

    public function getPagination(string $pagination_style = 'pagination-rounded', string $url_path = '', bool $use_backend_url = false): string
    {
        return $this->paginationHtml;
    }

    /**
     * @return list<FakeFrontendPostRecord>
     */
    private function filteredRecords(): array
    {
        $items = array_values(array_filter(
            $this->records,
            function (FakeFrontendPostRecord $record): bool {
                foreach ($this->filters as $filter) {
                    $actual = $record->getData($filter['field']);
                    $expected = $filter['value'];

                    if ($filter['condition'] === '!=') {
                        if ($actual === $expected) {
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
            usort($items, function (FakeFrontendPostRecord $left, FakeFrontendPostRecord $right): int {
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

final class FakeFrontendPostRecord extends Post
{
    /**
     * @param array<string, int|string|null> $data
     */
    public function __construct(
        private readonly array $data
    ) {
    }

    public function getId(mixed $default = 0): mixed
    {
        return (int)($this->data[Post::schema_fields_ID] ?? $default);
    }

    public function getData(string $key = '', $index = null): mixed
    {
        if ($key === '') {
            return $this->data;
        }

        return $this->data[$key] ?? null;
    }
}

final class FakeFrontendCategoryCollection extends Category
{
    /**
     * @param list<FakeFrontendCategoryRecord> $records
     */
    public function __construct(
        private readonly array $records = []
    ) {
    }

    /** @var list<array{field:string,value:mixed,condition:string}> */
    private array $filters = [];

    /** @var list<array{field:string,direction:string}> */
    private array $orders = [];

    /** @var list<FakeFrontendCategoryRecord> */
    private array $matchedItems = [];

    public function clear(bool $with_query = true): static
    {
        $this->filters = [];
        $this->orders = [];
        $this->matchedItems = [];

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

    public function find(): static
    {
        return $this;
    }

    public function fetch(): static
    {
        $items = array_values(array_filter(
            $this->records,
            function (FakeFrontendCategoryRecord $record): bool {
                foreach ($this->filters as $filter) {
                    $actual = $record->getData($filter['field']);
                    $expected = $filter['value'];
                    if ($actual !== $expected) {
                        return false;
                    }
                }

                return true;
            }
        ));

        if ($this->orders !== []) {
            usort($items, function (FakeFrontendCategoryRecord $left, FakeFrontendCategoryRecord $right): int {
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

        $this->matchedItems = $items;

        return $this;
    }

    /**
     * @return list<FakeFrontendCategoryRecord>
     */
    public function getItems(): array
    {
        return $this->matchedItems;
    }
}

final class FakeFrontendCategoryRecord extends Category
{
    /**
     * @param array<string, int|string|null> $data
     */
    public function __construct(
        private readonly array $data
    ) {
    }

    public function getId(mixed $default = 0): mixed
    {
        return (int)($this->data[Category::schema_fields_ID] ?? $default);
    }

    public function getData(string $key = '', $index = null): mixed
    {
        if ($key === '') {
            return $this->data;
        }

        return $this->data[$key] ?? null;
    }
}

final class FakeFrontendPageCollection extends Page
{
    public function clear(bool $with_query = true): static
    {
        return $this;
    }

    public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
    {
        return $this;
    }

    public function order(string $field, string $direction = 'ASC'): static
    {
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

    public function getItems(): array
    {
        return [];
    }
}
