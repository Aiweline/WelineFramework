<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Payment\Service\PaymentScopeConfigService;

final class PaymentScopeConfigEnvironmentTest extends TestCase
{
    public function testResolveScopeIgnoresRequestAndOldGlobalEnvironment(): void
    {
        $service = new PaymentScopeConfigService(
            null,
            null,
            static fn (string $scope): string => 'live',
        );

        $missing = $service->resolveScope([]);
        $requested = $service->resolveScope(['environment' => 'live', 'scope' => 'shop.main']);

        self::assertSame('sandbox', $missing['environment']);
        self::assertSame('sandbox', $requested['environment']);
        self::assertSame('shop.main.default', $requested['scope']);
    }

    public function testSavedMethodEnvironmentWinsPerScopeAndIgnoresRequest(): void
    {
        $service = new PaymentScopeConfigService(
            null,
            null,
            static fn (): string => 'live',
        );
        $build = new ReflectionMethod(PaymentScopeConfigService::class, 'buildRuntimeOverride');
        $build->setAccessible(true);

        $website = $build->invoke(
            $service,
            'paypal',
            ['environment' => 'live'],
            'shop.__website__.default',
            'sandbox',
            'Weline_Payment',
        );
        $global = $build->invoke(
            $service,
            'paypal',
            ['environment' => 'sandbox'],
            'default.default.default',
            'live',
            'Weline_Payment',
        );
        $empty = $build->invoke(
            $service,
            'paypal',
            [],
            'shop.__website__.default',
            'live',
            'Weline_Payment',
        );

        self::assertSame('live', $website['environment']);
        self::assertSame('shop.__website__.default', $website['scope']);
        self::assertSame('sandbox', $global['environment']);
        self::assertSame('sandbox', $empty['environment']);
        self::assertSame('sandbox', $service->selectMethodEnvironment('live', []));
        self::assertSame('live', $service->selectMethodEnvironment('sandbox', ['environment' => 'live']));
    }

    public function testProviderTemplatesDeclareScopedEnvironment(): void
    {
        $root = dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend';
        foreach (['paypal', 'stripe', 'fake_card'] as $code) {
            $template = (string) file_get_contents($root . '/' . $code . '.phtml');
            self::assertStringContainsString('payment/method/' . $code . '/environment', $template);
            self::assertStringContainsString('scope="global,website,store"', $template);
            self::assertStringContainsString('options="sandbox:沙盒,live:正式"', $template);
        }

        $general = (string) file_get_contents($root . '/general.phtml');
        $assets = (string) file_get_contents($root . '/assets.phtml');
        $index = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/Method/index.phtml');

        self::assertStringNotContainsString('payment/general/default_environment', $general);
        self::assertStringNotContainsString('payment/asset/credit/environment', $assets);
        self::assertStringContainsString("payment/method/' . \$methodCode . '/environment'", $index);
        self::assertStringContainsString('field="environmentField"', $index);
    }

    public function testInvalidConfiguredValueFallsBackToSandbox(): void
    {
        $service = new PaymentScopeConfigService(
            null,
            null,
            static fn (string $scope): string => 'staging',
        );

        self::assertSame('sandbox', $service->configuredEnvironment('shop.main.default'));
    }

    public function testCheckoutDoesNotForceSandboxWhenRequestOmitsEnvironment(): void
    {
        $root = dirname(__DIR__, 4);
        $checkout = (string) file_get_contents($root . '/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php');
        $b2b = (string) file_get_contents($root . '/B2B/extends/module/Weline_Framework/Query/B2BQueryProvider.php');

        self::assertStringNotContainsString("?? 'sandbox'", $checkout);
        self::assertStringNotContainsString("?? 'sandbox'", $b2b);
    }
}
