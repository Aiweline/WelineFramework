<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Helper\CurrencySymbol;

final class CurrencySymbolTest extends TestCase
{
    public function testCommonCodesPreferGlyphsOverIsoCodes(): void
    {
        self::assertSame('$', CurrencySymbol::forCode('USD'));
        self::assertSame('£', CurrencySymbol::forCode('GBP'));
        self::assertSame('€', CurrencySymbol::forCode('EUR'));
        self::assertSame('¥', CurrencySymbol::forCode('CNY'));
        self::assertSame('¥', CurrencySymbol::forCode('JPY'));
        self::assertSame('A$', CurrencySymbol::forCode('AUD'));
    }

    public function testFormatAmountUsesGlyphWithoutTrailingIsoCode(): void
    {
        self::assertSame('$12.50', CurrencySymbol::formatAmount(12.5, 'USD'));
        self::assertSame('£23.33', CurrencySymbol::formatAmount(23.33, 'GBP'));
        self::assertStringNotContainsString('USD', CurrencySymbol::formatAmount(6.63, 'USD'));
        self::assertStringNotContainsString('GBP', CurrencySymbol::formatAmount(23.33, 'GBP'));
    }

    public function testClientMapIncludesConfiguredCurrencies(): void
    {
        $map = CurrencySymbol::clientMap();
        self::assertArrayHasKey('USD', $map);
        self::assertArrayHasKey('GBP', $map);
        self::assertSame('$', $map['USD']);
        self::assertSame('£', $map['GBP']);
    }
}
