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
        $resolve = new \ReflectionMethod(\Weline\Promotion\Service\PromotionStorefrontActiveDealResolver::class, 'resolveForProduct');
        self::assertSame(1, $resolve->getNumberOfRequiredParameters());
        self::assertTrue($resolve->getParameters()[1]->allowsNull());
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
        self::assertStringContainsString('function listHubStorefrontProductIds', $content);
    }
}
