<?php

declare(strict_types=1);

namespace Weline\Blog\Controller\Frontend;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Blog\Service\BlogScopeResolver;
use Weline\Blog\Service\BlogSearchCategoryScopeService;
use Weline\Blog\Service\BlogSeoFactsBuilder;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Helper\WidgetI18n;
use Weline\Theme\Service\ThemeContextService;

/**
 * 博客列表（店面）。
 *
 * @Extra type=fpc enabled=true ttl=31536000 namespaces=global/storefront/blog public_path_patterns=/blog
 */
final class Index extends FrontendController
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
     * 读取走 ThemeData::getParamTranslation()（不依赖参数定义是否注册进 Meta），
     * 未配置译文时返回布局声明的 default，因此「没动过后台」输出不变。
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

        // 页面 locale 与 Blog scope locale 可能不同源，显式遍历候选组合。
        $scopes = ['default'];
        try {
            $currentScope = trim((string)$this->themeContext->resolveCurrentScope('frontend'));
            if ($currentScope !== '' && $currentScope !== 'default') {
                $scopes[] = $currentScope;
            }
        } catch (\Throwable) {
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
        $articles = $this->resolver->listPublishedArticles($websiteId, $locale, 50, $this->scope->baseUrl());
        $categories = $this->resolver->listCategories($websiteId, $locale);

        // Keep H1 / theme_page_title / <title> leaf aligned with SEO list title (brand suffix added by Seo head).
        $seo = $this->seoFacts->buildListProfile($articles, $this->getUrl('blog'));
        $title = trim((string)($seo['title'] ?? ''));
        if ($title === '') {
            $title = $this->layoutCopy('title', '博客', '博客');
        }
        $this->layoutType = 'blog_category';
        $this->request->setGet('page_type', 'blog_list');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);
        $this->assign('blog_page_heading', $title);
        $this->assign('blog_page_subtitle', $this->layoutCopy('subheading', '精选文章与分类阅读', '精选文章与分类阅读'));
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
        $this->assign('blog_rss_label', $this->layoutCopy('rss_label', '订阅 RSS', '订阅 RSS'));
        // 列表区文案：优先布局参数（后台按语言可改），空串时模板回退 <lang> 词典。
        $this->assign('blog_list_title', $this->layoutCopy('list_title', '', ''));
        $this->assign('blog_total_label', $this->layoutCopy('total_label', '共 %{1} 条', ''));
        $this->assign('blog_empty_text', $this->layoutCopy('empty_text', '', ''));
        $this->assign('blog_back_link', $this->layoutCopy('back_link', '', ''));
        $this->assign('seo', $seo);

        return (string)$this->fetch('Weline_Blog::templates/frontend/index.phtml');
    }
}
