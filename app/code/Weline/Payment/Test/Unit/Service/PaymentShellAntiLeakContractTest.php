<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;
use Weline\Payment\Interface\ProviderConnectInterface;
use Weline\Payment\Interface\ProviderInterface;

/**
 * Shell must route by method_code; Callback/Facade must not import gateway OAuth.
 */
final class PaymentShellAntiLeakContractTest extends TestCase
{
    public function testCallbackDoesNotImportGatewayOauth(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Callback.php');
        self::assertStringNotContainsString('use Weline\\Payment\\Service\\PayPalOAuthService', $src);
        self::assertStringNotContainsString('payment/frontend/paypal/return', $src);
        self::assertStringContainsString('PaymentBrowserReturnDispatcher', $src);
    }

    public function testPayPalProviderImplementsConnectAndFakeDoesNot(): void
    {
        self::assertTrue(is_subclass_of(PayPalProvider::class, ProviderInterface::class));
        self::assertTrue(is_subclass_of(PayPalProvider::class, ProviderConnectInterface::class));
        self::assertTrue(is_subclass_of(FakeProvider::class, ProviderInterface::class));
        self::assertFalse(is_subclass_of(FakeProvider::class, ProviderConnectInterface::class));

        $paypalMeta = (new PayPalProvider())->getDisplayMetadata();
        self::assertSame('template', $paypalMeta['checkout_mode'] ?? null);
        self::assertNotSame('', (string) ($paypalMeta['icon_url'] ?? $paypalMeta['icon'] ?? ''));
        $fakeMeta = (new FakeProvider())->getDisplayMetadata();
        self::assertSame('template', $fakeMeta['checkout_mode'] ?? null);
        self::assertSame('fake_card', $fakeMeta['checkout_template_code'] ?? null);
        self::assertNotSame('', (string) ($fakeMeta['icon_url'] ?? $fakeMeta['icon'] ?? ''));
    }

    public function testConnectControllerRequiresMethodCode(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Connect.php');
        self::assertStringContainsString('method_code', $src);
        self::assertStringContainsString('PaymentConnectDispatcher', $src);
        self::assertStringNotContainsString('PayPalOAuthService', $src);
    }

    public function testLegacyPaymentProviderInterfaceIsDeprecated(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Interface/PaymentProviderInterface.php');
        self::assertStringContainsString('@deprecated', $src);
        self::assertStringContainsString('ProviderInterface', $src);
    }
}
