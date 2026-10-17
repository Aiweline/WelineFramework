<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllBlogViewContractsPass().
 */
final class BlogViewContractsTest extends TestCase
{
    public function testAllBlogViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'BlogFrontendTemplate::testListTemplateRendersAmazonCards' => function () {
            $this->assert_BlogFrontendTemplate_testListTemplateRendersAmazonCards();
        },
            'BlogFrontendTemplate::testCategoryFilterTemplateUsesFrameworkUrl' => function () {
            $this->assert_BlogFrontendTemplate_testCategoryFilterTemplateUsesFrameworkUrl();
        },
            'BlogFrontendTemplate::testDetailTemplateRendersAmazonArticle' => function () {
            $this->assert_BlogFrontendTemplate_testDetailTemplateRendersAmazonArticle();
        },
            'BlogFrontendTemplate::testBlogReviewsWidgetRegistersDefaultInjection' => function () {
            $this->assert_BlogFrontendTemplate_testBlogReviewsWidgetRegistersDefaultInjection();
        },
            'BlogFrontendTemplate::testHeaderBlogLinkWidgetRegistersDefaultInjection' => function () {
            $this->assert_BlogFrontendTemplate_testHeaderBlogLinkWidgetRegistersDefaultInjection();
        },
            'BlogFrontendTemplate::testFooterBlogLinkWidgetRegistersDefaultInjection' => function () {
            $this->assert_BlogFrontendTemplate_testFooterBlogLinkWidgetRegistersDefaultInjection();
        },
            'BlogFrontendTemplate::testFooterNewsLinkWidgetRegistersDefaultInjection' => function () {
            $this->assert_BlogFrontendTemplate_testFooterNewsLinkWidgetRegistersDefaultInjection();
        },
            'BlogFrontendTemplate::testBlogReviewsWidgetTemplateUsesReviewRuntime' => function () {
            $this->assert_BlogFrontendTemplate_testBlogReviewsWidgetTemplateUsesReviewRuntime();
        },
            'BlogReviewsPlacement::testReviewsUseInjectionXorEmptyLayoutSlot' => function () {
            $this->assert_BlogReviewsPlacement_testReviewsUseInjectionXorEmptyLayoutSlot();
        },
            'BlogReviewsPlacement::testReviewsLayoutShellDoesNotDoubleCardChrome' => function () {
            $this->assert_BlogReviewsPlacement_testReviewsLayoutShellDoesNotDoubleCardChrome();
        },
            'BlogSearchHitTemplate::testHitTemplateDispatchesByContentKind' => function () {
            $this->assert_BlogSearchHitTemplate_testHitTemplateDispatchesByContentKind();
        },
            'BlogSearchHitTemplate::testBlogSearchHitsRenderAsCards' => function () {
            $this->assert_BlogSearchHitTemplate_testBlogSearchHitsRenderAsCards();
        },
        ];
    }

private function assert_BlogFrontendTemplate_testListTemplateRendersAmazonCards(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/index.phtml');
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

private function assert_BlogFrontendTemplate_testCategoryFilterTemplateUsesFrameworkUrl(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/partials/category-filter.phtml');
    self::assertStringContainsString('data-testid="blog-category-filter"', $template);
    self::assertStringContainsString("\$template->getUrl(ltrim(\$path, '/')", $template);
    self::assertStringContainsString("\$buildUrl", $template);
    self::assertStringContainsString('<p class="amazon-blog-filter__title">', $template);
    self::assertStringNotContainsString('<h2 class="amazon-blog-filter__title">', $template);
}

private function assert_BlogFrontendTemplate_testDetailTemplateRendersAmazonArticle(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/post/detail.phtml');
    self::assertStringContainsString('amazon-blog-article', $template);
    self::assertStringContainsString('data-testid="blog-post-detail"', $template);
    self::assertStringContainsString('blog-related', $template);
    self::assertStringContainsString('amazon-blog-article__content', $template);
    self::assertStringContainsString('amazon-blog-article__author-inline', $template);
    self::assertStringContainsString('amazon-blog-article__keywords', $template);
    self::assertStringContainsString('@url{$rPath}', $template);
    self::assertStringNotContainsString("href=\"<?= \$escape(\$rUrl) ?>\"", $template);
    $form = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/backend/post-admin/form.phtml');
    self::assertStringContainsString('Weline\Blog\Model\Post\LocalDescription', $form);
    self::assertStringContainsString('data-testid="blog-post-keywords-local"', $form);
    self::assertStringContainsString('field="keywords"', $form);
}

private function assert_BlogFrontendTemplate_testBlogReviewsWidgetRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
    $tpl = 'Weline_Blog::templates/frontend/widgets/blog-reviews.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/widgets/blog-reviews.phtml');
    self::assertStringContainsString('@widget.code {blog-reviews}', $src);
    self::assertStringContainsString('@widget.placement {injection}', $src);
    self::assertStringContainsString('"layout_type":"blog"', $src);
    self::assertStringContainsString('"slot":"blog-reviews"', $src);
    self::assertStringContainsString('"required":true', $src);
    self::assertStringContainsString('@widget.slot {blog-reviews}', $src);
}

