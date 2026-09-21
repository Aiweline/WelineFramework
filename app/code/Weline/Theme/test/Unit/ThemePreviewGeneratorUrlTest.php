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
        self::assertStringContainsString('preview_gen=1', $url);
        self::assertStringContainsString('preview_exp=', $url);
        self::assertStringContainsString('preview_sig=', $url);
        self::assertStringNotContainsString('index/index', $url);
    }

    public function testScreenshotCommandBoundsHeadlessWait(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/ThemePreviewGenerator.php');

        self::assertStringContainsString('capture-screenshot.mjs', $source);
        self::assertStringContainsString('SCREENSHOT_CHROME_TIMEOUT_MS = 24000', $source);
    }

    public function testCaptureSignatureRejectsAnotherThemeAndAnExpiredWindow(): void
    {
        $exp = time() + 60;
        $signature = ThemePreviewGenerator::signCapture(10, 'frontend', $exp);

        self::assertTrue(ThemePreviewGenerator::isValidCaptureSignature(10, 'frontend', $exp, $signature));
        self::assertFalse(ThemePreviewGenerator::isValidCaptureSignature(11, 'frontend', $exp, $signature));
        self::assertFalse(ThemePreviewGenerator::isValidCaptureSignature(10, 'backend', $exp, $signature));
        self::assertFalse(ThemePreviewGenerator::isValidCaptureSignature(10, 'frontend', time() - 30, $signature));
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

    public function testHttpErrorPageCannotBeAcceptedAsAThemePreview(): void
    {
        $method = new \ReflectionMethod(ThemePreviewGenerator::class, 'assertSuccessfulCaptureStatus');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('502');

        $method->invoke(null, 502, 'https://theme/theme/frontend/theme-preview/gateway');
    }
}
