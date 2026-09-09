<?php

declare(strict_types=1);

namespace Weline\Blog\Controller\Frontend;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Blog\Service\BlogScopeResolver;
use Weline\Blog\Service\BlogSearchCategoryScopeService;
use Weline\Blog\Service\BlogSeoFactsBuilder;
use Weline\Framework\App\Controller\FrontendController;

/** Blog category listing: /blog/category/{slug} — Theme layout blog_category. */
final class Category extends FrontendController
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

        $heading = $active !== null
            ? (string)($active['name'] ?? __('博客分类'))
            : (string)__('博客分类');
        // Leaf for <title>: keep composed length in soft SERP budget (30-65) after site suffix.
        $title = $active !== null
            ? (string)__('「%{1}」分类博客 · 穿搭与选购', [$heading])
            : (string)__('汉服博客分类 · 穿搭灵感与选购指南');

        $this->layoutType = 'blog_category';
        $this->request->setGet('page_type', 'blog_category');
        $this->request->setGet(
            'theme_public_route',
            ltrim(BlogNamespace::categoryPublicPath($slug), '/'),
        );
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('blog_page_heading', $heading);
        $this->assign(
            'blog_page_subtitle',
            $active !== null
                ? (string)__('浏览「%{1}」分类下的汉服穿搭、形制科普与选购避坑文章，帮助你更快做出合适选择。', [$heading])
                : (string)__('按分类浏览汉服穿搭灵感、形制科普与选购指南，快速找到适合日常与礼仪场合的内容。'),
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

        $listUrl = $this->getUrl(ltrim(BlogNamespace::categoryPublicPath($slug), '/'));
        $seo = $this->seoFacts->buildListProfile($articles, $listUrl);
        $seo['page_type'] = 'blog_category';
        $seo['title'] = $title;
        $subtitle = (string)($this->getData('blog_page_subtitle') ?: '');
        $seo['description'] = $subtitle !== ''
            ? $subtitle
            : (string)$seo['description'];
        if (mb_strlen((string)$seo['description']) < 50) {
            $seo['description'] = rtrim((string)$seo['description'], "。.;； ")
                . '。'
                . (string)__('阅读穿搭灵感、形制科普与选购避坑，帮助你更快做出合适选择。');
        }
        if (mb_strlen((string)$seo['description']) > 320) {
            $seo['description'] = mb_substr((string)$seo['description'], 0, 320);
        }
        $seo['breadcrumbs'] = [
            ['name' => (string)__('首页'), 'url' => (string)$this->getUrl('/')],
            ['name' => (string)__('博客'), 'url' => (string)$this->getUrl('blog')],
            ['name' => $heading, 'url' => $listUrl],
        ];
        $seo['feeds'] = $slug !== ''
            ? $this->seoFacts->categoryFeeds($slug, $this->scope->baseUrl(), $title)
            : $this->seoFacts->siteFeeds($this->scope->baseUrl());
        $this->assign('blog_rss_url', $slug !== ''
            ? BlogNamespace::categoryRssPublicPath($slug)
            : BlogNamespace::rssPublicPath());
        $this->assign('blog_rss_label', $slug !== ''
            ? (string)__('订阅本分类 RSS')
            : (string)__('订阅 RSS'));
        $this->assign('seo', $seo);

        return (string)$this->fetch('Weline_Blog::templates/frontend/category/index.phtml');
    }
}