private function assert_BlogFrontendTemplate_testHeaderBlogLinkWidgetRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
    $tpl = 'Weline_Blog::templates/frontend/widgets/header-blog-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/widgets/header-blog-link.phtml');
    self::assertStringContainsString('@widget.code {header-blog-link}', $src);
    self::assertStringContainsString('@widget.slot {header-nav-extensions}', $src);
    self::assertStringContainsString('"slot":"header-nav-extensions"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_BlogFrontendTemplate_testFooterBlogLinkWidgetRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
    $tpl = 'Weline_Blog::templates/frontend/widgets/footer-blog-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/widgets/footer-blog-link.phtml');
    self::assertStringContainsString('@widget.code {footer-blog-link}', $src);
    self::assertStringContainsString('@widget.slot {footer-about-links}', $src);
    self::assertStringContainsString('"slot":"footer-about-links"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_BlogFrontendTemplate_testFooterNewsLinkWidgetRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
    $tpl = 'Weline_Blog::templates/frontend/widgets/footer-news-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/widgets/footer-news-link.phtml');
    self::assertStringContainsString('@widget.code {footer-news-link}', $src);
    self::assertStringContainsString('@widget.slot {footer-about-links}', $src);
    self::assertStringContainsString('"category_slug":"news"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_BlogFrontendTemplate_testBlogReviewsWidgetTemplateUsesReviewRuntime(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/widgets/blog-reviews.phtml');
    self::assertStringContainsString('data-testid="storefront-blog-reviews"', $template);
    self::assertStringContainsString('data-type-code="blog"', $template);
    self::assertStringContainsString('blog:post:', $template);
    self::assertStringContainsString('data-weline-load="productReviews"', $template);
    self::assertStringNotContainsString('product-reviews.js', $template);
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Setup/Upgrade.php');
    self::assertStringContainsString('ensureBlogReviewsPublished', $upgrade);
    self::assertStringContainsString('publishLayout', $upgrade);
    self::assertStringContainsString('applyRequiredMissingForIdentity', $upgrade);
}

private function assert_BlogReviewsPlacement_testReviewsUseInjectionXorEmptyLayoutSlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/extends/module/Weline_Widget/Weline_Blog/widget.php';
    $tpl = 'Weline_Blog::templates/frontend/widgets/blog-reviews.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/widgets/blog-reviews.phtml');
    self::assertStringContainsString('@widget.code {blog-reviews}', $src);
    self::assertStringContainsString('@widget.placement {injection}', $src);
    self::assertStringContainsString('"layout_type":"blog"', $src);
    self::assertStringContainsString('"slot":"blog-reviews"', $src);
    self::assertStringContainsString('"required":true', $src);
    self::assertStringNotContainsString('@widget.placement {layout}', $src);
    $layout = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/theme/frontend/layouts/blog/default.phtml');
    self::assertStringContainsString('id="blog-reviews"', $layout);
    self::assertDoesNotMatchRegularExpression('/<w:widget\b[^>]*name=["\']blog-reviews["\']/', $layout);
}

private function assert_BlogReviewsPlacement_testReviewsLayoutShellDoesNotDoubleCardChrome(): void
{
    $layout = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/theme/frontend/layouts/blog/default.phtml');
    self::assertStringContainsString('blog-layout__reviews-container', $layout);
    self::assertStringContainsString('background: transparent', $layout);
    self::assertStringContainsString('padding: 0', $layout);
    // 禁止布局壳再套白底边框（与 .weline-review 叠成盒中盒）
    self::assertDoesNotMatchRegularExpression('/\.blog-layout__reviews-container\s*\{[^}]*border:\s*1px/s', $layout);
    self::assertDoesNotMatchRegularExpression('/\.blog-layout__reviews-container\s*\{[^}]*background:\s*#fff/s', $layout);
}

private function assert_BlogSearchHitTemplate_testHitTemplateDispatchesByContentKind(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/search/hit.phtml');
    self::assertStringContainsString('search-hit-post.phtml', $template);
    self::assertStringContainsString('search-hit-cms.phtml', $template);
    self::assertStringContainsString('content_kind', $template);
}

private function assert_BlogSearchHitTemplate_testBlogSearchHitsRenderAsCards(): void
{
    $post = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/search/partials/search-hit-post.phtml');
    $cms = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/view/templates/frontend/search/partials/search-hit-cms.phtml');
    self::assertStringContainsString('blog-storefront__card', $post);
    self::assertStringContainsString('data-testid="storefront-blog-card"', $post);
    self::assertStringContainsString('blog-storefront__media', $post);
    self::assertStringContainsString('blog-storefront__body', $post);
    self::assertStringContainsString('StorefrontImagePlaceholder', $post);
    self::assertStringContainsString('blog-storefront__card', $cms);
    self::assertStringContainsString('data-testid="storefront-blog-card"', $cms);
    self::assertStringContainsString('data-testid="search-hit-blog-cms"', $cms);
}
}
