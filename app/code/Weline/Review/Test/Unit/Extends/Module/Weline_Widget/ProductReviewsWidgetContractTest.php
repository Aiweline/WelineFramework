<?php

declare(strict_types=1);

namespace Weline\Review\Test\Unit\Extends\Module\Weline_Widget;

use PHPUnit\Framework\TestCase;

final class ProductReviewsWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsDefaultInjectionSlot(): void
    {
        $path = dirname(__DIR__, 5) . '/extends/module/Weline_Widget/Weline_Review/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertIsArray($widgets);
        self::assertArrayHasKey('product-reviews', $widgets);
        $widget = $widgets['product-reviews'];
        $widgetSource = (string)file_get_contents($path);
        self::assertSame('product-reviews', $widget['slot'] ?? null);
        self::assertSame('comment', $widget['type'] ?? null);
        self::assertSame('Weline_Review::templates/frontend/widgets/product-reviews.phtml', $widget['template'] ?? null);
        self::assertStringContainsString("'placement' => 'injection'", $widgetSource);
        self::assertSame('injection', $widgets['product-reviews']['placement'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product-reviews', $injection['slot'] ?? null);
        self::assertSame('product', $injection['layout_type'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));

        $hook = (string)file_get_contents(
            dirname(__DIR__, 5) . '/view/hooks/Weline_Review/frontend/layouts/product-reviews/content.phtml'
        );
        self::assertStringNotContainsString('fetch(', $hook);
        self::assertStringContainsString('placement=injection', $hook);
    }

    public function testWidgetTemplateUsesThemeTokensExternalAssetsAndNoInlineScript(): void
    {
        $tpl = dirname(__DIR__, 5) . '/view/templates/frontend/widgets/product-reviews.phtml';
        self::assertFileExists($tpl);
        $source = (string)file_get_contents($tpl);
        self::assertStringContainsString('data-testid="storefront-product-reviews"', $source);
        self::assertStringContainsString('data-testid="storefront-product-reviews-unavailable"', $source);
        self::assertStringContainsString('data-review-root', $source);
        self::assertStringContainsString('data-layout-mode', $source);
        self::assertStringContainsString('data-form-collapsed', $source);
        self::assertStringContainsString('data-review-media-dialog', $source);
        self::assertStringContainsString('w-review-media__dialog', $source);
        self::assertStringContainsString('data-w-component="dialog"', $source);
        self::assertStringContainsString('viewPhoto', $source);
        self::assertStringContainsString('weline-review--layout-', $source);
        self::assertStringContainsString("'stack', 'split'", $source);
        self::assertStringContainsString('Weline_Review::css/widgets/product-reviews.css', $source);
        self::assertStringContainsString('data-weline-load="productReviews"', $source);
        self::assertStringContainsString('StorefrontOfferResolver', $source);
        self::assertStringContainsString('global_offer_uuid', $source);
        self::assertStringContainsString('global_product_uuid', $source);

        $css = (string)file_get_contents(dirname(__DIR__, 5) . '/view/statics/css/widgets/product-reviews.css');
        self::assertStringContainsString('--color-bg-primary', $css);
        self::assertStringContainsString('--color-accent', $css);
        self::assertStringContainsString('weline-review--layout-stack', $css);
        self::assertStringContainsString('weline-review--layout-split', $css);
        self::assertStringContainsString('weline-review--form-collapsed', $css);
        self::assertStringContainsString('w-review-media__dialog', $css);
        self::assertStringContainsString('w-review-media__dialog-body', $css);
        self::assertStringNotContainsString('#0b0d0f', $css);
        self::assertStringNotContainsString('#df2029', $css);
    }

    public function testWidgetRegistrationExposesLayoutConfigParams(): void
    {
        $path = dirname(__DIR__, 5) . '/extends/module/Weline_Widget/Weline_Review/widget.php';
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        $params = $widgets['product-reviews']['params'] ?? [];
        self::assertSame('stack', $params['layout_mode']['default'] ?? null);
        self::assertSame('right', $params['form_position']['default'] ?? null);
        self::assertSame('1', $params['form_collapsed']['default'] ?? null);
        $config = $widgets['product-reviews']['default_injections'][0]['config'] ?? [];
        self::assertSame('stack', $config['layout_mode'] ?? null);
        self::assertSame('1', $config['form_collapsed'] ?? null);
    }

    public function testJsBuildsNativeRatingStarsFromSchema(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 5) . '/view/statics/js/widgets/product-reviews.js');
        self::assertStringContainsString('schemaFields.map(fieldInput)', $js);
        self::assertStringContainsString("field.type === 'rating'", $js);
        self::assertStringContainsString("radio.type = 'radio'", $js);
        self::assertStringContainsString('radiogroup', $js);
        self::assertStringContainsString('keydown', $js);
        self::assertStringContainsString('data-review-write-toggle', $js);
        self::assertStringContainsString('openReviewLightbox', $js);
        self::assertStringContainsString('UI.dialog.open', $js);
        self::assertStringContainsString('createReviewMediaThumb', $js);
        self::assertStringContainsString('bindFormCollapseControl', $js);
        self::assertStringContainsString('setAverageText', $js);
        self::assertStringNotContainsString('!average || !count', $js);
        self::assertStringNotContainsString("field.type === 'rating'){input=make('select')", $js);
    }

    public function testJsDefaultPagerReplacesInfiniteScroll(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 5) . '/view/statics/js/widgets/product-reviews.js');
        $css = (string)file_get_contents(dirname(__DIR__, 5) . '/view/statics/css/widgets/product-reviews.css');
        self::assertStringContainsString('goToPage', $js);
        self::assertStringContainsString('renderPager', $js);
        self::assertStringContainsString('data-review-page-prev', $js);
        self::assertStringContainsString('data-review-page-next', $js);
        self::assertStringContainsString('data-review-pager', $js);
        self::assertStringNotContainsString('loadMoreReviews', $js);
        self::assertStringNotContainsString('bindInfiniteScroll', $js);
        self::assertStringNotContainsString('data-review-scroll-sentinel', $js);
        self::assertStringNotContainsString("append: true", $js);
        self::assertStringContainsString('.weline-review__pager', $css);
        self::assertStringNotContainsString('.weline-review__items.is-scrollable', $css);
        self::assertStringNotContainsString('overflow-y: scroll', $css);

        $tpl = (string)file_get_contents(dirname(__DIR__, 5) . '/view/templates/frontend/widgets/product-reviews.phtml');
        self::assertStringContainsString("'prevPage'", $tpl);
        self::assertStringContainsString("'nextPage'", $tpl);
        self::assertStringContainsString("'pageLabelPrefix'", $tpl);
        self::assertStringContainsString('data-review-pager', $tpl);
        self::assertStringContainsString('20260915-review-compact1', $tpl);
        self::assertStringNotContainsString("'scrollForMore'", $tpl);

        $modules = (string)file_get_contents(dirname(__DIR__, 5) . '/view/statics/frontend/weline.modules.js');
        self::assertStringContainsString('product-reviews.v20260904-pager2.js', $modules);
    }
}
