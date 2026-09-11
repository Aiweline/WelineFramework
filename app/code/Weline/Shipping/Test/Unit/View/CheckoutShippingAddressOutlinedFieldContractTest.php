<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 结账收货地址编辑器须用标准 w-field Outlined floating：空态依赖 placeholder。
 */
final class CheckoutShippingAddressOutlinedFieldContractTest extends TestCase
{
    public function testCheckoutShippingAddressEditorUsesWFieldNotchMarkup(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-shipping-address.phtml';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString('class="w-field w-shipping-checkout-address__field"', $src);
        self::assertStringContainsString('class="w-field__label"', $src);
        self::assertStringNotContainsString('w-form-label', $src);
        self::assertStringNotContainsString('data-label-layout="stacked"', $src);
    }

    public function testCheckoutShippingAddressCssDoesNotForceStackedLabels(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/css/widgets/checkout-shipping-address.css';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString('--weline-theme-field-label-bg', $src);
        self::assertStringNotContainsString('.w-form-label', $src);
    }

    public function testCheckoutShippingContactFieldsHavePlaceholdersForFloatingLabel(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-shipping-address.phtml';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);

        // PHP 短标签内含 `>`，不能用简单 name=...[^>]*placeholder 正则扫整段。
        self::assertStringContainsString("placeholder=\"<?= \$esc(__('请输入收货人姓名')) ?>\"", $src);
        self::assertStringContainsString("placeholder=\"<?= \$esc(__('请输入联系电话')) ?>\"", $src);
        self::assertStringContainsString("placeholder=\"<?= \$esc(__('请输入邮箱地址')) ?>\"", $src);
        self::assertStringContainsString("placeholder=\"<?= \$esc(__('请输入邮政编码')) ?>\"", $src);
        self::assertMatchesRegularExpression('/name="name"[\\s\\S]*?placeholder=/', $src);
        self::assertMatchesRegularExpression('/name="phone"[\\s\\S]*?placeholder=/', $src);
        self::assertMatchesRegularExpression('/name="email"[\\s\\S]*?placeholder=/', $src);
        self::assertMatchesRegularExpression('/name="postal_code"[\\s\\S]*?placeholder=/', $src);
    }
}
