<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BlogFrontendTemplateContractTest extends TestCase
{
    public function testListTemplateRendersAmazonCards(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/index.phtml');
        self::assertStringContainsString('amazon-blog-listing', $template);
        self::assertStringContainsString('blog-storefront__card', $template);
        self::assertStringContainsString('data-testid="blog-list"', $template);
        self::assertStringContainsString('StorefrontImagePlaceholder', $template);
        self::assertStringContainsString("\$this->getUrl(ltrim(\$url, '/')", $template);
        self::assertStringContainsString("\$this->getUrl(ltrim(\$categoryUrl, '/')", $template);
        self::assertStringContainsString('amazon-blog-listing__section-title', $template);
        self::assertStringContainsString('<h1 class="amazon-blog-listing__title">', $template);
        self::assertStringContainsString('blog-storefront__card-title', $template);
        self::assertStringNotContainsString('<header class="amazon-blog-listing__header">', $template);
        self::assertStringNotContainsString('<h3><?= $escape($cardTitle) ?></h3>', $template);
        self::assertStringNotContainsString('<h2><?= $escape($title', $template);
    }

    public function testCategoryFilterTemplateUsesFrameworkUrl(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/partials/category-filter.phtml'
        );
        self::assertStringContainsString('data-testid="blog-category-filter"', $template);
        self::assertStringContainsString("\$template->getUrl(ltrim(\$path, '/')", $template);
        self::assertStringContainsString("\$buildUrl", $template);
        self::assertStringContainsString('<p class="amazon-blog-filter__title">', $template);
        self::assertStringNotContainsString('<h2 class="amazon-blog-filter__title">', $template);
    }

    public function testDetailTemplateRendersAmazonArticle(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/post/detail.phtml');
        self::assertStringContainsString('amazon-blog-article', $template);
        self::assertStringContainsString('data-testid="blog-post-detail"', $template);
        self::assertStringContainsString('blog-related', $template);
        self::assertStringContainsString('amazon-blog-article__content', $template);
        self::assertStringContainsString('amazon-blog-article__author-inline', $template);
        self::assertStringContainsString('amazon-blog-article__keywords', $template);
        self::assertStringContainsString('@url{$rPath}', $template);
        self::assertStringNotContainsString("href=\"<?= \$escape(\$rUrl) ?>\"", $template);

        $form = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/backend/post-admin/form.phtml');
        self::assertStringContainsString('Weline\\Blog\\Model\\Post\\LocalDescription', $form);
        self::assertStringContainsString('data-testid="blog-post-keywords-local"', $form);
        self::assertStringContainsString('field="keywords"', $form);
    }

    public function testBlogReviewsWidgetRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('blog-reviews', $widgets);
        $widget = $widgets['blog-reviews'];
        self::assertSame('blog-reviews', $widget['slot'] ?? null);
        self::assertSame('Weline_Blog::templates/frontend/widgets/blog-reviews.phtml', $widget['template'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('blog', $injection['layout_type'] ?? null);
        self::assertSame('blog-reviews', $injection['slot'] ?? null);
    }

    public function testHeaderBlogLinkWidgetRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
        $widgets = require $widgetFile;
        self::assertArrayHasKey('header-blog-link', $widgets);
        $widget = $widgets['header-blog-link'];
        self::assertSame('header-nav-extensions', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Blog::templates/frontend/widgets/header-blog-link.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('header-nav-extensions', $injection['slot'] ?? null);
        self::assertSame('header', $injection['area'] ?? null);
        self::assertSame(0, (int)($injection['sort_order'] ?? -1));
        self::assertSame('博客', $injection['config']['label'] ?? null);

        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/header-blog-link.phtml'
        );
        self::assertStringContainsString('data-testid="header-blog-link"', $template);
        self::assertStringContainsString("@url{'blog'}", $template);
        self::assertStringContainsString('@widget.slot {header-nav-extensions}', $template);
        self::assertStringContainsString('右侧扩展槽', $injection['reason'] ?? '');
        self::assertStringContainsString('与快捷导航同簇', (string)($widget['description'] ?? ''));
    }

    public function testFooterBlogLinkWidgetRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-blog-link', $widgets);
        $widget = $widgets['footer-blog-link'];
        self::assertSame('footer-about-links', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Blog::templates/frontend/widgets/footer-blog-link.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('footer-about-links', $injection['slot'] ?? null);
        self::assertSame('footer', $injection['area'] ?? null);
        self::assertSame(0, (int)($injection['sort_order'] ?? -1));
        self::assertSame('博客', $injection['config']['label'] ?? null);
    }

    public function testFooterNewsLinkWidgetRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-news-link', $widgets);
        $widget = $widgets['footer-news-link'];
        self::assertSame('footer-about-links', $widget['slot'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('footer-about-links', $injection['slot'] ?? null);
        self::assertSame('footer', $injection['area'] ?? null);
        self::assertSame('news', $injection['config']['category_slug'] ?? null);
    }

    public function testBlogReviewsWidgetTemplateUsesReviewRuntime(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/blog-reviews.phtml');
        self::assertStringContainsString('data-testid="storefront-blog-reviews"', $template);
        self::assertStringContainsString('data-type-code="blog"', $template);
        self::assertStringContainsString('blog:post:', $template);
        self::assertStringContainsString('data-weline-load="productReviews"', $template);
        self::assertStringNotContainsString('product-reviews.js', $template);

        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('ensureBlogReviewsPublished', $upgrade);
        self::assertStringContainsString('publishLayout', $upgrade);
        self::assertStringContainsString('applyRequiredMissingForIdentity', $upgrade);
    }
}
