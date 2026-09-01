<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionThemeFilterPreviewTest extends TestCase
{
    public function testServiceExposesPreviewFilterProducts(): void
    {
        $servicePath = dirname(__DIR__, 3) . '/Service/PromotionThemeProductService.php';
        $controllerPath = dirname(__DIR__, 3) . '/Controller/Backend/Theme.php';
        $themeServicePath = dirname(__DIR__, 3) . '/Service/PromotionActivityThemeService.php';
        $jsPath = dirname(__DIR__, 3) . '/view/statics/js/backend/promotion-theme-products.js';

        $service = (string) file_get_contents($servicePath);
        $controller = (string) file_get_contents($controllerPath);
        $themeService = (string) file_get_contents($themeServicePath);
        $script = (string) file_get_contents($jsPath);

        self::assertStringContainsString('function previewFilterProducts', $service);
        self::assertStringContainsString('$params[\'limit\'] = $limit;', $service);
        self::assertStringContainsString('一站一活动', $service);
        self::assertStringContainsString('一站一活动', $themeService);
        self::assertStringContainsString('defaultThemeWebsiteIds', $themeService);
        self::assertStringContainsString('postPreviewFilterProducts', $controller);
        self::assertStringContainsString('promotion-theme-filter-preview-table', $script);
        self::assertStringContainsString('promotion-theme-filter-preview__thumb', $script);
        self::assertStringContainsString('image_url', $script);
        self::assertStringContainsString('scopeRequired', $script);
        self::assertStringContainsString('website_raw', $script);
        self::assertStringContainsString('website_id < 0', $script);
        self::assertStringContainsString('Weline.UI.toast', $script);
        self::assertStringContainsString('failPreview', $script);
        self::assertStringNotContainsString('payload.website_id <= 0', $script);
        self::assertStringContainsString('page_size', $service);
        self::assertStringContainsString('page_count', $service);
        self::assertStringContainsString('array_slice', $service);
        self::assertStringContainsString('data-preview-page', $script);
        self::assertStringContainsString('{{total}}', $script);
        self::assertStringContainsString('promotion-theme-filter-preview-pager', $script);
    }
}
