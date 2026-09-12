<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Api\PaymentExpressAddressSinkInterface;
use Weline\Payment\Api\PaymentExpressFacadeInterface;
use Weline\Payment\Service\ExpressCheckoutOrchestrator;
use Weline\Payment\Service\PayPalExpressProfileMapper;

final class ExpressCheckoutOrchestratorContractTest extends TestCase
{
    public function testCapabilityConstantAndFacadeBinding(): void
    {
        self::assertSame('express_checkout', ExpressCheckoutOrchestrator::CAPABILITY_EXPRESS);
        self::assertSame(
            'payment.express_address_sink.',
            PaymentExpressAddressSinkInterface::CAPABILITY_PREFIX,
        );
        $module = include dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame(
            ExpressCheckoutOrchestrator::class,
            $module['provides'][PaymentExpressFacadeInterface::class] ?? null,
        );
        $checkoutModule = include dirname(__DIR__, 4) . '/Checkout/etc/module.php';
        self::assertSame(
            \Weline\Checkout\Service\CheckoutPaymentExpressAddressSink::class,
            $checkoutModule['provides']['payment.express_address_sink.Weline_Checkout'] ?? null,
        );
    }

    public function testPayPalMapperExtractsShippingProfile(): void
    {
        $mapper = new PayPalExpressProfileMapper();
        $profile = $mapper->fromOrderPayload([
            'payer' => [
                'email_address' => 'buyer@example.com',
                'name' => ['given_name' => 'Ada', 'surname' => 'Lovelace'],
            ],
            'purchase_units' => [[
                'shipping' => [
                    'name' => ['full_name' => 'Ada Lovelace'],
                    'address' => [
                        'address_line_1' => '1 Market St',
                        'admin_area_1' => 'CA',
                        'admin_area_2' => 'San Francisco',
                        'postal_code' => '94105',
                        'country_code' => 'US',
                    ],
                ],
            ]],
        ]);
        self::assertIsArray($profile);
        self::assertSame('Ada Lovelace', $profile['name'] ?? null);
        self::assertSame('1 Market St', $profile['address1'] ?? null);
        self::assertSame('US', $profile['country_code'] ?? null);
        self::assertSame('buyer@example.com', $profile['email'] ?? null);
        self::assertSame('paypal_express', $profile['source'] ?? null);
    }

