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

        self::assertSame('account', $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_ACCOUNT));
        self::assertSame('account/login', $resolver->getFrontendUrlPathForPreview('account/login'));
        self::assertSame(
            'account/forgot-password',
            $resolver->getFrontendUrlPathForPreview('account/forgot-password')
        );
        self::assertSame('products', $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_PRODUCT_LIST));
        self::assertSame('product', $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_PRODUCT));
        self::assertSame('category', $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_CATEGORY));
        self::assertSame('/', $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_HOME));
        self::assertSame('/', $resolver->getPreviewPathByPageType(ThemeLayout::PAGE_TYPE_HOME));
        self::assertSame('/account', $resolver->getPreviewPathByPageType(ThemeLayout::PAGE_TYPE_ACCOUNT));
        self::assertStringNotContainsString(
            'theme-preview/content',
            $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_PRODUCT)
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
            'getFrontendUrlPathForPreview($pageType)',
            $source
        );
        // Must not pass empty homepage route into getFrontendUrl (query-bin REQUEST_URI leak).
        self::assertStringNotContainsString(
            'getPreviewRouteByPageType',
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
        self::assertStringNotContainsString(
            'theme/backend/theme-editor/layout-preview',
            $source
        );
        self::assertStringContainsString(
            "getBackendUrl('weline_dashboard/backend/dashboard'",
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
        self::assertSame('product', $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_PRODUCT));
    }

    public function testQueryBinPathsAreRejectedAsStorefrontPublicRoutes(): void
    {
        $resolver = new ThemePageTypeResolver();

        self::assertSame('', $resolver->normalizeStorefrontPublicRoute('framework/query-bin'));
        self::assertSame('', $resolver->normalizeStorefrontPublicRoute('/api/framework/query-bin'));
        self::assertSame('', $resolver->normalizeStorefrontPublicRoute('USD/zh_Hans_CN/framework/query-bin'));
        self::assertTrue($resolver->isNonStorefrontPublicRoute('framework/query-bin'));
        self::assertSame('/', $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_HOME));
        self::assertSame('promotion', $resolver->getFrontendUrlPathForPreview('promotion'));
    }

    public function testMarkupLeaksAreRejectedAsStorefrontPublicRoutes(): void
    {
        $resolver = new ThemePageTypeResolver();

        self::assertSame('', $resolver->normalizeStorefrontPublicRoute('<div    class='));
        self::assertSame('', $resolver->normalizeStorefrontPublicRoute('/%3cdiv%20%20%20%20class='));
        self::assertSame('', $resolver->normalizeStorefrontPublicRoute('<div class="x">'));
        self::assertFalse($resolver->isSafeStorefrontPublicRoute('<div    class='));
        self::assertTrue($resolver->isSafeStorefrontPublicRoute(''));
        self::assertTrue($resolver->isSafeStorefrontPublicRoute('product/benq-screenbar'));
        self::assertTrue($resolver->isSafeStorefrontPublicRoute('zh_Hans_CN/products'));
        self::assertSame('/', $resolver->getFrontendUrlPathForPreview(ThemeLayout::PAGE_TYPE_HOME));
    }
}
