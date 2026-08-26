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
}
