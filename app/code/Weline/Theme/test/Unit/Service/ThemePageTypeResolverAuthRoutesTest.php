<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\ThemePageTypeResolver;

/**
 * 账号认证相关 URL 推断 layout 类型回归（无需 HTTP 运行时）。
 */
final class ThemePageTypeResolverAuthRoutesTest extends TestCase
{
    public function testAccountPreviewRouteEqualsLayoutPath(): void
    {
        $resolver = new ThemePageTypeResolver();
        self::assertSame(
            'account',
            $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_ACCOUNT)
        );
        self::assertSame(
            '/account',
            $resolver->getPreviewPathByPageType(ThemeLayout::PAGE_TYPE_ACCOUNT)
        );
    }

    public function testCustomerChallengeUriResolvesToAccountDotChallenge(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            'account.challenge',
            $resolver->resolveLayoutTypeFromUri('/customer/account/challenge?challenge_token=x')
        );
        $this->assertSame(
            'account.challenge',
            $resolver->resolveLayoutTypeFromUri('/zh_CN/customer/account/challenge')
        );
    }

    public function testCustomerLoginUriResolvesToAccountSlashLogin(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            'account/login',
            $resolver->resolveLayoutTypeFromUri('https://shop.example/customer/account/login')
        );
        $this->assertSame(
            'account/login',
            $resolver->mapLayoutTypeToPageType('account/login')
        );
    }

    public function testCustomerRegisterUriResolvesToAccountSlashRegister(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            'account/register',
            $resolver->resolveLayoutTypeFromUri('/customer/account/register')
        );
    }

    public function testCustomerForgotUriResolvesToAccountSlashForgotPassword(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            'account/forgot-password',
            $resolver->resolveLayoutTypeFromUri('/zh_CN/customer/account/forgot-password')
        );
    }

    public function testAccountDashboardUriUsesAccountPageType(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            ThemeLayout::PAGE_TYPE_ACCOUNT,
            $resolver->resolveLayoutTypeFromUri('/customer/account')
        );
    }

    public function testBareProductSlugUriResolvesToProductLayout(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            ThemeLayout::PAGE_TYPE_PRODUCT,
            $resolver->resolveLayoutTypeFromUri('/product/benq-screenbar')
        );
        $this->assertSame(
            ThemeLayout::PAGE_TYPE_PRODUCT,
            $resolver->resolveLayoutTypeFromUri('product/theme-mug')
        );
    }

    public function testLocalePrefixedProductSlugUriResolvesToProductLayout(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            ThemeLayout::PAGE_TYPE_PRODUCT,
            $resolver->resolveLayoutTypeFromUri('/en_US/product/benq-screenbar')
        );
    }

    public function testProductPreviewRouteEqualsLayoutPathNotContentShell(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            'product',
            $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_PRODUCT)
        );
        $this->assertStringNotContainsString(
            'theme-preview/content',
            $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_PRODUCT)
        );
    }

    public function testUnknownLayoutTypeMapsToItselfForDynamicThemeLayouts(): void
    {
        $resolver = new ThemePageTypeResolver();

        $this->assertSame(
            'e2e_custom_layout',
            $resolver->mapLayoutTypeToPageType('e2e_custom_layout')
        );
    }

    public function testProductsListingUriResolvesToProductListLayout(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            ThemeLayout::PAGE_TYPE_PRODUCT_LIST,
            $resolver->resolveLayoutTypeFromUri('/en_US/products/')
        );
        $this->assertSame(
            ThemeLayout::PAGE_TYPE_PRODUCT_LIST,
            $resolver->resolvePageTypeFromUri('/products')
        );
    }

    public function testLocalizedStorefrontMatrixKeepsDedicatedPageTypes(): void
    {
        $resolver = new ThemePageTypeResolver();
        $cases = [
            '/USD/en_US/' => ThemeLayout::PAGE_TYPE_HOME,
            '/en_US/USD/promotion/deals' => ThemeLayout::PAGE_TYPE_PROMOTION,
            '/ar_SA/USD/activity/autumn' => ThemeLayout::PAGE_TYPE_ACTIVITY,
            '/CNY/zh_Hans_CN/checkout/success' => ThemeLayout::PAGE_TYPE_CHECKOUT_SUCCESS,
            '/CNY/zh_Hans_CN/checkout/failer' => ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE,
            '/en_US/USD/checkout/failure' => ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE,
            '/en_US/faq' => ThemeLayout::PAGE_TYPE_FAQ,
            '/USD/en_US/guide/payment/alipay' => ThemeLayout::PAGE_TYPE_PAYMENT_GUIDE,
            '/en_US/USD/guide/shipping' => ThemeLayout::PAGE_TYPE_GUIDE,
            '/about' => ThemeLayout::PAGE_TYPE_ABOUT,
            '/contact' => ThemeLayout::PAGE_TYPE_CONTACT,
            '/qa' => ThemeLayout::PAGE_TYPE_QA,
            '/rma' => ThemeLayout::PAGE_TYPE_RMA,
            '/privacy' => ThemeLayout::PAGE_TYPE_POLICY,
            '/terms-and-conditions' => ThemeLayout::PAGE_TYPE_TERMS,
            '/not-found' => ThemeLayout::PAGE_TYPE_NOT_FOUND,
            '/dashboard' => ThemeLayout::PAGE_TYPE_DASHBOARD,
            '/page/hanfu-care' => 'cms',
        ];

        foreach ($cases as $uri => $expected) {
            self::assertSame($expected, $resolver->resolvePageTypeFromUri($uri), $uri);
        }
    }

    public function testLegacyCheckoutFailerAliasCanonicalizesToCheckoutFailure(): void
    {
        $resolver = new ThemePageTypeResolver();

        self::assertSame(
            'checkout_failer',
            $resolver->mapLayoutTypeToPageType('checkout_failer')
        );
        self::assertSame(
            ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE,
            $resolver->mapLayoutTypeToPageType(ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE)
        );
    }

    public function testUnknownContentUriUsesCmsFallbackWhenRequested(): void
    {
        $resolver = new ThemePageTypeResolver();

        self::assertSame(
            ThemeLayout::PAGE_TYPE_CMS,
            $resolver->resolvePageTypeFromUri('/editorial/hanfu-care', ThemeLayout::PAGE_TYPE_CMS)
        );
    }
}
