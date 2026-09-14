<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class YouMayLikeWidgetContractTest extends TestCase
{
    public function testRegistrationPinsProductOwnedDefaultInjectionAndListingCard(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = require $path;
        $widget = $widgets['you-may-like'] ?? [];
        self::assertSame('you-may-like', $widget['code'] ?? null);
        self::assertSame('Weline_Product::templates/frontend/widgets/you-may-like.phtml', $widget['template'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product-you-may-like', $injection['slot'] ?? null);
        self::assertSame('product', $injection['layout_type'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));

        $tpl = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/you-may-like.phtml';
        $source = (string)file_get_contents($tpl);
        self::assertStringContainsString('youMayLikeCards', $source);
        self::assertStringContainsString('StorefrontProductWidgetCatalog', $source);
        self::assertStringContainsString('class="wpc-listing-card wym-card"', $source);
        self::assertStringContainsString('density="standard"', $source);
        self::assertStringContainsString('show-sku="true"', $source);
        self::assertStringContainsString('data-testid="storefront-you-may-like"', $source);
        self::assertStringContainsString("WidgetI18n::label('根据当前商品为你推荐'", $source);
        self::assertStringContainsString('WidgetI18n::label($titleSource', $source);
        self::assertStringNotContainsString("translate('根据当前商品为你推荐', 'zh_Hans_CN'", $source);
        self::assertStringNotContainsString('ThemeDemoCatalog::products($limit, 40)', $source);
    }
}
