<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Order view must not promote raw service_code as the primary shipping label.
 */
final class BackendOrderViewShippingMethodHumanizeContractTest extends TestCase
{
    public function testViewTemplateShowsHumanLabelAndCopyableCode(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Order/view.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('payment_chrome', $src);
        self::assertStringContainsString('shipping_method_label', $src);
        self::assertStringContainsString('order-view-shipping-method-label', $src);
        self::assertStringContainsString('order-view-shipping-method-code', $src);
        self::assertStringContainsString('data-copy-text', $src);
        self::assertStringContainsString('点击复制服务码', $src);
        self::assertStringContainsString('navigator.clipboard', $src);
        // Must not dump raw shipping_method as the only cell content.
        self::assertDoesNotMatchRegularExpression(
            '/配送方式[\s\S]{0,200}<\?= \$viewShippingMethod !== \'\' \? htmlspecialchars\(\$viewShippingMethod\)/',
            $src,
        );
    }

    public function testViewControllerAssignsPaymentChrome(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Backend/Order.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertMatchesRegularExpression(
            '/function view\(\)[\s\S]+BackendOrderPaymentChromePresenter[\s\S]+assign\(\'payment_chrome\'/',
            $src,
        );
    }
}
