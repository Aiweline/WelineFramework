<?php

declare(strict_types=1);

namespace Weline\Blog\Controller\Frontend;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Blog\Service\BlogScopeResolver;
use Weline\Blog\Service\BlogSearchCategoryScopeService;
use Weline\Framework\App\Controller\FrontendController;

/** Blog category listing: /blog/category/{slug} — Theme layout blog_category. */
final class Category extends FrontendController
{
    public function __construct(
        private readonly BlogContentResolver $resolver,
        private readonly BlogScopeResolver $scope,
        private readonly BlogSearchCategoryScopeService $categoryScopes,
    ) {
    }

    public function index(): string
    {
        $websiteId = $this->scope->websiteId();
        $locale = $this->scope->locale();
        $slug = strtolower(trim((string)$this->request->getParam('category_slug', '')));
        $categories = $this->resolver->listCategories($websiteId, $locale);

        $active = null;
        if ($slug !== '') {
            $active = $this->resolver->resolveCategoryBySlug($websiteId, $slug, $locale);
            if ($active === null) {
                $this->noRouter();

                return '';
            }
        }

        $categoryId = $active !== null ? (int)($active['category_id'] ?? 0) : 0;
        $articles = $categoryId > 0
            ? $this->resolver->listPublishedArticlesByCategory($websiteId, $locale, $categoryId, 50, $this->scope->baseUrl())
            : $this->resolver->listPublishedArticles($websiteId, $locale, 50, $this->scope->baseUrl());

        $title = $active !== null
            ? (string)($active['name'] ?? __('博客分类'))
            : (string)__('博客分类');

        $this->layoutType = 'blog_category';
        $this->request->setGet('page_type', 'blog_category');
        $this->request->setGet(
            'theme_public_route',
            ltrim(BlogNamespace::categoryPublicPath($slug), '/'),
        );
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('blog_page_heading', $title);
        $this->assign(
            'blog_page_subtitle',
            $active !== null
                ? (string)__('浏览「%{1}」分类下的文章', [$title])
                : (string)__('按分类浏览博客文章'),
        );
        $this->assign('blog_active_category_id', $categoryId);
        $this->assign('blog_active_category_slug', $slug);
        $this->assign('blog_categories', $categories);
        $this->assign('blog_category_scopes', $this->categoryScopes->listForSearch($websiteId, $locale));
        $this->assign('blog_articles', array_map(static fn(BlogArticle $a): array => $a->toArray(), $articles));
        $this->assign('blog_item_list', array_map(static function (BlogArticle $article): array {
            return [
                'name' => $article->title,
                'url' => $article->publicUrl,
                'description' => $article->excerpt,
            ];
        }, $articles));

        return (string)$this->fetch('Weline_Blog::templates/frontend/category/index.phtml');
    }
}
