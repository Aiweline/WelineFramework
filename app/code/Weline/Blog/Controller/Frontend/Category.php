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
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\WidgetI18n;
use Weline\Theme\Service\ThemeContextService;

/**
 * 博客分类列表（店面）。
 *
 * @Extra type=fpc enabled=true ttl=31536000 namespaces=global/storefront/blog public_path_patterns=/blog/category,/blog/category/*
 */
final class Category extends FrontendController
{
    public function __construct(
        private readonly BlogContentResolver $resolver,
        private readonly BlogScopeResolver $scope,
        private readonly BlogSearchCategoryScopeService $categoryScopes,
        private readonly BlogSeoFactsBuilder $seoFacts,
        private readonly ThemeContextService $themeContext,
    ) {
    }

    /**
     * 页面文案优先取自「布局参数」（后台可视化按语言可改），缺省才回退词典。
     * 参数声明见 layouts/blog_category/default.phtml 顶部 @param.*。
     *
     * 读取走 ThemeData::getParamTranslation()：它不依赖参数定义是否已注册进 Meta，
     * 未配置译文时返回布局文件里声明的 default，因此「没动过后台」时输出与改造前一致。
     *
     * @param string               $param    参数名（@param.<name>）
     * @param string               $default  布局声明的默认串；传空串表示只取译文、不兜底
     * @param string               $fallback 布局默认串为空时的词典兜底文案
     * @param array<string,string> $vars     占位符替换表（键为 1/2/…，对应 %{1}）
     */
    private function layoutCopy(string $param, string $default, string $fallback = '', array $vars = []): string
    {
        $identify = 'layouts.blog_category.default';
        $value = '';

        // 页面 locale 与 Blog scope locale 可能不同源，这里显式遍历候选组合：
        // scope 取「主题当前 scope + default」，locale 取「当前 + en_US + zh_Hans_CN」。
        $scopes = ['default'];
        $currentScope = '';
        try {
            $currentScope = trim((string)$this->themeContext->resolveCurrentScope('frontend'));
        } catch (\Throwable) {
            $currentScope = '';
        }
        if ($currentScope !== '' && $currentScope !== 'default') {
            $scopes[] = $currentScope;
        }

        $locales = [];
        try {
            $locales[] = trim((string)$this->scope->locale());
        } catch (\Throwable) {
        }
        $locales[] = 'en_US';
        $locales[] = 'zh_Hans_CN';

        foreach (array_values(array_unique(array_filter($locales))) as $candidateLocale) {
            foreach ($scopes as $candidateScope) {
                try {
                    // 传空 default：只取译文，避免把布局默认串误判成「已命中译文」。
                    $hit = trim(ThemeData::getParamTranslation($identify, $param, $candidateScope, $candidateLocale, ''));
                } catch (\Throwable) {
                    $hit = '';
                }
                if ($hit !== '') {
                    $value = $hit;
                    break 2;
                }
            }
        }

        if ($value === '') {
            $value = trim($default);
        }

        if ($value === '') {
            // 空兜底 = 只取参数，未配置时返回空串，交由模板自身的 <lang> 词典回退。
            if ($fallback === '') {
                return '';
            }
            $value = $vars === []
                ? WidgetI18n::label($fallback)
                : WidgetI18n::label($fallback, '', array_values($vars));
        } elseif ($vars !== []) {
            foreach ($vars as $index => $replacement) {
                $value = str_replace('%{' . $index . '}', (string)$replacement, $value);
            }
        }

        return $value;
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
            ? (string)($active['name'] ?? $this->layoutCopy('heading_all', '博客分类', '博客分类'))
            : $this->layoutCopy('heading_all', '博客分类', '博客分类');
        // Leaf for <title>: keep composed length in soft SERP budget (30-65) after site suffix.
        $title = $active !== null
            ? $this->layoutCopy('heading_category', '「%{1}」分类 · 博客文章', '「%{1}」分类 · 博客文章', [1 => $heading])
            : $this->layoutCopy('heading_all', '博客分类', '博客分类');

        $this->layoutType = 'blog_category';
        $this->request->setGet('page_type', 'blog_category');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('blog_page_heading', $heading);
        $this->assign(
            'blog_page_subtitle',
            $active !== null
                ? $this->layoutCopy('subtitle_category', '浏览「%{1}」分类下的文章，快速找到你需要的内容。', '浏览「%{1}」分类下的文章，快速找到你需要的内容。', [1 => $heading])
                : $this->layoutCopy('subtitle_all', '按分类浏览本站文章与指南。', '按分类浏览本站文章与指南。'),
        );
        $this->assign('blog_active_category_id', $categoryId);
        // 列表区文案：优先布局参数（后台按语言可改），空串时模板回退 <lang> 词典。
        $this->assign('blog_list_title', $this->layoutCopy('list_title', '', ''));
        $this->assign('blog_total_label', $this->layoutCopy('total_label', '共 %{1} 条', ''));
        $this->assign('blog_empty_text', $this->layoutCopy('empty_text', '', ''));
        $this->assign('blog_back_link', $this->layoutCopy('back_link', '', ''));
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
                . $this->layoutCopy('seo_description', '阅读精选文章与实用指南，帮助你更快找到需要的内容。', '阅读精选文章与实用指南，帮助你更快找到需要的内容。');
        }
        if (mb_strlen((string)$seo['description']) > 320) {
            $seo['description'] = mb_substr((string)$seo['description'], 0, 320);
        }
        $seo['breadcrumbs'] = [
            ['name' => $this->layoutCopy('breadcrumb_home', '首页', '首页'), 'url' => (string)$this->getUrl('/')],
            ['name' => $this->layoutCopy('breadcrumb_blog', '博客', '博客'), 'url' => (string)$this->getUrl('blog')],
            ['name' => $heading, 'url' => $listUrl],
        ];
        $seo['feeds'] = $slug !== ''
            ? $this->seoFacts->categoryFeeds($slug, $this->scope->baseUrl(), $title)
            : $this->seoFacts->siteFeeds($this->scope->baseUrl());
        $this->assign('blog_rss_url', $slug !== ''
            ? BlogNamespace::categoryRssPublicPath($slug)
            : BlogNamespace::rssPublicPath());
        $this->assign('blog_rss_label', $slug !== ''
            ? $this->layoutCopy('rss_label_category', '订阅本分类 RSS', '订阅本分类 RSS')
            : $this->layoutCopy('rss_label', '订阅 RSS', '订阅 RSS'));
        $this->assign('seo', $seo);

        return (string)$this->fetch('Weline_Blog::templates/frontend/category/index.phtml');
    }
}
