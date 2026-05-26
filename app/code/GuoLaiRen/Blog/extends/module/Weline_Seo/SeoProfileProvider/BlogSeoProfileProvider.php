<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Seo\Interface\SeoProfileProviderInterface;

class BlogSeoProfileProvider implements SeoProfileProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        $post = $context['current_post'] ?? $this->readTemplate($template, 'current_post');
        if (is_array($post) || is_object($post)) {
            return $this->postProfile($post, $context);
        }

        $items = $this->postItems($template);
        if ($items !== []) {
            return [
                'page_type' => $this->readTemplate($template, 'current_category') ? 'blog_category' : 'blog_list',
                'item_list' => $items,
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function postProfile(mixed $post, array $context): array
    {
        $title = trim((string)($this->read($post, ['title', 'headline', 'name']) ?: ($context['title'] ?? '')));
        if ($title === '') {
            return [];
        }

        $article = [
            'headline' => $title,
            'description' => (string)($this->read($post, ['summary', 'excerpt', 'description']) ?: ($context['description'] ?? '')),
            'datePublished' => (string)($this->read($post, ['published_at', 'created_at']) ?: ''),
            'dateModified' => (string)($this->read($post, ['updated_at', 'modified_at']) ?: ''),
            'author_name' => (string)($this->read($post, ['author_name', 'author']) ?: ''),
            'image' => (string)($this->read($post, ['cover_image', 'image', 'featured_image']) ?: ($context['image'] ?? '')),
            'articleSection' => (string)($this->read($post, ['category_name', 'category', 'section']) ?: ''),
            'keywords' => $this->tags($this->read($post, ['tags', 'keywords'])),
        ];

        if ($this->isNews($post)) {
            $article['is_news'] = true;
        }

        $isNews = (bool)($article['is_news'] ?? false);

        return [
            'page_type' => $isNews ? 'news_article' : 'blog_post',
            'article' => array_filter($article, static fn (mixed $value): bool => $value !== '' && $value !== []),
            'geo' => [
                'type' => $isNews ? 'news' : 'article',
                'include' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function postItems($template): array
    {
        $items = [];
        foreach (['posts', 'articles', 'recent_posts', 'related_posts'] as $key) {
            foreach ($this->list($this->readTemplate($template, $key)) as $post) {
                $title = trim((string)$this->read($post, ['title', 'headline', 'name']));
                $url = trim((string)$this->read($post, ['url', 'href', 'canonical']));
                if ($title === '' || $url === '') {
                    continue;
                }
                $items[$url] = [
                    'name' => $title,
                    'url' => $url,
                    'description' => (string)($this->read($post, ['summary', 'excerpt', 'description']) ?: ''),
                    'image' => (string)($this->read($post, ['cover_image', 'image', 'featured_image']) ?: ''),
                ];
            }
        }
        return array_values($items);
    }

    private function isNews(mixed $source): bool
    {
        $explicit = $this->read($source, ['is_news']);
        if ($explicit !== null && filter_var($explicit, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        $type = strtolower(trim((string)($this->read($source, ['article_type', 'content_type', 'type']) ?: '')));
        if (in_array($type, ['news', 'news_article'], true)) {
            return true;
        }
        foreach (['category_slug', 'category_name', 'category'] as $key) {
            $value = strtolower(trim((string)($this->read($source, [$key]) ?: '')));
            if ($value !== '' && preg_match('/(^|[-_\\s])news($|[-_\\s])/', $value)) {
                return true;
            }
        }
        return false;
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
     * @return array<int, mixed>
     */
    private function list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_is_list($value) ? $value : [$value];
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
