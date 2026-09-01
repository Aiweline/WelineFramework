<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class PayPalConfigTemplateContractTest extends TestCase
{
    public function testPayPalConfigTemplateExposesSandboxFields(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $template = (string) file_get_contents(
            $moduleRoot . '/extends/module/Weline_SystemConfig/Config/backend/paypal.phtml'
        );
        $provider = (string) file_get_contents(
            $moduleRoot . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php'
        );

        self::assertStringContainsString('PayPal 沙箱环境', $template);
        self::assertStringContainsString('无需去 PayPal Developer 手动创建 App', $template);
        self::assertStringContainsString('payment/backend/connect/authorize?method_code=paypal&environment=sandbox', $template);
        self::assertStringContainsString('沙箱一键授权', $template);
        self::assertStringContainsString('action-label-connected="重新授权"', $template);
        self::assertStringContainsString('connected-key="payment/method/paypal/sandbox_oauth_connected_at"', $template);
        self::assertStringContainsString('connected-key="payment/method/paypal/live_oauth_connected_at"', $template);
        self::assertStringContainsString("return 'paypal';", $provider);
        self::assertStringContainsString("'checkout_template_code' => 'paypal'", $provider);
        self::assertStringContainsString('ProviderConnectInterface', $provider);
    }
}
