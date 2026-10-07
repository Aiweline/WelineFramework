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
        self::assertSame('customer/account/login', $resolver->getFrontendUrlPathForPreview('account/login'));
        self::assertSame(
            'customer/account/forgot-password',
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

    public function testThemePreviewEntryApplicationRewritesFrontendBaseForWebsiteOrigin(): void
    {
        $root = \dirname(__DIR__, 7);
        $source = (string) file_get_contents(
            $root . '/app/code/Weline/Theme/Service/ThemePreviewEntryApplication.php'
        );
        $indexSource = (string) file_get_contents(
            $root . '/app/code/Weline/Theme/Controller/Backend/Index.php'
        );
        $listSource = (string) file_get_contents(
            $root . '/app/code/Weline/Theme/view/templates/backend/index.phtml'
        );

        self::assertStringContainsString('rewriteFrontendBaseForWebsite(', $source);
        self::assertStringContainsString('normalizeStorefrontPreviewBaseUrl(', $source);
        self::assertStringContainsString('stripBackendAreaMountFromUrl(', $source);
        self::assertStringContainsString("getAreaRoutePrefix('backend')", $source);
        self::assertStringContainsString('InstallLocalStorefrontBaseResolver', $source);
        self::assertStringContainsString('appendWebsiteQueryParams(', $source);
        self::assertStringContainsString("?int \$websiteId = null", $source);
        self::assertStringContainsString("?string \$websiteCode = null", $source);
        self::assertStringContainsString("getParam('website_id'", $indexSource);
        self::assertStringContainsString("getParam('website_code'", $indexSource);
        self::assertStringContainsString('$websiteId,', $indexSource);
        self::assertStringContainsString('$websiteCode,', $indexSource);
        self::assertStringContainsString("getPost('website_id'", $indexSource);
        self::assertStringContainsString('ensureFrontendPreviewImage', $indexSource);
        self::assertStringContainsString("'website_id' => \$websiteId", $indexSource);
        self::assertStringContainsString("'preview_mode' => 'version'", $listSource);
    }

    public function testNormalizeStorefrontPreviewBaseUrlStripsBackendMountAndRewritesOrigin(): void
    {
        $entry = new \Weline\Theme\Service\ThemePreviewEntryApplication(
            $this->createMock(\Weline\Theme\Service\ThemeContextService::class)
        );
        $backendMount = \trim((string)(\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''), '/');
        self::assertNotSame('', $backendMount, 'backend area mount must be configured for this contract');

        $adminFrontend = 'https://p05113ef3.test.weline.com/' . $backendMount . '/';
        $stripped = $entry->normalizeStorefrontPreviewBaseUrl($adminFrontend, null, null);
        self::assertSame('https://p05113ef3.test.weline.com/', $stripped);
        self::assertStringNotContainsString($backendMount, $stripped);

        $withPath = 'https://p05113ef3.test.weline.com/' . $backendMount . '/account';
        self::assertSame(
            'https://p05113ef3.test.weline.com/account',
            $entry->normalizeStorefrontPreviewBaseUrl($withPath, null, null)
        );

        $rewritten = $entry->normalizeStorefrontPreviewBaseUrl($adminFrontend, 544, 'grocery');
        self::assertStringNotContainsString($backendMount, $rewritten);
        self::assertStringNotContainsString('daocharms.com', $rewritten);
        try {
            $origin = \trim((string)(\Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\InstallLocalStorefrontBaseResolver::class
            )->resolveForWebsite(544, 'grocery') ?? ''));
        } catch (\Throwable) {
            $origin = '';
        }
        if ($origin !== '') {
            self::assertStringStartsWith(\rtrim($origin, '/'), $rewritten);
        }
    }

    public function testThemeEditorBuildFrontendPreviewUrlNormalizesStorefrontBase(): void
    {
        $root = \dirname(__DIR__, 7);
        $source = (string) file_get_contents(
            $root . '/app/code/Weline/Theme/Controller/Backend/ThemeEditor.php'
        );
        $js = (string) file_get_contents(
            $root . '/app/code/Weline/Theme/view/statics/js/theme-editor.js'
        );
        $siteBrand = (string) file_get_contents(
            $root . '/app/code/Weline/Theme/Helper/SiteBrand.php'
        );

        self::assertStringContainsString('normalizeStorefrontPreviewBaseUrl(', $source);
        self::assertStringContainsString('getStorefrontCanvasOrigin() !== window.location.origin', $js);
        self::assertStringContainsString('shouldUseBackendBrandIdentity()', $siteBrand);
        self::assertStringContainsString('resolveFrontendLogoUrl', $siteBrand);
    }

    public function testPreparePreviewRedirectGroceryOmitsBackendMount(): void
    {
        $theme = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Theme\Model\WelineTheme::class
        );
        $theme->load(7);
        $themeId = (int)$theme->getId();
        if ($themeId < 1 || \strtolower(\trim((string)$theme->getName())) !== 'grocery') {
            self::markTestSkipped('grocery theme id=7 not present in this environment');
        }

        $session = $this->createStub(\Weline\Framework\Session\Auth\AuthenticatedSessionInterface::class);
        $session->method('set')->willReturnCallback(static function (): void {
        });
        $session->method('get')->willReturn(null);

        $entry = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Theme\Service\ThemePreviewEntryApplication::class
        );
        $result = $entry->preparePreviewRedirect(
            $themeId,
            'frontend',
            $session,
            true,
            null,
            'homepage',
            null,
            'draft',
            'frontend',
            'version',
            544,
            'grocery',
        );
        self::assertTrue($result['ok'] ?? false, (string)($result['message'] ?? 'preview redirect failed'));
        $redirect = (string)($result['redirect'] ?? '');
        self::assertNotSame('', $redirect);
        $backendMount = \trim((string)(\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''), '/');
        self::assertNotSame('', $backendMount);
        self::assertStringNotContainsString('/' . $backendMount, $redirect);
        try {
            $origin = \trim((string)(\Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\InstallLocalStorefrontBaseResolver::class
            )->resolveForWebsite(544, 'grocery') ?? ''));
        } catch (\Throwable) {
            $origin = '';
        }
        self::assertStringNotContainsString('daocharms.com', $redirect);
        if ($origin !== '') {
            self::assertStringStartsWith(\rtrim($origin, '/'), $redirect);
        }
        self::assertStringContainsString('weline_preview_token=', $redirect);
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
