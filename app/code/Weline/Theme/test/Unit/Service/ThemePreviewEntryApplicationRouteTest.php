<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\ThemePageTypeResolver;

final class ThemePreviewEntryApplicationRouteTest extends TestCase
{
    public function testHomepageLivePreviewUsesCanonicalStorefrontRoute(): void
    {
        $resolver = new ThemePageTypeResolver();

        self::assertSame(
            'index/index',
            $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_HOME)
        );
        self::assertStringNotContainsString(
            'theme-preview/content',
            $resolver->getPreviewRouteByPageType(ThemeLayout::PAGE_TYPE_HOME)
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
            '$this->getThemePageTypeResolver()->getPreviewRouteByPageType($pageType)',
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
        self::assertStringNotContainsString(
            "'theme/frontend/theme-preview/content'",
            $source
        );
    }
}
