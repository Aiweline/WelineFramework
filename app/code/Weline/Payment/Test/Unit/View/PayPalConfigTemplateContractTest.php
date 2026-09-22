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
        self::assertStringContainsString('payment/method/paypal/express_logo', $template);
        self::assertStringContainsString('快捷支付 Logo', $template);
        self::assertStringContainsString('payment/method/paypal/express_enabled', $template);
        self::assertStringContainsString('启用 PayPal 快捷支付', $template);
        self::assertStringContainsString('payment/method/paypal/google_pay_enabled', $template);
        self::assertStringContainsString('payment/method/paypal/apple_pay_enabled', $template);
        self::assertStringContainsString('启用 Google Pay（PayPal）', $template);
        self::assertStringContainsString('启用 Apple Pay（PayPal）', $template);
        self::assertMatchesRegularExpression(
            '/key="payment\/method\/paypal\/google_pay_enabled"[\s\S]*?default="1"/',
            $template
        );
        self::assertMatchesRegularExpression(
            '/key="payment\/method\/paypal\/apple_pay_enabled"[\s\S]*?default="1"/',
            $template
        );
        self::assertMatchesRegularExpression(
            '/key="payment\/method\/paypal\/google_pay_enabled"[\s\S]*?scope="global,website,store"/',
            $template
        );
        self::assertStringContainsString('无需去 PayPal Developer 手动创建 App', $template);
        self::assertStringContainsString('payment/backend/connect/authorize?method_code=paypal&environment=sandbox', $template);
        self::assertStringContainsString('沙箱一键授权', $template);
        self::assertStringContainsString('action-label-connected="重新授权"', $template);
        self::assertStringContainsString('connected-key="payment/method/paypal/sandbox_oauth_connected_at"', $template);
        self::assertStringContainsString('connected-key="payment/method/paypal/live_oauth_connected_at"', $template);
        self::assertStringContainsString('发货物流回传（Add Tracking）', $template);
        self::assertStringContainsString('默认启用', $template);
        self::assertStringContainsString('无需在 PayPal App Features 勾选', $template);
        self::assertStringContainsString('/v2/checkout/orders/{order_id}/track', $template);
        self::assertStringContainsString('payment:paypal:sync-tracking', $template);
        self::assertStringNotContainsString('须在 PayPal Developer 的 REST App 打开 Shipping', $template);
        self::assertStringContainsString("return 'paypal';", $provider);
        self::assertStringContainsString("'checkout_template_code' => 'paypal'", $provider);
        self::assertStringContainsString('ProviderConnectInterface', $provider);

        foreach (['sandbox_client_secret', 'live_client_secret'] as $secretField) {
            self::assertTrue(
                (bool) preg_match(
                    '/key="payment\/method\/paypal\/' . preg_quote($secretField, '/') . '"([\s\S]*?)\/>/',
                    $template,
                    $m
                ),
                $secretField . ' field missing'
            );
            self::assertStringContainsString('value-type="encrypted"', $m[1]);
            self::assertStringContainsString('type="secret"', $m[1]);
            self::assertStringNotContainsString('value-type="string"', $m[1]);
        }
    }
}
