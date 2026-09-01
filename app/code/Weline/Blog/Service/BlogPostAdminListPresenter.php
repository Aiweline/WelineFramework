<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Model\Post;

/**
 * 博客后台列表行展示：封面 URL、合并列、范围标记。
 */
final class BlogPostAdminListPresenter
{
    /**
     * @param array<string,mixed> $post
     * @param array<int,string> $categoryNames
     * @param array<int,string> $websiteLabels
     * @param array<string,string> $statusLabels
     * @return array<string,mixed>
     */
    public function presentRow(
        array $post,
        array $categoryNames,
        array $websiteLabels,
        array $statusLabels,
    ): array {
        $postId = (int)($post[Post::schema_fields_ID] ?? 0);
        $websiteId = (int)($post[Post::schema_fields_WEBSITE_ID] ?? 0);
        $categoryId = (int)($post[Post::schema_fields_CATEGORY_ID] ?? 0);
        $status = (string)($post[Post::schema_fields_STATUS] ?? '');
        $locale = (string)($post[Post::schema_fields_LOCALE] ?? '');
        $title = trim((string)($post[Post::schema_fields_TITLE] ?? ''));
        $slug = trim((string)($post[Post::schema_fields_SLUG] ?? ''));
        $author = trim((string)($post[Post::schema_fields_AUTHOR] ?? ''));
        $categoryName = $categoryNames[$categoryId] ?? '—';
        $statusLabel = $statusLabels[$status] ?? $status;

        $articleHead = $title;
        if ($slug !== '') {
            $articleHead = $title !== '' ? $title . ' · ' . $slug : $slug;
        }

        $metaParts = [];
        if ($locale !== '') {
            $metaParts[] = $locale;
        }
        if ($categoryName !== '—') {
            $metaParts[] = $categoryName;
        }
        if ($statusLabel !== '') {
            $metaParts[] = $statusLabel;
        }

        return [
            'post_id' => $postId,
            'cover_image' => $this->coverDisplayUrl(trim((string)($post[Post::schema_fields_COVER_IMAGE] ?? ''))),
            'article_head' => $articleHead !== '' ? $articleHead : '—',
            'article_title' => $title !== '' ? $title : '—',
            'article_slug' => $slug !== '' ? $slug : '—',
            'author' => $author !== '' ? $author : '—',
            'scope_markers' => $this->formatScopeMarkers($websiteId, $websiteLabels),
            'meta_cluster' => $metaParts !== [] ? implode(' · ', $metaParts) : '—',
            'updated_at' => (string)($post[Post::schema_fields_UPDATED_AT] ?? ''),
        ];
    }

  /**
     * @param list<array{value?:string,label?:string}> $websiteOptions
     * @return array<int,string>
     */
    public function websiteLabelMapFromOptions(array $websiteOptions): array
    {
        $map = [0 => '全站'];
        foreach ($websiteOptions as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = trim((string)($option['value'] ?? ''));
            if ($value === '' || !ctype_digit($value)) {
                continue;
            }
            $label = trim((string)($option['label'] ?? ''));
            $map[(int)$value] = $label !== '' ? $label : '#' . $value;
        }

        return $map;
    }

    private function coverDisplayUrl(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $lower = strtolower($path);
        if (str_starts_with($lower, 'https://')
            || str_starts_with($lower, 'http://')
            || str_starts_with($lower, 'data:image/')
        ) {
            return $path;
        }
        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, '://')) {
            return '';
        }

        $url = \Weline\FileManager\Api\Image::pathToMediaUrl($path, 96, 96);

        return is_string($url) && $url !== '' ? $url : '';
    }

    /**
     * @param array<int,string> $websiteLabels
     */
    private function formatScopeMarkers(int $websiteId, array $websiteLabels): string
    {
        $websiteLabel = $websiteLabels[$websiteId] ?? ($websiteId > 0 ? '#' . $websiteId : '全站');
        $storeLabel = '全店铺';
        $channelLabel = '全渠道';

        return '网站 ' . $websiteLabel . ' · 店铺 ' . $storeLabel . ' · 渠道 ' . $channelLabel;
    }
}
