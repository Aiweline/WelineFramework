<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionStorefrontActiveDealResolverContractTest extends TestCase
{
    public function testResolverExposesActiveThemeDealApi(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionStorefrontActiveDealResolver.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);
        self::assertStringContainsString('function resolveForProduct(int $productId)', $content);
        self::assertStringContainsString('function applyToCatalogPrice(float $catalogPrice', $content);
        self::assertStringContainsString('listActiveThemesForStorefront', $content);
        self::assertStringContainsString('applyDealToPrice', $content);
    }

    public function testThemeServiceExposesStorefrontActiveThemeList(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionActivityThemeService.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);
        self::assertStringContainsString('function listActiveThemesForStorefront', $content);
    }
}
