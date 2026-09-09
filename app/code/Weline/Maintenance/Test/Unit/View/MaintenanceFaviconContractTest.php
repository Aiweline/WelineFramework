<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\SiteBrand;

final class MaintenanceFaviconContractTest extends TestCase
{
    public function testStandaloneTemplateDeclaresFaviconLinks(): void
    {
        $template = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/maintenance.phtml'
        );

        self::assertStringContainsString('maintenance_favicon_url', $template);
        self::assertStringContainsString('rel="icon"', $template);
        self::assertStringContainsString('rel="shortcut icon"', $template);
        self::assertStringContainsString('rel="apple-touch-icon"', $template);
        self::assertStringContainsString('SiteBrand::DEFAULT_ICON_PUBLIC_PATH', $template);
    }

    public function testGeneratorResolvesSiteIconBeforeDefault(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/MaintenanceStaticGenerator.php'
        );

        self::assertStringContainsString('resolveMaintenanceBrandUrls', $source);
        self::assertStringContainsString('ThemeBrandResolver', $source);
        self::assertStringContainsString("'site_icon'", $source);
        self::assertStringContainsString('DEFAULT_ICON_PUBLIC_PATH', $source);
        self::assertStringContainsString('DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH', $source);
        self::assertStringContainsString('toPublicMediaOrStaticUrl', $source);
        self::assertStringContainsString('resolveFrontendLogoUrl', $source);
        self::assertStringContainsString('/Weline/Theme/view/theme/frontend/assets/images/theme/logo.png', $source);
        self::assertStringContainsString('resolveWebsiteScopedPublishedBrand', $source);
        self::assertStringContainsString('materializeMaintenanceFlagMarkup', $source);
        self::assertStringContainsString('CountryFlagMarkup', $source);
        self::assertStringContainsString('/pub/errors/maintenance/flags/', $source);
    }

    public function testStandaloneTemplateStylesLanguageFlagImages(): void
    {
        $template = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/maintenance.phtml'
        );

        self::assertStringContainsString('.language-flag-img', $template);
        self::assertStringContainsString('language-flag', $template);
    }

    public function testStandaloneTemplateUsesResolvedLogoUrl(): void
    {
        $template = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/maintenance.phtml'
        );

        self::assertStringContainsString('maintenance_logo_url', $template);
        self::assertStringContainsString('maintenance-brand__logo-bar', $template);
        self::assertStringContainsString('class="maintenance-logo"', $template);
    }

    public function testThemeDefaultIconPublicPathsAreStable(): void
    {
        self::assertSame(
            '/Weline/Theme/view/theme/frontend/assets/images/theme/icon.png',
            SiteBrand::DEFAULT_ICON_PUBLIC_PATH
        );
        self::assertSame(
            '/Weline/Theme/view/theme/frontend/assets/images/theme/apple-touch-icon.png',
            SiteBrand::DEFAULT_APPLE_TOUCH_ICON_PUBLIC_PATH
        );
        $iconFs = \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/assets/images/theme/icon.png';
        $appleFs = \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/assets/images/theme/apple-touch-icon.png';
        self::assertFileExists($iconFs);
        self::assertFileExists($appleFs);
    }
}
