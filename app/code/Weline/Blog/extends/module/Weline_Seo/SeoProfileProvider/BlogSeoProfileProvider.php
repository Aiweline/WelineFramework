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
        if ($pageType === 'blog_list') {
            $articles = $this->resolver->listPublishedArticles(
                $this->scope->websiteId(),
                $this->scope->locale(),
                50,
                $this->scope->baseUrl(),
            );

            return $this->factsBuilder->buildListProfile($articles, $this->absoluteListUrl());
        }

        $articleData = $this->articleFromContext($template, $context);
        if ($articleData === null) {
            if ($pageType !== 'blog_post') {
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

            return $this->factsBuilder->buildDetailProfile($article);
        }

        return $this->factsBuilder->buildDetailProfile(BlogArticle::fromArray($articleData));
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
        if (isset($context['blog_article']) && is_array($context['blog_article'])) {
            return $context['blog_article'];
        }
        if (!is_object($template) || !method_exists($template, 'getData')) {
            return null;
        }
        $article = $template->getData('blog_article');

        return is_array($article) ? $article : null;
    }

    private function absoluteListUrl(): string
    {
        $base = $this->scope->baseUrl();
        if ($base === '') {
            return '/blog';
        }

        return $base . '/blog';
    }
}
