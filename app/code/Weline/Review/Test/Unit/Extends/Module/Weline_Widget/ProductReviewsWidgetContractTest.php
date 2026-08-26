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
        self::assertStringContainsString('data-review-root', $source);
        self::assertStringContainsString('Weline_Review::css/widgets/product-reviews.css', $source);
        self::assertStringContainsString('Weline_Review::js/widgets/product-reviews.js', $source);
        self::assertStringContainsString('StorefrontCatalogViewService', $source);
        self::assertStringContainsString('publishedOfferBySlug', $source);
        self::assertStringContainsString('global_offer_uuid', $source);

        $css = (string)file_get_contents(dirname(__DIR__, 5) . '/view/statics/css/widgets/product-reviews.css');
        self::assertStringContainsString('--color-bg-primary', $css);
        self::assertStringContainsString('--color-accent', $css);
        self::assertStringNotContainsString('#0b0d0f', $css);
        self::assertStringNotContainsString('#df2029', $css);
    }

    public function testJsBuildsNativeRatingStarsFromSchema(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 5) . '/view/statics/js/widgets/product-reviews.js');
        self::assertStringContainsString('schemaFields.map(fieldInput)', $js);
        self::assertStringContainsString("field.type === 'rating'", $js);
        self::assertStringContainsString("radio.type = 'radio'", $js);
        self::assertStringContainsString('radiogroup', $js);
        self::assertStringContainsString('keydown', $js);
        self::assertStringNotContainsString("field.type === 'rating'){input=make('select')", $js);
    }
}
