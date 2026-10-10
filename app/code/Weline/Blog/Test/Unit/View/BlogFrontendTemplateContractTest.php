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
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
        $tpl = 'Weline_Blog::templates/frontend/widgets/blog-reviews.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/blog-reviews.phtml');
        self::assertStringContainsString('@widget.code {blog-reviews}', $src);
        self::assertStringContainsString('@widget.placement {layout}', $src);
        self::assertStringContainsString('@widget.default_injections {[]}', $src);
        self::assertStringContainsString('@widget.slot {blog-reviews}', $src);
    }

    public function testHeaderBlogLinkWidgetRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
        $tpl = 'Weline_Blog::templates/frontend/widgets/header-blog-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/header-blog-link.phtml');
        self::assertStringContainsString('@widget.code {header-blog-link}', $src);
        self::assertStringContainsString('@widget.slot {header-nav-extensions}', $src);
        self::assertStringContainsString('"slot":"header-nav-extensions"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testFooterBlogLinkWidgetRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
        $tpl = 'Weline_Blog::templates/frontend/widgets/footer-blog-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-blog-link.phtml');
        self::assertStringContainsString('@widget.code {footer-blog-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-about-links}', $src);
        self::assertStringContainsString('"slot":"footer-about-links"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testFooterNewsLinkWidgetRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
        $tpl = 'Weline_Blog::templates/frontend/widgets/footer-news-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-news-link.phtml');
        self::assertStringContainsString('@widget.code {footer-news-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-about-links}', $src);
        self::assertStringContainsString('"category_slug":"news"', $src);
        self::assertStringContainsString('"required":true', $src);
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
