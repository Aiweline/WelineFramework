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

    private function serviceForCurrentUrl(string $currentUrl): CustomerAuthReturnUrlService
    {
        $url = $this->createMock(Url::class);
        $url->method('getCurrentUrl')->willReturn($currentUrl);

        $request = $this->createMock(Request::class);
        $request->method('getUrlBuilder')->willReturn($url);

        return new CustomerAuthReturnUrlService($request);
    }
}
