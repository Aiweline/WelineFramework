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
        $url = ThemePreviewGenerator::getPreviewUrl(10, 'frontend', null, 0);

        self::assertStringContainsString('theme/frontend/theme-preview/gateway', $url);
        self::assertStringContainsString('preview_theme=10', $url);
        self::assertStringContainsString('preview_area=frontend', $url);
        self::assertStringContainsString('preview_mode=version', $url);
        self::assertStringContainsString('website_id=0', $url);
        self::assertStringContainsString('preview_gen=1', $url);
        self::assertStringContainsString('preview_exp=', $url);
        self::assertStringContainsString('preview_sig=', $url);
        self::assertStringNotContainsString('index/index', $url);
        self::assertStringNotContainsString('daocharms.com', $url);
        self::assertMatchesRegularExpression('#^https?://#i', $url);
    }

    public function testUnboundThemeCaptureFallsBackToWebsiteZeroAbsoluteBase(): void
    {
        $base = ThemePreviewGenerator::resolveCaptureBaseUrlForWebsite(0);
        self::assertNotNull($base);
        self::assertMatchesRegularExpression('#^https?://#i', (string)$base);
        self::assertStringNotContainsString('daocharms.com', (string)$base);

        $url = ThemePreviewGenerator::getPreviewUrl(10, 'frontend', $base, 0);
        self::assertStringStartsWith((string)$base, $url);
        self::assertStringContainsString('theme/frontend/theme-preview/gateway', $url);
        self::assertStringContainsString('preview_mode=version', $url);
        self::assertStringNotContainsString('://localhost/', $url);
    }

    public function testDaocharmsCaptureUsesInstallMountNotPublicDomain(): void
    {
        $base = ThemePreviewGenerator::resolveCaptureBaseUrlForWebsite(158, 'daocharms');
        if ($base === null) {
            self::markTestSkipped('website 158 not available in this environment');
        }
        self::assertStringContainsString('/daocharms', $base);
        self::assertStringNotContainsString('://daocharms.com', $base);

        $url = ThemePreviewGenerator::getPreviewUrl(99, 'frontend', $base, 158);
        self::assertStringStartsWith($base, $url);
        self::assertStringContainsString('website_id=158', $url);
        self::assertStringNotContainsString('://daocharms.com', $url);
    }

    public function testEnsureFrontendPreviewImageContractExists(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/ThemePreviewGenerator.php');
        self::assertStringContainsString('function ensureFrontendPreviewImage', $source);
        self::assertStringContainsString('InstallLocalStorefrontBaseResolver', $source);
        self::assertStringContainsString("'preview_mode' => 'version'", $source);

        $imageController = dirname(__DIR__, 2) . '/Controller/Backend/ThemePreview/Image.php';
        self::assertFileExists($imageController);
        $ctrl = (string)file_get_contents($imageController);
        self::assertStringContainsString('ensureFrontendPreviewImage', $ctrl);
        self::assertStringContainsString('website_id is required', $ctrl);
    }

    public function testNormalizeCaptureBaseUrlKeepsMountPath(): void
    {
        $normalized = ThemePreviewGenerator::normalizeCaptureBaseUrl('https://example.test/shop/');
        self::assertSame('https://example.test/shop', $normalized);
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

    public function testWebsiteScopedPreviewPathDoesNotFallBackToSharedPng(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/ThemePreviewGenerator.php');
        self::assertStringContainsString('_w{$websiteId}.png', $source);
        self::assertStringContainsString('resolveCaptureBaseUrlForWebsite', $source);
        self::assertStringContainsString('function getPreviewImagePublicUrl', $source);
        // Per-site cards must never paint the legacy shared PNG when the
        // website-scoped file is missing (grocery Host vs Hanfu Host).
        self::assertStringNotContainsString(
            'if (!is_file($abs) && $area === \'frontend\' && $websiteId !== null)',
            $source
        );

        $dir = PUB . ThemePreviewGenerator::PREVIEW_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $shared = ThemePreviewGenerator::getPreviewImagePath(99001, 'frontend', null);
        $scoped = ThemePreviewGenerator::getPreviewImagePath(99001, 'frontend', 544);
        @unlink($shared);
        @unlink($scoped);
        file_put_contents($shared, 'shared-only');
        try {
            self::assertStringEndsWith('_w544.png', $scoped);
            self::assertSame('', ThemePreviewGenerator::getPreviewImagePublicUrl(99001, 'frontend', 544));
            file_put_contents($scoped, 'scoped');
            $url = ThemePreviewGenerator::getPreviewImagePublicUrl(99001, 'frontend', 544);
            self::assertStringContainsString('theme_99001_frontend_w544.png', $url);
            self::assertStringNotContainsString('theme_99001_frontend.png?', $url);
        } finally {
            @unlink($shared);
            @unlink($scoped);
        }
    }
}
