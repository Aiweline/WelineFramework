<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Extends\Module\Weline_Framework\Security\Csp\PaymentVendorsCsp;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\StripeProvider;

final class PaymentProviderCspDirectivesContractTest extends TestCase
{
    public function testBuiltInProvidersDeclareCspDirectives(): void
    {
        /** @var PayPalProvider $paypal */
        $paypal = (new \ReflectionClass(PayPalProvider::class))->newInstanceWithoutConstructor();
        $p = $paypal->cspDirectives();
        self::assertContains('https://www.paypal.com', $p['script-src'] ?? []);
        self::assertContains('https://www.paypalobjects.com', $p['script-src'] ?? []);
        self::assertContains('https://www.sandbox.paypal.com', $p['frame-src'] ?? []);
        self::assertContains('https://api-m.paypal.com', $p['connect-src'] ?? []);
        self::assertContains('https://api-m.sandbox.paypal.com', $p['connect-src'] ?? []);

        /** @var StripeProvider $stripe */
        $stripe = (new \ReflectionClass(StripeProvider::class))->newInstanceWithoutConstructor();
        $s = $stripe->cspDirectives();
        self::assertContains('https://js.stripe.com', $s['script-src'] ?? []);
        self::assertContains('https://api.stripe.com', $s['connect-src'] ?? []);
        self::assertContains('https://hooks.stripe.com', $s['frame-src'] ?? []);
        self::assertContains('https://checkout.stripe.com', $s['frame-src'] ?? []);

        /** @var FakeProvider $fake */
        $fake = (new \ReflectionClass(FakeProvider::class))->newInstanceWithoutConstructor();
        self::assertSame([], $fake->cspDirectives());
    }

    public function testPaymentVendorsCspAggregatesRegistryProviders(): void
    {
        /** @var PayPalProvider $paypal */
        $paypal = (new \ReflectionClass(PayPalProvider::class))->newInstanceWithoutConstructor();
        /** @var StripeProvider $stripe */
        $stripe = (new \ReflectionClass(StripeProvider::class))->newInstanceWithoutConstructor();
        /** @var FakeProvider $fake */
        $fake = (new \ReflectionClass(FakeProvider::class))->newInstanceWithoutConstructor();

        $contribution = (new PaymentVendorsCsp(static fn (): array => [$paypal, $stripe, $fake]))->contribution();
        $script = $contribution->directives['script-src'] ?? [];
        $connect = $contribution->directives['connect-src'] ?? [];
        self::assertContains('https://www.paypal.com', $script);
        self::assertContains('https://www.paypalobjects.com', $script);
        self::assertContains('https://js.stripe.com', $script);
        self::assertContains('https://api-m.sandbox.paypal.com', $connect);
        self::assertContains('https://api.stripe.com', $connect);
    }

    public function testProviderInterfaceDeclaresCspDirectives(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Interface/ProviderInterface.php'
        );
        self::assertStringContainsString('function cspDirectives(): array', $src);
    }
}
