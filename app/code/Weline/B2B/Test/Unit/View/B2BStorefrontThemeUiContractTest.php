<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: B2B Theme ToC/ToB storefront templates exist with required markers.
 */
final class B2BStorefrontThemeUiContractTest extends TestCase
{
    public function testKeyTemplatesContainThemeMarkers(): void
    {
        $switcher = BP . 'app/code/Weline/B2B/view/templates/frontend/widgets/selling-mode-switcher.phtml';
        $deposit = BP . 'app/code/Weline/B2B/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml';
        $hang = BP . 'app/code/Weline/B2B/view/templates/frontend/partials/account-order-hang.phtml';
        $afterPriceHook = BP . 'app/code/Weline/B2B/view/hooks/Weline_Product/frontend/product/detail/after-price.phtml';
        $checkoutHook = BP . 'app/code/Weline/B2B/view/hooks/Weline_Checkout/frontend/layouts/checkout/summary-before.phtml';
        $productInfo = BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml';
        $miniCart = BP . 'app/code/Weline/Theme/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml';
        $css = BP . 'app/code/Weline/B2B/view/statics/css/b2b-storefront.css';
        $js = BP . 'app/code/Weline/B2B/view/statics/js/selling-mode.js';

        foreach ([$switcher, $deposit, $hang, $afterPriceHook, $checkoutHook, $productInfo, $miniCart, $css, $js] as $path) {
            self::assertFileExists($path, $path);
        }

        $switcherContent = (string)file_get_contents($switcher);
        self::assertStringContainsString('data-b2b-selling-mode="1"', $switcherContent);
        self::assertStringContainsString('data-selling-mode', $switcherContent);
        self::assertStringContainsString('selling-mode-segment', $switcherContent);
        self::assertStringContainsString('data-size="sm"', $switcherContent);
        self::assertStringNotContainsString('data-w-width="full"', $switcherContent);
        self::assertStringNotContainsString('selling-mode-badge', $switcherContent);
        self::assertStringContainsString('w-button-group', $switcherContent);
        self::assertStringContainsString('<lang>购买方式</lang>', $switcherContent);
        self::assertStringContainsString('<lang>零售</lang>', $switcherContent);
        self::assertStringContainsString('<lang>批发</lang>', $switcherContent);
        self::assertStringContainsString('w:form', $switcherContent);
        self::assertStringContainsString('data-w-component="drawer"', $switcherContent);
        self::assertStringNotContainsString("<?= __('购买方式') ?>", $switcherContent);

        $depositContent = (string)file_get_contents($deposit);
        self::assertStringContainsString('data-b2b-deposit-note', $depositContent);
        self::assertStringContainsString('w-badge', $depositContent);
        self::assertStringContainsString('<lang>批发订单</lang>', $depositContent);
        self::assertStringContainsString('30%', $depositContent);

        $hangContent = (string)file_get_contents($hang);
        self::assertStringContainsString('data-b2b-account-hang', $hangContent);
        self::assertStringContainsString('w-badge', $hangContent);
        self::assertStringContainsString('purpose=deposit', $hangContent);
        self::assertStringContainsString('purpose=balance', $hangContent);
        self::assertStringContainsString('<lang>支付定金</lang>', $hangContent);
        self::assertStringContainsString('<lang>支付尾款</lang>', $hangContent);

        $productInfoContent = (string)file_get_contents($productInfo);
        self::assertStringContainsString('product-selling-mode', $productInfoContent);
        self::assertStringContainsString('selling-mode-switcher.phtml', $productInfoContent);
        self::assertStringContainsString('data-tob-moq', $productInfoContent);
        self::assertStringContainsString('data-tob-qty-step', $productInfoContent);

        $miniCartContent = (string)file_get_contents($miniCart);
        self::assertStringContainsString('data-cart-type-badge', $miniCartContent);
        self::assertStringContainsString('w-badge', $miniCartContent);
    }
}
