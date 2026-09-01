<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\HeaderCommerceData;

final class HeaderCommerceDataTest extends TestCase
{
    public function testDefaultHotWordsAreNonEmpty(): void
    {
        $words = HeaderCommerceData::defaultHotWords();
        self::assertNotEmpty($words);
        self::assertContains('iPhone', $words);
    }

    public function testFormatMoneyUsesCurrencySymbol(): void
    {
        self::assertSame('¥12.50', HeaderCommerceData::formatMoney(12.5, 'CNY'));
        self::assertSame('$12.50', HeaderCommerceData::formatMoney(12.5, 'USD'));
    }

    public function testDemoCartSummaryProvidesObservableChrome(): void
    {
        $demo = HeaderCommerceData::demoCartSummary();
        self::assertTrue($demo['is_demo']);
        self::assertFalse($demo['is_empty']);
        self::assertGreaterThan(0, $demo['cart_count']);
        self::assertNotSame('', $demo['subtotal_formatted']);
    }

    public function testResolveCategoryNavItemsContractShape(): void
    {
        $resolved = HeaderCommerceData::resolveCategoryNavItems();
        self::assertArrayHasKey('items', $resolved);
        self::assertArrayHasKey('source', $resolved);
        self::assertArrayHasKey('is_demo', $resolved);
        self::assertIsArray($resolved['items']);
        self::assertIsString($resolved['source']);
        self::assertFalse($resolved['is_demo']);
        self::assertStringNotContainsString('电子产品', json_encode($resolved['items'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testHeaderPrefersCatalogOverDemoNavFactory(): void
    {
        $header = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        $src = (string)file_get_contents($header);
        self::assertStringContainsString('HeaderCommerceData::resolveCategoryNavItems', $src);
        self::assertStringContainsString('AllMenuTreeRegistry::hasPublished()', $src);
        // Live path must not force demo factory when catalog is available.
        self::assertMatchesRegularExpression(
            '/empty\(\$navItems\).*resolveCategoryNavItems/s',
            $src
        );
    }
}
