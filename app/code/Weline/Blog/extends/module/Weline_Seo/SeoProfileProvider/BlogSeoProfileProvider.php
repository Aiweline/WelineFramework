<?php

declare(strict_types=1);

namespace Weline\Blog\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Blog\Service\BlogScopeResolver;
use Weline\Blog\Service\BlogSeoFactsBuilder;
use Weline\Seo\Interface\SeoProfileProviderInterface;

final class BlogSeoProfileProvider implements SeoProfileProviderInterface
{
    private const LIST_TYPES = [
        'blog_list',
        'blog_category',
        'blog',
    ];

    private const DETAIL_TYPES = [
        'blog_post',
        'blog_article',
        'blog',
        'article',
    ];

    public function __construct(
        private readonly BlogSeoFactsBuilder $factsBuilder,
        private readonly BlogContentResolver $resolver,
        private readonly BlogScopeResolver $scope,
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        if (!$this->isHeadSlot($context)) {
            return [];
        }

        $pageType = strtolower(trim((string)($context['page_type'] ?? $this->requestPageType($template))));
        $articleData = $this->articleFromContext($template, $context);

        if ($articleData !== null) {
            return $this->factsBuilder->buildDetailProfile(
                BlogArticle::fromArray($articleData),
                $this->preferredDetailCanonical($context),
            );
        }

        if ($this->isListPageType($pageType, $template, $context)) {
            $articles = $this->articlesFromContext($template, $context);
            if ($articles === []) {
                $articles = $this->resolver->listPublishedArticles(
                    $this->scope->websiteId(),
                    $this->scope->locale(),
                    50,
                    $this->scope->baseUrl(),
                );
            }

            $profile = $this->factsBuilder->buildListProfile($articles, $this->absoluteListUrl($context));
            // Category pages publish differentiated title/description via controller seo;
            // do not clobber them with the generic blog-index copy.
            if ($pageType === 'blog_category') {
                $profile['page_type'] = 'blog_category';
                $title = trim((string)($context['title'] ?? ''));
                if ($title !== '') {
                    $profile['title'] = $title;
                }
                $description = trim((string)($context['description'] ?? ''));
                if ($description !== '') {
                    $profile['description'] = $description;
                }
            }
            // Controller bag may already have a cover-based share image; provider rebuild
            // often lacks covers after Theme unsetData — keep the richer published image.
            $existingImage = trim((string)($context['image'] ?? ''));
            if ($existingImage !== '') {
                $profile['image'] = $existingImage;
                $existingAlt = trim((string)($context['image_alt'] ?? ''));
                if ($existingAlt !== '') {
                    $profile['image_alt'] = $existingAlt;
                }
            }

            return $profile;
        }

        if (!in_array($pageType, self::DETAIL_TYPES, true)) {
            return [];
        }

        $slug = trim((string)($context['slug'] ?? $this->requestSlug($template)));
        if ($slug === '') {
            return [];
        }
        $article = $this->resolver->resolveBySlug(
            $this->scope->websiteId(),
            $this->scope->locale(),
            $slug,
            $this->scope->baseUrl(),
        );
        if ($article === null) {
            return [];
        }

        return $this->factsBuilder->buildDetailProfile($article, $this->preferredDetailCanonical($context));
    }

    /**
     * Prefer controller/context canonical (getUrl with currency+locale) over DTO absolute.
     *
     * @param array<string, mixed> $context
     */
    private function preferredDetailCanonical(array $context): string
    {
        foreach (['canonical_url', 'url'] as $key) {
            $candidate = trim((string)($context[$key] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isHeadSlot(array $context): bool
    {
        $slot = strtolower(trim((string)($context['_slot'] ?? 'head')));

        return $slot === '' || $slot === 'head';
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     */
    private function isListPageType(string $pageType, $template, array $context): bool
    {
        if (in_array($pageType, ['blog_list', 'blog_category'], true)) {
            return true;
        }
        if ($pageType === 'blog' && $this->articleFromContext($template, $context) === null) {
            return $this->articlesFromContext($template, $context) !== []
                || $this->pathLooksLikeBlogIndex($context);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function pathLooksLikeBlogIndex(array $context): bool
    {
        $url = trim((string)($context['canonical_url'] ?? $context['url'] ?? ''));
        $path = is_string(parse_url($url, PHP_URL_PATH)) ? (string)parse_url($url, PHP_URL_PATH) : '';
        $segments = array_values(array_filter(explode('/', trim(rawurldecode($path), '/'))));
        $nonLocale = [];
        foreach ($segments as $segment) {
            if (preg_match('/^[a-z]{2}(?:[-_][a-z]{2,4}){1,2}$/i', $segment) === 1) {
                continue;
            }
            $nonLocale[] = strtolower($segment);
        }

        return $nonLocale === ['blog'];
    }

    /**
     * @param mixed $template
     */
    private function requestPageType($template): string
    {
        if (is_object($template) && method_exists($template, 'getRequest')) {
            return (string)$template->getRequest()->getParam('page_type', '');
        }

        return '';
    }

    /**
     * @param mixed $template
     */
    private function requestSlug($template): string
    {
        if (is_object($template) && method_exists($template, 'getRequest')) {
            return (string)$template->getRequest()->getParam('slug', '');
        }

        return '';
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function articleFromContext($template, array $context): ?array
    {
        foreach (['blog_article', 'article', 'current_post', 'page'] as $key) {
            if (isset($context[$key]) && is_array($context[$key]) && $this->looksLikeArticle($context[$key])) {
                return $context[$key];
            }
        }
        if (!is_object($template) || !method_exists($template, 'getData')) {
            return null;
        }
        foreach (['blog_article', 'article', 'current_post'] as $key) {
            $article = $template->getData($key);
            if (is_array($article) && $this->looksLikeArticle($article)) {
                return $article;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function looksLikeArticle(array $payload): bool
    {
        return trim((string)($payload['title'] ?? $payload['headline'] ?? $payload['name'] ?? '')) !== ''
            && (
                isset($payload['slug'])
                || isset($payload['canonical_url'])
                || isset($payload['public_url'])
                || isset($payload['content'])
                || isset($payload['excerpt'])
            );
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return list<BlogArticle>
     */
    private function articlesFromContext($template, array $context): array
    {
        $rows = [];
        if (is_array($context['blog_articles'] ?? null)) {
            $rows = $context['blog_articles'];
        } elseif (is_array($context['item_list'] ?? null)) {
            $rows = $context['item_list'];
        } elseif (is_object($template) && method_exists($template, 'getData')) {
            foreach (['blog_articles', 'blog_item_list', 'item_list'] as $key) {
                $value = $template->getData($key);
                if (is_array($value) && $value !== []) {
                    $rows = $value;
                    break;
                }
            }
        }

        $articles = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            try {
                $articles[] = BlogArticle::fromArray($row);
            } catch (\Throwable) {
                // item_list rows may only have name/url; skip incomplete rows
            }
        }

        return $articles;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function absoluteListUrl(array $context): string
    {
        foreach (['canonical_url', 'url'] as $key) {
            $candidate = trim((string)($context[$key] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }
        $base = $this->scope->baseUrl();
        if ($base === '') {
            return '/blog';
        }

        return $base . '/blog';
    }
}
