<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePreviewGenerator;

final class ThemePreviewGeneratorUrlTest extends TestCase
{
    public function testFrontendPreviewGenerationUsesThemePreviewGateway(): void
    {
        $url = ThemePreviewGenerator::getPreviewUrl(10, 'frontend');

        self::assertStringContainsString('theme/frontend/theme-preview/gateway', $url);
        self::assertStringContainsString('preview_theme=10', $url);
        self::assertStringContainsString('preview_area=frontend', $url);
        self::assertStringNotContainsString('index/index', $url);
    }

    public function testAbsoluteThemePathsSupportAreaDetection(): void
    {
        $theme = new WelineTheme();
        $theme->setData(WelineTheme::schema_fields_ID, 123);
        $theme->setData(
            WelineTheme::schema_fields_PATH,
            dirname(__DIR__, 6) . '/app/code/Weline/Theme/view/theme'
        );

        $service = new ThemeContextService(new WelineTheme());

        self::assertTrue($service->themeSupportsArea($theme, ThemeContextService::AREA_FRONTEND));
        self::assertTrue($service->themeSupportsArea($theme, ThemeContextService::AREA_BACKEND));
    }
}
