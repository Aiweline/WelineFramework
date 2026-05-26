<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Seo\SeoProfileProvider;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Seo\Interface\SeoProfileProviderInterface;

class PageBuilderSeoProfileProvider implements SeoProfileProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        $pageType = (string)($context['page_type'] ?? '');
        $profile = [];

        if ($pageType === Page::TYPE_BLOG) {
            $post = $context['current_post'] ?? $this->readTemplate($template, 'current_post') ?? $context['page'] ?? null;
            $article = $this->articleProfile($post, $context);
            if ($article !== []) {
                $profile['article'] = $article;
            }
        }

        if (in_array($pageType, [Page::TYPE_BLOG_LIST, Page::TYPE_BLOG_CATEGORY], true)
            && empty($context['item_list'])) {
            $items = $this->articleItems($template);
            if ($items !== []) {
                $profile['item_list'] = $items;
            }
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function articleProfile(mixed $post, array $context): array
    {
        $title = trim((string)($this->read($post, ['title', 'headline', 'name']) ?: ($context['title'] ?? '')));
        if ($title === '') {
            return [];
        }

        $article = [
            'headline' => $title,
            'description' => (string)($this->read($post, ['summary', 'excerpt', 'description']) ?: ($context['description'] ?? '')),
            'datePublished' => (string)($this->read($post, ['published_at', 'created_at', 'create_time']) ?: ''),
            'dateModified' => (string)($this->read($post, ['updated_at', 'modified_at', 'update_time']) ?: ''),
            'author_name' => (string)($this->read($post, ['author_name', 'author']) ?: ''),
            'image' => (string)($this->read($post, ['image', 'cover_image', 'featured_image']) ?: ($context['image'] ?? '')),
            'articleSection' => (string)($this->read($post, ['category_name', 'category', 'section']) ?: ''),
            'keywords' => $this->tags($this->read($post, ['tags', 'keywords'])),
        ];

        $type = strtolower(trim((string)$this->read($post, ['article_type', 'content_type', 'type'])));
        if ($type === 'news' || filter_var($this->read($post, ['is_news']), FILTER_VALIDATE_BOOLEAN)) {
            $article['is_news'] = true;
        }

        return array_filter($article, static fn(mixed $value): bool => $value !== '' && $value !== []);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function articleItems($template): array
    {
        $items = [];
        foreach (['posts', 'articles', 'recent_posts', 'related_posts'] as $key) {
            $source = $this->readTemplate($template, $key);
            if (!is_array($source)) {
                continue;
            }
            foreach ($source as $post) {
                if (!is_array($post) && !is_object($post)) {
                    continue;
                }
                $title = trim((string)$this->read($post, ['title', 'headline', 'name']));
                $url = trim((string)$this->read($post, ['url', 'href', 'canonical']));
                if ($title === '' || $url === '') {
                    continue;
                }
                $items[$url] = [
                    'name' => $title,
                    'url' => $url,
                    'description' => (string)($this->read($post, ['summary', 'excerpt', 'description']) ?: ''),
                    'image' => (string)($this->read($post, ['image', 'cover_image', 'featured_image']) ?: ''),
                ];
            }
        }

        return array_values($items);
    }

    private function readTemplate($template, string $key): mixed
    {
        return is_object($template) && method_exists($template, 'getData') ? $template->getData($key) : null;
    }

    private function read(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }
            if (is_object($source) && method_exists($source, 'getData')) {
                $value = $source->getData($key);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    private function tags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = str_contains($tags, ',') ? array_map('trim', explode(',', $tags)) : [$tags];
        }
        if (!is_array($tags)) {
            return [];
        }
        $result = [];
        foreach ($tags as $tag) {
            $tag = is_array($tag) ? ($tag['name'] ?? $tag['label'] ?? '') : $tag;
            $tag = trim((string)$tag);
            if ($tag !== '') {
                $result[$tag] = true;
            }
        }
        return array_keys($result);
    }
}
