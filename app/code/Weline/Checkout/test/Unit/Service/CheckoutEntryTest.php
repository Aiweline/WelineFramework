<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutEntry;

final class CheckoutEntryTest extends TestCase
{
    public function testNormalizeWhitelist(): void
    {
        self::assertSame(CheckoutEntry::CHECKOUT, CheckoutEntry::normalize('checkout'));
        self::assertSame(CheckoutEntry::EXPRESS, CheckoutEntry::normalize(' EXPRESS '));
        self::assertSame(CheckoutEntry::UNKNOWN, CheckoutEntry::normalize('forged'));
        self::assertSame(CheckoutEntry::CHECKOUT, CheckoutEntry::normalize('', CheckoutEntry::CHECKOUT));
    }

    public function testLabelsAreReadable(): void
    {
        self::assertNotSame('', CheckoutEntry::label(CheckoutEntry::CHECKOUT));
        self::assertNotSame('checkout', CheckoutEntry::label(CheckoutEntry::CHECKOUT));
        self::assertSame(CheckoutEntry::label(CheckoutEntry::UNKNOWN), CheckoutEntry::label('nope'));
    }

    public function testFilterRowsExcludeUnknown(): void
    {
        $codes = array_column(CheckoutEntry::filterRows(), 'code');
        self::assertContains(CheckoutEntry::CHECKOUT, $codes);
        self::assertContains(CheckoutEntry::EXPRESS, $codes);
        self::assertNotContains(CheckoutEntry::UNKNOWN, $codes);
    }
}
