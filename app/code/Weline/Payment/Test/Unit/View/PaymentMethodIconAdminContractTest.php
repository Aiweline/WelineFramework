<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class PaymentMethodIconAdminContractTest extends TestCase
{
    public function testMethodIndexRendersIconColumn(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Method/index.phtml';
        $content = (string) file_get_contents($path);
        self::assertStringContainsString("__('图标')", $content);
        self::assertStringContainsString('getEffectiveDisplayMetadata', $content);
        self::assertStringContainsString('data-testid="payment-method-icon"', $content);
    }

    public function testConfigTemplatesDeclareIconImageField(): void
    {
        foreach (['fake_card.phtml', 'paypal.phtml'] as $file) {
            $path = dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend/' . $file;
            $content = (string) file_get_contents($path);
            self::assertStringContainsString('/icon"', $content);
            self::assertStringContainsString('type="image"', $content);
            self::assertStringContainsString('支付图标', $content);
        }
    }
}
