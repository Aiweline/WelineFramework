<?php

declare(strict_types=1);

namespace Weline\Blog\Controller\Frontend;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Blog\Service\BlogScopeResolver;
use Weline\Blog\Service\BlogSearchCategoryScopeService;
use Weline\Blog\Service\BlogSeoFactsBuilder;
use Weline\Framework\App\Controller\FrontendController;

/** Blog listing: /blog — Theme layout blog_category (Amazon-style card grid). */
final class Index extends FrontendController
{
    public function __construct(
        private readonly BlogContentResolver $resolver,
        private readonly BlogScopeResolver $scope,
        private readonly BlogSearchCategoryScopeService $categoryScopes,
        private readonly BlogSeoFactsBuilder $seoFacts,
    ) {
    }

    public function index(): string
    {
        $websiteId = $this->scope->websiteId();
        $locale = $this->scope->locale();
        $articles = $this->resolver->listPublishedArticles($websiteId, $locale, 50, $this->scope->baseUrl());
        $categories = $this->resolver->listCategories($websiteId, $locale);

        // Keep H1 / theme_page_title / <title> leaf aligned with SEO list title (brand suffix added by Seo head).
        $seo = $this->seoFacts->buildListProfile($articles, $this->getUrl('blog'));
        $title = trim((string)($seo['title'] ?? ''));
        if ($title === '') {
            $title = (string)__('汉服博客 | 穿搭灵感与文化指南');
        }
        $this->layoutType = 'blog_category';
        $this->request->setGet('page_type', 'blog_list');
        $this->request->setGet('theme_public_route', 'blog');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('blog_page_heading', $title);
        $this->assign('blog_page_subtitle', (string)__('精选文章与分类阅读'));
        $this->assign('blog_active_category_id', 0);
        $this->assign('blog_active_category_slug', '');
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
        $this->assign('blog_rss_url', \Weline\Blog\Api\Uri\BlogNamespace::rssPublicPath());
        $this->assign('blog_rss_label', (string)__('订阅 RSS'));
        $this->assign('seo', $seo);

        return (string)$this->fetch('Weline_Blog::templates/frontend/index.phtml');
    }
}
