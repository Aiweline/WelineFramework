<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Mini-cart SSR must render PDP-aligned deal chrome (now + was + campaign).
 */
final class MiniCartDealChromeContractTest extends TestCase
{
    public function testMiniCartTemplateRendersDealPriceSlots(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('mini-cart-drawer__line-price-row', $src);
        self::assertStringContainsString('mini-cart-drawer__line-price-now', $src);
        self::assertStringContainsString('mini-cart-drawer__line-price-was', $src);
        self::assertStringContainsString('mini-cart-drawer__line-price-campaign', $src);
        self::assertStringContainsString('compare_at_minor', $src);
        self::assertStringContainsString('campaign_label', $src);
    }

    public function testMiniCartJsBuildsDealChrome(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/widgets/mini-cart-icon.js';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('mini-cart-drawer__line-price-row', $src);
        self::assertStringContainsString('mini-cart-drawer__line-price-was', $src);
        self::assertStringContainsString('mini-cart-drawer__line-price-campaign', $src);
        self::assertStringContainsString('compare_at_minor', $src);
        self::assertStringContainsString('campaign_label', $src);
        self::assertStringContainsString('ensureDrawerCss', $src);
        self::assertStringContainsString('data-weline-mini-cart-drawer-live', $src);
    }
}
