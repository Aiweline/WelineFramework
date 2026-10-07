<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Cold homepage shelves must ask the catalog for the widget-configured limit,
 * not a *3 over-fetch that hydrates unused cards before SSR.
 */
final class HomepageShelfLimitFetchContractTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function shelfWidgets(): iterable
    {
        yield 'new-arrivals' => [
            'view/theme/frontend/widgets/product/new-arrivals/default.phtml',
            'newArrivalCards($limit, $days, false)',
        ];
        yield 'featured-products' => [
            'view/theme/frontend/widgets/product/featured-products/default.phtml',
            'homepageFeaturedCards($limit, $showRating)',
        ];
        yield 'bestsellers' => [
            'view/theme/frontend/widgets/product/bestsellers/default.phtml',
            'homepageHotCards($limit)',
        ];
        yield 'deals-of-day' => [
            'view/theme/frontend/widgets/product/deals-of-day/default.phtml',
            'homepageDealsCards($limit)',
        ];
    }

    /**
     * @dataProvider shelfWidgets
     */
    public function testShelfWidgetFetchesConfiguredLimitOnly(string $relativePath, string $needle): void
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString($needle, $source);
        self::assertStringNotContainsString('max($limit * 3', $source);
        self::assertStringNotContainsString('max($limit*3', $source);
    }

    public function testNewArrivalsFallbackAlsoUsesConfiguredLimit(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/product/new-arrivals/default.phtml';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('->cards($limit, false)', $source);
        self::assertStringNotContainsString('cards(max($limit', $source);
    }

    /**
     * @dataProvider shelfWidgets
     */
    public function testShelfWidgetRendersCardsInOneBatchProjection(string $relativePath, string $needle): void
    {
        unset($needle);
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('ProductCardRenderer::projectFromProducts($shelfProducts, $shelfCardOptions)', $source);
        self::assertStringNotContainsString('<w:product:card', $source);
    }
}
