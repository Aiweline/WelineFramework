<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;

/**
 * 预览用页面桩：无数据库依赖，为组件预览提供 getHeaderNavigationPages / getNavigationPages 等示例数据。
 * 用于 ComponentService::renderPreview() 中 assign('page', stub)，避免 nav/footer 等组件在预览时为空。
 */
class PreviewPageStub
{
    private array $data = [];

    public function __construct(array $data = [])
    {
        $this->data = array_merge([
            'style' => 'tpmst',
            'title' => 'Preview',
            'meta_title' => 'Preview',
            'logo' => '',
            'icon' => '',
        ], $data);
    }

    public function getData(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /**
     * 页头导航示例数据（与 Page::getHeaderMenuTypes 顺序一致）
     */
    public function getHeaderNavigationPages(int $limit = 10): array
    {
        $customItems = $this->data['header_navigation_pages'] ?? null;
        if (\is_array($customItems) && $customItems !== []) {
            return \array_slice(\array_values($customItems), 0, $limit);
        }

        $items = [
            ['title' => __('Home'), 'handle' => '', 'url' => '/', 'type' => Page::TYPE_HOME, 'page_id' => 1],
            ['title' => __('About Us'), 'handle' => 'about', 'url' => '/about', 'type' => Page::TYPE_ABOUT, 'page_id' => 2],
            ['title' => __('Blog'), 'handle' => 'blog', 'url' => '/blog', 'type' => Page::TYPE_BLOG_LIST, 'page_id' => 3],
            ['title' => __('Contact'), 'handle' => 'contact', 'url' => '/contact', 'type' => Page::TYPE_CONTACT, 'page_id' => 4],
            ['title' => __('Terms'), 'handle' => 'terms', 'url' => '/terms', 'type' => Page::TYPE_TERMS_OF_SERVICE, 'page_id' => 5],
            ['title' => __('Privacy Policy'), 'handle' => 'privacy', 'url' => '/privacy', 'type' => Page::TYPE_PRIVACY_POLICY, 'page_id' => 6],
        ];
        return array_slice($items, 0, $limit);
    }

    /**
     * 页脚导航示例数据（排除单篇博客）
     */
    public function getNavigationPages(array $excludeTypes = [], int $limit = 30): array
    {
        $customItems = $this->data['navigation_pages'] ?? null;
        if (\is_array($customItems) && $customItems !== []) {
            $items = \array_values($customItems);
            if (!empty($excludeTypes)) {
                $items = \array_values(\array_filter(
                    $items,
                    static fn(array $row): bool => !\in_array((string)($row['type'] ?? ''), $excludeTypes, true)
                ));
            }

            return \array_slice($items, 0, $limit);
        }

        $items = [
            ['title' => __('Home'), 'handle' => '', 'url' => '/', 'type' => Page::TYPE_HOME, 'page_id' => 1],
            ['title' => __('About Us'), 'handle' => 'about', 'url' => '/about', 'type' => Page::TYPE_ABOUT, 'page_id' => 2],
            ['title' => __('Blog'), 'handle' => 'blog', 'url' => '/blog', 'type' => Page::TYPE_BLOG_LIST, 'page_id' => 3],
            ['title' => __('Contact'), 'handle' => 'contact', 'url' => '/contact', 'type' => Page::TYPE_CONTACT, 'page_id' => 4],
            ['title' => __('Terms'), 'handle' => 'terms', 'url' => '/terms', 'type' => Page::TYPE_TERMS_OF_SERVICE, 'page_id' => 5],
            ['title' => __('Privacy Policy'), 'handle' => 'privacy', 'url' => '/privacy', 'type' => Page::TYPE_PRIVACY_POLICY, 'page_id' => 6],
        ];
        if (!empty($excludeTypes)) {
            $items = array_values(array_filter($items, static fn($row) => !in_array($row['type'] ?? '', $excludeTypes, true)));
        }
        return array_slice($items, 0, $limit);
    }

    public function getBlogPosts(int $limit = 10, string $orderBy = 'published_at', string $orderDir = 'DESC'): array
    {
        $posts = $this->data['blog_posts'] ?? [];
        return \is_array($posts) ? \array_slice(\array_values($posts), 0, $limit) : [];
    }

    public function getBlogCategories(): array
    {
        $categories = $this->data['blog_categories'] ?? [];
        return \is_array($categories) ? \array_values($categories) : [];
    }

    public function getHomePageConfig(): array
    {
        $config = $this->data['home_page_config'] ?? [];
        return \is_array($config) ? $config : [];
    }

    public function isBlogType(): bool
    {
        return \in_array((string)($this->data['type'] ?? ''), [
            Page::TYPE_BLOG,
            Page::TYPE_BLOG_CATEGORY,
            Page::TYPE_BLOG_LIST,
        ], true);
    }
}
