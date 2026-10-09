<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Service\CustomerAuthReturnUrlService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

final class CustomerAuthReturnUrlServiceTest extends TestCase
{
    public function testFormatRedirectKeepsCurrentCurrencyPrefix(): void
    {
        $service = $this->serviceForCurrentUrl('http://127.0.0.1:9514/USD/customer/account/register');

        self::assertSame('/USD/customer/account', $service->formatRedirect('customer/account/index'));
    }

    public function testFormatRedirectKeepsCurrentLocaleAndCurrencyPrefix(): void
    {
        $service = $this->serviceForCurrentUrl(
            'http://127.0.0.1:9514/zh_Hans_CN/USD/customer/account/register'
        );

        self::assertSame(
            '/zh_Hans_CN/USD/customer/account',
            $service->formatRedirect('customer/account/index')
        );
    }

    public function testFormatRedirectDoesNotDuplicateAnExplicitPrefix(): void
    {
        $service = $this->serviceForCurrentUrl('http://127.0.0.1:9514/USD/customer/account/login');

        self::assertSame(
            '/USD/customer/account',
            $service->formatRedirect('/USD/customer/account/index')
        );
        self::assertSame(
            '/USD/products/demo',
            $service->formatRedirect('/USD/products/demo')
        );
    }

    public function testFormatInternalNavigationPrefixesFallback(): void
    {
        $service = $this->serviceForCurrentUrl('http://127.0.0.1:9514/USD/customer/account/login');

        self::assertSame('/USD/customer/account', $service->formatInternalNavigation(''));
    }

    public function testBuildAuthPageUrlKeepsCurrentCurrencyPrefixAndQuery(): void
    {
        $service = $this->serviceForCurrentUrl('http://127.0.0.1:9514/USD/customer/account/login');

        self::assertSame(
            '/USD/customer/account/register?redirect_url=products%2Fbse&campaign=dealer',
            $service->buildAuthPageUrl(
                '/customer/account/register',
                'products/bse',
                ['campaign' => 'dealer']
            )
        );
    }

    public function testBuildAuthPageUrlKeepsCurrentLocaleAndCurrencyPrefix(): void
    {
        $service = $this->serviceForCurrentUrl(
            'http://127.0.0.1:9514/zh_Hans_CN/USD/customer/account/login'
        );

        self::assertSame(
            '/zh_Hans_CN/USD/customer/account/forgot-password',
            $service->buildAuthPageUrl('/customer/account/forgot-password')
        );
    }

    public function testBuildAuthPageUrlCollapsesDuplicatedLocalePrefixOnCurrentUrl(): void
    {
        $service = $this->serviceForCurrentUrl(
            'http://127.0.0.1:9514/en_US/en_US/customer/account/login'
        );

        self::assertSame(
            '/en_US/customer/account/login',
            $service->buildAuthPageUrl('customer/account/login')
        );
    }

    public function testFormatAuthSuccessRedirectRewritesStaleLocaleToCurrentStorefront(): void
    {
        // Shopper is on Arabic login; session still holds a Hindi account target.
        $service = $this->serviceForCurrentUrl(
            'http://127.0.0.1:9514/ar_SA/customer/account/login'
        );

        self::assertSame(
            '/ar_SA/customer/account?w_auth=1',
            $service->formatAuthSuccessRedirect('/hi_IN/customer/account')
        );
        self::assertSame(
            '/ar_SA/customer/account?w_auth=1',
            $service->formatAuthSuccessRedirect('hi_IN/customer/account/index')
        );
    }

    public function testFormatAuthSuccessRedirectUsesRefererWhenQueryHasNoLocale(): void
    {
        $service = $this->serviceForCurrentUrl(
            'http://127.0.0.1:9514/api/framework/query-bin',
            'http://127.0.0.1:9514/ar_SA/'
        );

        self::assertSame(
            '/ar_SA/customer/account?w_auth=1',
            $service->formatAuthSuccessRedirect('/hi_IN/customer/account')
        );
    }

    public function testFormatAuthSuccessRedirectAppendsAuthRefreshSignal(): void
    {
        $service = $this->serviceForCurrentUrl('http://127.0.0.1:9514/USD/customer/account/login');

        self::assertSame(
            '/USD?w_auth=1',
            $service->formatAuthSuccessRedirect('/')
        );
        self::assertSame(
            '/USD/customer/account?w_auth=1',
            $service->formatAuthSuccessRedirect('customer/account/index')
        );
    }

    public function testWithAuthRefreshSignalPreservesAbsoluteOrigin(): void
    {
        $service = $this->serviceForCurrentUrl('http://127.0.0.1:9514/customer/account/logout');

        self::assertSame(
            'https://shop.test:9555/ar_SA/customer/account/login?w_auth=0',
            $service->withAuthRefreshSignal(
                'https://shop.test:9555/ar_SA/customer/account/login',
                CustomerAuthReturnUrlService::AUTH_REFRESH_LOGOUT_VALUE
            )
        );
    }

