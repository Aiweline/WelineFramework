<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;

/**
 * Lightweight Page-compatible object for rendering AI generated components
 * before the draft pages are materialized into real Page records.
 */
class PreviewPageStub
{
    /** @var array<string,mixed> */
    private array $data = [];

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data = [])
    {
        $pageType = (string)($data[Page::schema_fields_TYPE] ?? $data['type'] ?? Page::TYPE_HOME);
        $title = (string)($data[Page::schema_fields_TITLE] ?? $data['title'] ?? 'Preview Page');
        $handle = (string)($data[Page::schema_fields_HANDLE] ?? $data['handle'] ?? Page::getDefaultHandleForType($pageType));
        $now = \date('Y-m-d H:i:s');

        $this->data = \array_replace([
            Page::schema_fields_ID => 0,
            Page::schema_fields_WEBSITE_ID => 0,
            Page::schema_fields_TYPE => $pageType,
            Page::schema_fields_HANDLE => $handle,
            Page::schema_fields_NAME => $title,
            Page::schema_fields_TITLE => $title,
            Page::schema_fields_CONTENT => '',
            Page::schema_fields_STYLE => 'default',
            Page::schema_fields_STYLE_SETTING => [],
            Page::schema_fields_LAYOUT_CONFIG => [],
            Page::schema_fields_META_TITLE => $title,
            Page::schema_fields_META_DESCRIPTION => '',
            Page::schema_fields_META_KEYWORDS => '',
            Page::schema_fields_LOGO => '',
            Page::schema_fields_ICON => '',
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
            Page::schema_fields_CREATE_TIME => $now,
            Page::schema_fields_UPDATE_TIME => $now,
            'navigation_pages' => [],
            'header_navigation_pages' => [],
            'blog_posts' => [],
            'blog_categories' => [],
            'home_page_config' => [],
        ], $data);
    }

    /**
     * @return mixed|array<string,mixed>
     */
    public function getData(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->data;
        }

        return \array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    /**
     * @param string|array<string,mixed> $key
     */
    public function setData(string|array $key, mixed $value = null): self
    {
        if (\is_array($key)) {
            foreach ($key as $dataKey => $dataValue) {
                $this->data[(string)$dataKey] = $dataValue;
            }
            return $this;
        }

        $this->data[$key] = $value;
        return $this;
    }

    public function getId(): int
    {
        return (int)($this->data[Page::schema_fields_ID] ?? $this->data['id'] ?? 0);
    }

    public function getTitle(): string
    {
        return (string)($this->data[Page::schema_fields_TITLE] ?? $this->data['title'] ?? 'Preview Page');
    }

    public function getDescription(): string
    {
        return (string)($this->data[Page::schema_fields_META_DESCRIPTION] ?? $this->data['description'] ?? '');
    }

    public function getUrl(): string
    {
        $url = \trim((string)($this->data['url'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        $type = (string)($this->data[Page::schema_fields_TYPE] ?? Page::TYPE_HOME);
        $handle = (string)($this->data[Page::schema_fields_HANDLE] ?? Page::getDefaultHandleForType($type));

        return $type === Page::TYPE_HOME || $handle === '' ? '/' : '/' . \ltrim($handle, '/');
    }

    /**
     * @param list<string> $excludeTypes
     * @return list<array<string,mixed>>
     */
    public function getNavigationPages(array $excludeTypes = [], int $limit = 10): array
    {
        $pages = $this->normalizeList($this->data['navigation_pages'] ?? []);
        if ($pages === []) {
            $pages = $this->normalizeList($this->data['header_navigation_pages'] ?? []);
        }

        if ($excludeTypes !== []) {
            $exclude = \array_fill_keys($excludeTypes, true);
            $pages = \array_values(\array_filter($pages, static function (array $page) use ($exclude): bool {
                $type = (string)($page['type'] ?? '');
                return $type === '' || !isset($exclude[$type]);
            }));
        }

        return \array_slice($pages, 0, \max(0, $limit));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getHeaderNavigationPages(int $limit = 10): array
    {
        $pages = $this->normalizeList($this->data['header_navigation_pages'] ?? []);
        if ($pages === []) {
            $pages = $this->getNavigationPages([], $limit);
        }

        return \array_slice($pages, 0, \max(0, $limit));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getBlogPosts(int $limit = 10, string $orderBy = 'published_at', string $orderDir = 'DESC'): array
    {
        return \array_slice($this->normalizeList($this->data['blog_posts'] ?? []), 0, \max(0, $limit));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getBlogCategories(): array
    {
        return $this->normalizeList($this->data['blog_categories'] ?? []);
    }

    /**
     * @return array<string,mixed>
     */
    public function getHomePageConfig(): array
    {
        return \is_array($this->data['home_page_config'] ?? null) ? $this->data['home_page_config'] : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function getStyleSetting(): array
    {
        return $this->decodeArrayValue($this->data[Page::schema_fields_STYLE_SETTING] ?? []);
    }

    /**
     * @return array<string,mixed>
     */
    public function getLayoutConfig(): array
    {
        return $this->decodeArrayValue($this->data[Page::schema_fields_LAYOUT_CONFIG] ?? []);
    }

    public function getHomePage(?int $websiteId = null, bool $publishedOnly = true): ?self
    {
        return $this;
    }

    public function getLayoutPageId(): ?int
    {
        $layoutPageId = (int)($this->data[Page::schema_fields_LAYOUT_PAGE_ID] ?? 0);
        return $layoutPageId > 0 ? $layoutPageId : null;
    }

    public function getLayoutPage(): ?self
    {
        return null;
    }

    public function isBlogType(): bool
    {
        return \in_array((string)($this->data[Page::schema_fields_TYPE] ?? ''), \array_keys(Page::getBlogPageTypes()), true);
    }

    public function getTypeName(): string
    {
        $type = (string)($this->data[Page::schema_fields_TYPE] ?? '');
        $labels = Page::getPageTypes();

        return isset($labels[$type]) ? (string)$labels[$type] : $type;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getChildren(): array
    {
        return $this->getNavigationPages();
    }

    public function hasChildren(): bool
    {
        return $this->getNavigationPages() !== [];
    }

    public function __call(string $method, array $args): mixed
    {
        if (\str_starts_with($method, 'get')) {
            $key = \strtolower((string)\preg_replace('/(?<!^)[A-Z]/', '_$0', \substr($method, 3)));
            if ($key !== '') {
                return $this->getData($key, $this->defaultGetterFallback($method));
            }
            return $this->defaultGetterFallback($method);
        }

        if (\str_starts_with($method, 'is') || \str_starts_with($method, 'has')) {
            return false;
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private function decodeArrayValue(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (\is_string($value) && \trim($value) !== '') {
            $decoded = \json_decode($value, true);
            return \is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param mixed $value
     * @return list<array<string,mixed>>
     */
    private function normalizeList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function defaultGetterFallback(string $method): mixed
    {
        foreach (['Pages', 'Posts', 'Categories', 'Items', 'List', 'Config', 'Setting'] as $arraySuffix) {
            if (\str_ends_with($method, $arraySuffix)) {
                return [];
            }
        }

        return '';
    }
}
