<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\ThemePageTypeResolver;

/**
 * Path ↔ layout 1:1 — preview route equals layout (or explicit public route).
 * No PREVIEW_ROUTE tables / theme-preview/content encoding.
 */
final class ThemePreviewEntryApplicationRouteTest extends TestCase
{
    public function testPreviewRouteIsLayoutPathOneToOne(): void
    {
        $resolver = new ThemePageTypeResolver();

        self::assertSame('account', $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_ACCOUNT));
        self::assertSame('account', $resolver->getPreviewRouteByPageType('account'));
        self::assertSame('account/login', $resolver->getPreviewRouteByPageType('account/login'));
        self::assertSame(
            'account/forgot-password',
            $resolver->getPreviewRouteByPageType('account/forgot-password')
        );
        self::assertSame('products', $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_PRODUCT_LIST));
        self::assertSame('product', $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_PRODUCT));
        self::assertSame('category', $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_CATEGORY));
        self::assertSame('', $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_HOME));
        self::assertSame('/', $resolver->getPreviewPathByPageType(ThemeLayout::PAGE_TYPE_HOME));
        self::assertSame('/account', $resolver->getPreviewPathByPageType(ThemeLayout::PAGE_TYPE_ACCOUNT));
        self::assertStringNotContainsString(
            'theme-preview/content',
            $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_PRODUCT)
        );
    }

    public function testExplicitPublicRouteIsNeverRemapped(): void
    {
        $resolver = new ThemePageTypeResolver();

        self::assertSame(
            'account',
            $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_ACCOUNT, 'account')
        );
        self::assertSame(
            'customer/account/login',
            $resolver->getPreviewRouteByPageType('account/login', 'customer/account/login')
        );
        self::assertSame(
            'products',
            $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_PRODUCT_LIST, 'products')
        );
        self::assertSame(
            'product/benq-screenbar',
            $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_PRODUCT, 'product/benq-screenbar')
        );
    }

    public function testThemeEditorBuildFrontendPreviewUrlUsesTokenOnlyRoute(): void
    {
        $root = \dirname(__DIR__, 7);
        $source = (string) file_get_contents(
            $root . '/app/code/Weline/Theme/Controller/Backend/ThemeEditor.php'
        );

        self::assertStringContainsString(
            '$this->previewTokenService->getPreviewUrl($baseUrl, $token)',
            $source
        );
        self::assertStringContainsString(
            'getFrontendUrlPathForPreview($pageType, $publicRoute)',
            $source
        );
        // Must not pass empty homepage route into getFrontendUrl (query-bin REQUEST_URI leak).
        self::assertStringNotContainsString(
            'getPreviewRouteByPageType($pageType, $publicRoute)',
            $source
        );
    }

    public function testThemePreviewEntryApplicationUsesTokenOnlyRedirect(): void
    {
        $root = \dirname(__DIR__, 7);
        $source = (string) file_get_contents(
            $root . '/app/code/Weline/Theme/Service/ThemePreviewEntryApplication.php'
        );

        self::assertStringContainsString(
            '$previewTokenService->getPreviewUrl(',
            $source
        );
        self::assertStringContainsString(
            'getFrontendUrlPathForPreview($resolvedPageType)',
            $source
        );
        self::assertStringNotContainsString(
            "'theme/frontend/theme-preview/content'",
            $source
        );
    }

    public function testFrontendUrlPathForPreviewNeverPassesEmptyString(): void
    {
        $resolver = new ThemePageTypeResolver();

        self::assertSame('/', $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_HOME));
        self::assertSame('/', $resolver->getFrontendUrlPathForPreview('homepage'));
        self::assertSame('/', $resolver->getFrontendUrlPathForPreview('index/index'));
        self::assertSame('account', $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_ACCOUNT));
        self::assertSame(
            'product/benq-screenbar',
            $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_PRODUCT, 'product/benq-screenbar')
        );
    }

    public function testQueryBinPathsAreRejectedAsStorefrontPublicRoutes(): void
    {
        $resolver = new ThemePageTypeResolver();

        self::assertSame('', $resolver->normalizeStorefrontPublicRoute('framework/query-bin'));
        self::assertSame('', $resolver->normalizeStorefrontPublicRoute('/api/framework/query-bin'));
        self::assertSame('', $resolver->normalizeStorefrontPublicRoute('USD/zh_Hans_CN/framework/query-bin'));
        self::assertTrue($resolver->isNonStorefrontPublicRoute('framework/query-bin'));
        self::assertSame(
            '/',
            $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_HOME, 'framework/query-bin')
        );
        self::assertSame(
            'promotion/deals',
            $resolver->getFrontendUrlPathForPreview('promotion', 'promotion/deals')
        );
    }
}