    public function testFormatAuthInvalidRedirectUsesRefererLocaleWhenRequestHasNone(): void
    {
        $service = $this->serviceForCurrentUrl(
            'http://127.0.0.1:9514/customer/account/logout',
            'http://127.0.0.1:9514/ar_SA/products/demo'
        );

        self::assertSame(
            '/ar_SA/customer/account/login?w_auth=0',
            $service->formatAuthInvalidRedirect('/customer/account/login')
        );
    }

    public function testWithAuthRefreshSignalPreservesExistingQueryAndFragment(): void
    {
        $service = $this->serviceForCurrentUrl('http://127.0.0.1:9514/customer/account/login');

        self::assertSame(
            '/products/demo?campaign=dealer&w_auth=1#reviews',
            $service->withAuthRefreshSignal('/products/demo?campaign=dealer#reviews')
        );
        self::assertSame(
            '/?w_auth=1',
            $service->withAuthRefreshSignal('/?w_auth=0')
        );
    }

    public function testFormatAuthInvalidRedirectAppendsLogoutSignalOnLoginRoute(): void
    {
        $service = $this->serviceForCurrentUrl('http://127.0.0.1:9514/USD/customer/account/index');

        self::assertSame(
            '/USD/customer/account/login?w_auth=0',
            $service->formatAuthInvalidRedirect('/customer/account/login')
        );
        self::assertSame(
            '/USD?w_auth=0',
            $service->formatAuthInvalidRedirect('/')
        );
    }

    public function testForceLocalizationPrefixAppliesWhenCallbackHasNoPathPrefix(): void
    {
        $service = $this->serviceForCurrentUrl(
            'http://127.0.0.1:9514/customer/account/social-login/callback'
        );
        $service->forceLocalizationPrefix('/USD/en_US');

        self::assertSame(
            '/USD/en_US/customer/account',
            $service->formatInternalNavigation('')
        );
        self::assertSame(
            '/USD/en_US/customer/account/index#social-login',
            $service->formatInternalNavigation('/customer/account/index#social-login')
        );
        self::assertSame(
            '/USD/en_US/products/demo?w_auth=1',
            $service->formatAuthSuccessRedirect('products/demo')
        );
    }

    public function testForceLocalizationPrefixRewritesWrongReturnUrlLocale(): void
    {
        $service = $this->serviceForCurrentUrl(
            'http://127.0.0.1:9514/customer/account/social-login/callback'
        );
        $service->forceLocalizationPrefix('/ar_SA');

        self::assertSame(
            '/ar_SA/products/demo?w_auth=1',
            $service->formatAuthSuccessRedirect('/hi_IN/products/demo')
        );
        self::assertSame(
            '/ar_SA/customer/account',
            $service->formatInternalNavigation('/hi_IN/customer/account')
        );
    }

    public function testForceLocalizationPrefixEmptyKeepsDefaultLocale(): void
    {
        $service = $this->serviceForCurrentUrl(
            'http://127.0.0.1:9514/customer/account/social-login/callback'
        );
        $service->forceLocalizationPrefix('');

        self::assertSame('/customer/account', $service->formatInternalNavigation(''));
    }

    public function testNormalizeTargetBlocksSocialLoginRoutes(): void
    {
        $service = $this->serviceForCurrentUrl('http://127.0.0.1:9514/products/demo');

        self::assertSame('', $service->normalizeTarget('/customer/account/social-login/callback'));
        self::assertSame('', $service->normalizeTarget('/customer/account/social-login/choose'));
        self::assertSame('products/demo', $service->normalizeTarget('/products/demo'));
    }

    public function testNormalizeTargetPreservesUtf8QueryWithoutParseUrlCorruption(): void
    {
        $service = $this->serviceForCurrentUrl('https://p05113ef3.test.weline.com/products');
        $encoded = '/product/demo?size=s&style_type=' . rawurlencode('上襦加灰蓝裙子');
        $decoded = '/product/demo?size=s&style_type=上襦加灰蓝裙子';

        $fromEncoded = $service->normalizeTarget($encoded);
        $fromDecoded = $service->normalizeTarget($decoded);

        self::assertNotSame('', $fromEncoded);
        self::assertNotSame('', $fromDecoded);
        self::assertSame(1, preg_match('//u', $fromEncoded));
        self::assertSame(1, preg_match('//u', $fromDecoded));
        self::assertStringNotContainsString("\x5f襦", $fromEncoded);
        self::assertStringNotContainsString("\x5f襦", $fromDecoded);
        self::assertStringContainsString('style_type=', $fromEncoded);
        self::assertTrue(
            str_contains($fromEncoded, rawurlencode('上襦加灰蓝裙子'))
            || str_contains($fromEncoded, '上襦加灰蓝裙子')
        );
        self::assertStringContainsString('上襦加灰蓝裙子', $fromDecoded);
    }

    private function serviceForCurrentUrl(string $currentUrl, string $referer = ''): CustomerAuthReturnUrlService
    {
        $url = $this->createMock(Url::class);
        $url->method('getCurrentUrl')->willReturn($currentUrl);

        $request = $this->createMock(Request::class);
        $request->method('getUrlBuilder')->willReturn($url);
        $request->method('getReferer')->willReturn($referer);

        return new CustomerAuthReturnUrlService($request);
    }
}
