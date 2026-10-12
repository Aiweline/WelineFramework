<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutDeliveryContextService;

final class CheckoutDeliveryFallbackCountryContractTest extends TestCase
{
    public function testLocaleMapsToWesternAndCnSchemas(): void
    {
        self::assertSame('US', CheckoutDeliveryContextService::defaultCountryFromLocale('en_US'));
        self::assertSame('GB', CheckoutDeliveryContextService::defaultCountryFromLocale('en_GB'));
        self::assertSame('JP', CheckoutDeliveryContextService::defaultCountryFromLocale('ja_JP'));
        self::assertSame('CN', CheckoutDeliveryContextService::defaultCountryFromLocale('zh_Hans_CN'));
        self::assertSame('US', CheckoutDeliveryContextService::defaultCountryFromLocale('en'));
        self::assertSame('CN', CheckoutDeliveryContextService::defaultCountryFromLocale('zh'));
    }

    public function testCurrentCountryFallbackUsesStorefrontResolverNotHardCn(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutDeliveryContextService.php'
        );
        self::assertStringContainsString('resolveStorefrontFallbackCountry', $src);
        self::assertStringContainsString('defaultCountryFromLocale', $src);
        self::assertStringContainsString('currentWebsiteDefaultLanguage', $src);
        self::assertStringContainsString('localeAllowedOnWebsite', $src);
        self::assertStringContainsString(
            'return self::resolveStorefrontFallbackCountry();',
            $src
        );
        // currentCountryCode 末级须走店面回落，禁止在 Cookie 分支后直接裸 return 'CN'
        self::assertMatchesRegularExpression(
            "/COOKIE_LOCATION[\s\S]*?return self::resolveStorefrontFallbackCountry\(\);/",
            $src
        );
    }

    public function testCheckoutWidgetSeedsFallbackCountry(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Shipping/view/templates/frontend/widgets/checkout-shipping-address.phtml'
        );
        self::assertStringContainsString('resolveStorefrontFallbackCountry', $tpl);
        self::assertStringContainsString('$fallbackCountry', $tpl);
    }
}
