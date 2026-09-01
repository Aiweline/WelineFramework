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
            '/USD/customer/account/index',
            $service->formatRedirect('/USD/customer/account/index')
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

    private function serviceForCurrentUrl(string $currentUrl): CustomerAuthReturnUrlService
    {
        $url = $this->createMock(Url::class);
        $url->method('getCurrentUrl')->willReturn($currentUrl);

        $request = $this->createMock(Request::class);
        $request->method('getUrlBuilder')->willReturn($url);

        return new CustomerAuthReturnUrlService($request);
    }
}
