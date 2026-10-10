<?php

declare(strict_types=1);

namespace Weline\Review\Test\Unit\Extends\Module\Weline_Widget;

use PHPUnit\Framework\TestCase;

final class ProductReviewsWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsDefaultInjectionSlot(): void
    {
        $widgetPhp = dirname(__DIR__, 5) . '/extends/module/Weline_Widget/Weline_Review/widget.php';
        $tpl = 'Weline_Review::templates/frontend/widgets/product-reviews.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 5) . '/view/templates/frontend/widgets/product-reviews.phtml');
        self::assertStringContainsString('@widget.code {product-reviews}', $src);
        self::assertStringContainsString('@widget.slot {product-reviews}', $src);
        self::assertStringContainsString('"slot":"product-reviews"', $src);
        self::assertStringContainsString('"layout_type":"product"', $src);
        self::assertStringContainsString('"required":true', $src);
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
        // @media 条件禁止 var(--breakpoint-*)（浏览器忽略整条规则）
        self::assertStringNotContainsString('@media (max-width: var(', $css);
        self::assertStringContainsString('@media (max-width: 768px)', $css);
        self::assertStringContainsString('@media (max-width: 1024px)', $css);
        self::assertStringContainsString('repeat(auto-fit, minmax(min(100%, var(--token-size-12rem)), 1fr))', $css);
        self::assertStringContainsString('writing-mode: horizontal-tb', $css);
        self::assertStringContainsString(
            ".weline-review__write-toggle {\n        justify-self: stretch;\n        width: 100%;",
            $css
        );
        // 窄屏能力条去边框/底，避免部件内再套一层盒
        self::assertStringContainsString(
            ".weline-review__media-capabilities span {\n        min-height: 0;\n        padding: var(--spacing-1) 0;\n        border: 0;\n        border-radius: 0;\n        background: transparent;",
            $css
        );
    }

    public function testWidgetRegistrationExposesLayoutConfigParams(): void
    {
        $widgetPhp = dirname(__DIR__, 5) . '/extends/module/Weline_Widget/Weline_Review/widget.php';
        $tpl = 'Weline_Review::templates/frontend/widgets/product-reviews.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 5) . '/view/templates/frontend/widgets/product-reviews.phtml');
        self::assertStringContainsString('@param layout_mode {default="stack"', $src);
        self::assertStringContainsString('@param form_position {default="right"', $src);
        self::assertStringContainsString('@param form_collapsed {default="1"', $src);
        self::assertStringContainsString('"layout_mode":"stack"', $src);
        self::assertStringContainsString('"form_collapsed":"1"', $src);
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
        self::assertStringContainsString('Weline_Review::css/widgets/product-reviews.css', $tpl);
        self::assertStringNotContainsString("'scrollForMore'", $tpl);

        $modules = (string)file_get_contents(dirname(__DIR__, 5) . '/view/statics/frontend/weline.modules.js');
        self::assertStringContainsString('product-reviews.v20260904-pager2.js', $modules);
    }
}
