<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service\SocialLogin;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Service\SocialLogin\SocialLoginStorefrontLocale;

final class SocialLoginStorefrontLocaleTest extends TestCase
{
    public function testPrefixFromLocalizedPathAndAbsoluteUrl(): void
    {
        self::assertSame('/en_US', SocialLoginStorefrontLocale::prefixFromUrl('/en_US/customer/account/login'));
        self::assertSame(
            '/USD/en_US',
            SocialLoginStorefrontLocale::prefixFromUrl('https://shop.test/USD/en_US/products/demo?x=1')
        );
        self::assertSame('', SocialLoginStorefrontLocale::prefixFromUrl('/customer/account/login'));
    }

    public function testFirstNonEmptyPrefersReturnUrlWhenStartHasNoPrefix(): void
    {
        self::assertSame(
            '/en_US',
            SocialLoginStorefrontLocale::firstNonEmpty(
                '',
                '/en_US/customer/account/login',
                'https://shop.test/customer/account/login'
            )
        );
        self::assertSame('hi_IN', SocialLoginStorefrontLocale::languageFromPrefix('/hi_IN'));
        self::assertSame('en_US', SocialLoginStorefrontLocale::languageFromPrefix('/USD/en_US'));
        self::assertSame('ar_SA', SocialLoginStorefrontLocale::languageFromPrefix('/ar_SA'));
    }

    public function testFirstNonEmptyPrefersReturnUrlOverWrongStartPrefix(): void
    {
        // Simulate OAuth start hit as /hi_IN/... while shopper return_url is Arabic.
        self::assertSame(
            '/ar_SA',
            SocialLoginStorefrontLocale::firstNonEmpty(
                '/ar_SA/product/demo',
                'https://shop.test/ar_SA/product/demo',
                '/hi_IN'
            )
        );
    }
}
