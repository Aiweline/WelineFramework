<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Cart page JS must render PDP-aligned deal chrome beside line totals.
 */
final class CartPageDealChromeContractTest extends TestCase
{
    public function testCartPageBuildLineRendersWasAndCampaign(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/cart/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('weline-cart-shell__line-price-row', $src);
        self::assertStringContainsString('weline-cart-shell__line-price-now', $src);
        self::assertStringContainsString('weline-cart-shell__line-price-was', $src);
        self::assertStringContainsString('weline-cart-shell__line-price-campaign', $src);
        self::assertStringContainsString('compare_at_minor', $src);
        self::assertStringContainsString('campaign_label', $src);
    }
}
