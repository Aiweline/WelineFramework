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
        self::assertSame('product-reviews', $widget['slot'] ?? null);
        self::assertSame('comment', $widget['type'] ?? null);
        self::assertSame('Weline_Review::templates/frontend/widgets/product-reviews.phtml', $widget['template'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product-reviews', $injection['slot'] ?? null);
        self::assertSame('product', $injection['layout_type'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
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
        self::assertStringContainsString('Weline_Review::js/widgets/product-reviews.js', $source);
        self::assertStringContainsString('StorefrontCatalogViewService', $source);
        self::assertStringContainsString('publishedOfferBySlug', $source);
        self::assertStringContainsString('global_offer_uuid', $source);

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
}
