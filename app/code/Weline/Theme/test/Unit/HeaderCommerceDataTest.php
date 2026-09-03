<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\HeaderCommerceData;
use Weline\Theme\Service\AllMenu\AllMenuTreeRegistry;

final class HeaderCommerceDataTest extends TestCase
{
    protected function tearDown(): void
    {
        AllMenuTreeRegistry::reset();
        parent::tearDown();
    }
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
        self::assertNotEmpty($resolved['items']);
        self::assertSame('全部商品', (string)($resolved['items'][0]['text'] ?? ''));
        self::assertSame('/products', (string)($resolved['items'][0]['url'] ?? ''));
        self::assertSame([], $resolved['items'][0]['children'] ?? null);
    }

    public function testPrependProductsCatalogItemIsIdempotent(): void
    {
        $once = HeaderCommerceData::prependProductsCatalogItem([
            ['text' => '女装', 'url' => '/category/women', 'children' => []],
        ]);
        self::assertCount(2, $once);
        self::assertSame('全部商品', $once[0]['text']);
        self::assertSame('/products', $once[0]['url']);

        $twice = HeaderCommerceData::prependProductsCatalogItem($once);
        self::assertCount(2, $twice);
        self::assertSame('全部商品', $twice[0]['text']);
    }

    public function testAllProductsNavCanBeDisabledViaRegistry(): void
    {
        AllMenuTreeRegistry::publishAllProductsNav(false);
        $resolved = HeaderCommerceData::resolveCategoryNavItems();
        self::assertIsArray($resolved['items']);
        foreach ($resolved['items'] as $item) {
            self::assertNotSame('全部商品', (string)($item['text'] ?? ''));
            self::assertDoesNotMatchRegularExpression('#(^|/)products/?$#', (string)($item['url'] ?? ''));
        }

        $kept = HeaderCommerceData::maybePrependAllProductsItem(
            [['text' => '女装', 'url' => '/category/women', 'children' => []]],
            false,
        );
        self::assertCount(1, $kept);
        self::assertSame('女装', $kept[0]['text']);

        $forcedOff = HeaderCommerceData::resolveCategoryNavItems(['include_all_products' => false]);
        self::assertIsArray($forcedOff['items']);
        foreach ($forcedOff['items'] as $item) {
            self::assertNotSame('全部商品', (string)($item['text'] ?? ''));
        }
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