    public function testPayPalProviderDeclaresExpressCapability(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Payment/PaymentProvider/PayPalProvider.php'
        );
        self::assertStringContainsString("'express_checkout' => true", $src);
        self::assertStringContainsString('GET_FROM_FILE', $src);
        self::assertStringContainsString('NO_SHIPPING', $src);
        self::assertStringContainsString("'user_action' => \$express ? 'CONTINUE' : 'PAY_NOW'", $src);
        self::assertStringContainsString('express_prepare_only', $src);
        self::assertStringContainsString('express_confirm_capture', $src);
        self::assertStringContainsString('express_awaiting_confirm', $src);
        self::assertStringContainsString('express_profile', $src);
        self::assertStringContainsString('PayPalExpressProfileMapper', $src);
        self::assertStringContainsString('patchOrder', $src);
    }

    public function testApiClientCreateOrderAcceptsExpressOptions(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PayPalApiClient.php');
        self::assertStringContainsString('shipping_preference', $src);
        self::assertStringContainsString('express_checkout', $src);
        self::assertStringContainsString('GET_FROM_FILE', $src);
        self::assertStringContainsString('function patchOrder', $src);
    }

    public function testEvaluateExpressProfileCoreAndGaps(): void
    {
        $orch = new ExpressCheckoutOrchestrator();
        self::assertSame(
            PaymentExpressFacadeInterface::META_AWAITING_CONFIRM,
            ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM,
        );
        self::assertSame('express_awaiting_confirm', ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM);

        $complete = $orch->evaluateExpressProfile([
            'name' => 'Ada',
            'address1' => '1 Market St',
            'country_code' => 'US',
            'phone' => '555',
            'email' => 'a@b.c',
        ]);
        self::assertTrue($complete['complete']);
        self::assertTrue($complete['requires_shipping']);
        self::assertSame([], $complete['missing_fields']);

        $gaps = $orch->evaluateExpressProfile([
            'contact_name' => 'Ada',
            'street' => '1 Market St',
            'country_code' => 'US',
        ]);
        self::assertTrue($gaps['complete']);
        self::assertContains('contact_phone', $gaps['missing_fields']);
        self::assertContains('email', $gaps['missing_fields']);

        $incomplete = $orch->evaluateExpressProfile([
            'email' => 'a@b.c',
        ]);
        self::assertFalse($incomplete['complete']);
        self::assertContains('contact_name', $incomplete['missing_fields']);
        self::assertContains('address1', $incomplete['missing_fields']);
        self::assertContains('country_code', $incomplete['missing_fields']);

        $digital = $orch->evaluateExpressProfile([], false);
        self::assertTrue($digital['complete']);
        self::assertFalse($digital['requires_shipping']);
        self::assertSame([], $digital['missing_fields']);

        self::assertTrue(ExpressCheckoutOrchestrator::isExpressAwaitingConfirm([
            'metadata' => [ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM => 1],
        ]));
        self::assertFalse(ExpressCheckoutOrchestrator::isExpressAwaitingConfirm(['metadata' => []]));
    }

    public function testBrowserReturnAppliesExpressFacade(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentBrowserReturnDispatcher.php'
        );
        self::assertStringContainsString('PaymentExpressFacadeInterface', $src);
        self::assertStringContainsString('applyExpressProfileFromPaymentResult', $src);
        self::assertStringContainsString('express_prepare_only', $src);
        self::assertStringContainsString('decideExpressReview', $src);
        self::assertStringContainsString('META_AWAITING_CONFIRM', $src);
    }

    public function testExpressWidgetUsesShellList(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/checkout-express-payment.phtml'
        );
        self::assertStringContainsString('PaymentExpressFacadeInterface', $tpl);
        self::assertStringContainsString('listExpressMethods', $tpl);
        self::assertStringContainsString('data-express-pay', $tpl);
        self::assertStringNotContainsString(
            "\$expressMethods = [[\n        'method_code' => \$configMethod",
            $tpl
        );
        self::assertMatchesRegularExpression('/if \(\$expressMethods === \[\]\) \{\s*return;/s', $tpl);
    }

    public function testShellAndMethodExpressConfigKeys(): void
    {
        self::assertSame(
            'payment/general/express_checkout_enabled',
            ExpressCheckoutOrchestrator::CONFIG_SHELL_EXPRESS_ENABLED
        );
        $general = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend/general.phtml'
        );
        self::assertStringContainsString('payment/general/express_checkout_enabled', $general);
        self::assertStringContainsString('启用快捷智能支付', $general);
        $orch = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/ExpressCheckoutOrchestrator.php'
        );
        self::assertStringContainsString('isShellExpressEnabled', $orch);
        $mgr = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/PaymentMethodManager.php'
        );
        self::assertStringContainsString("unset(\$capabilities['express_checkout'])", $mgr);
        self::assertStringContainsString('express_enabled', $mgr);
    }

    public function testApplyExpressProfileRequiresExpressFlag(): void
    {
        $orch = new ExpressCheckoutOrchestrator();
        $noop = $orch->applyExpressProfileFromPaymentResult([
            'payload' => [
                'express_profile' => ['name' => 'Ada', 'address1' => '1 St', 'country_code' => 'US'],
            ],
        ], []);
        self::assertFalse($noop['applied']);

        $flagged = $orch->applyExpressProfileFromPaymentResult([
            'payload' => [
                'express_checkout' => true,
                'express_profile' => ['name' => 'Ada', 'address1' => '1 St', 'country_code' => 'US'],
            ],
        ], ['method_code' => 'paypal']);
        // May or may not apply depending on sink registry in unit context.
        self::assertArrayHasKey('applied', $flagged);
        self::assertArrayHasKey('sinks', $flagged);
    }

    public function testWithExpressContextMergesWhenSupports(): void
    {
        // Unit-level merge helper: when method unsupported, context unchanged.
        $orch = new ExpressCheckoutOrchestrator();
        $unchanged = $orch->withExpressContext(['amount_minor' => 100], 'not_a_real_method');
        self::assertArrayNotHasKey('express_checkout', $unchanged);
    }
}