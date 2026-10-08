<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ThemeApplicationUsageServiceContractTest extends TestCase
{
    public function testUsageServiceScansWebsiteApplicationsNotActivationFlags(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/ThemeApplicationUsageService.php';
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('frontendWebsiteUsageByThemeId', $source);
        self::assertStringContainsString('frontendWebsiteBindingsByThemeId', $source);
        self::assertStringContainsString('resolveEditWebsiteForTheme', $source);
        self::assertStringContainsString('themesForDefaultUpgrade', $source);
        self::assertStringContainsString('ThemeApplication', $source);
        self::assertStringContainsString('backendApplicationThemeId', $source);
        self::assertStringNotContainsString('is_active_frontend', $source);
        self::assertStringNotContainsString('getActiveTheme', $source);
    }

    public function testThemeManageListDropsActivationControls(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/backend/index.phtml';
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringNotContainsString('activate-btn', $source);
        self::assertStringNotContainsString('activateTheme', $source);
        self::assertStringContainsString('theme_website_usage', $source);
        self::assertStringContainsString('theme-website-usage', $source);
        self::assertStringContainsString('backend_application_theme_id', $source);
        self::assertStringContainsString("'website_id' => \$editWebsiteId", $source);
        self::assertStringContainsString("'website_code' => \$editWebsiteCode", $source);
        self::assertStringContainsString('theme_edit_websites', $source);
        self::assertStringContainsString('data-website-previews', $source);
        self::assertStringContainsString('data-theme-preview-nav', $source);
        self::assertStringContainsString('getPreviewImagePublicUrl', $source);
        self::assertStringContainsString('applyThemeCardPreviewImage', $source);
        self::assertStringContainsString('stepThemeCardWebsite', $source);
        self::assertStringContainsString('website_id:', $source);
        // Bound frontend cards must not fall back to legacy shared DB preview path.
        self::assertStringContainsString('never the legacy shared DB path', $source);
    }

    public function testThemeIndexControllerInjectsUsageNotActiveFlags(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Backend/Index.php';
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('ThemeApplicationUsageService', $source);
        self::assertStringContainsString('theme_website_usage', $source);
        self::assertStringContainsString('theme_edit_websites', $source);
        self::assertStringContainsString('frontendWebsiteBindingsByThemeId', $source);
        self::assertStringContainsString("order(WelineTheme::schema_fields_ID, 'DESC')", $source);
        self::assertStringNotContainsString('postActivate', $source);
        self::assertStringNotContainsString('IS_ACTIVE_FRONTEND', $source);
    }
}
