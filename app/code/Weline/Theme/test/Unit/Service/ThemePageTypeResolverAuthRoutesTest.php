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
    public function testCustomerLoginUriResolvesToAccountDotAuth(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            'account.auth',
            $resolver->resolveLayoutTypeFromUri('https://shop.example/customer/account/login')
        );
    }

    public function testCustomerRegisterUriResolvesToAccountDotAuth(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            'account.auth',
            $resolver->resolveLayoutTypeFromUri('/customer/account/register')
        );
    }

    public function testCustomerForgotUriResolvesToAccountDotAuth(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            'account.auth',
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

    public function testProductPreviewRouteUsesThemeVisualContent(): void
    {
        $resolver = new ThemePageTypeResolver();
        $this->assertSame(
            'theme/frontend/theme-preview/content',
            $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_PRODUCT)
        );
        $this->assertStringNotContainsString(
            'product/default',
            $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_PRODUCT)
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
}
