<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\SlotBoundaryScanner;

final class SlotBoundaryDuplicatePreferShallowTest extends TestCase
{
    public function testPreferNonEmptyShallowBeatsEmptyNestedDuplicate(): void
    {
        $html = '<!--@weline-slot:product-express-payment-->'
            . '<div class="theme-layout-entity-slot" data-slot-id="product-express-payment">'
            . '<section data-testid="product-express-payment">Pay</section>'
            . '</div>'
            . '<!--@/weline-slot:product-express-payment-->'
            . '<!--@weline-slot:product-main-->'
            . '<div class="theme-layout-entity-slot" data-slot-id="product-main">'
            . '<!--@weline-slot:product-express-payment-->'
            . '<div data-wslot="product-express-payment">                </div>'
            . '<!--@/weline-slot:product-express-payment-->'
            . '</div>'
            . '<!--@/weline-slot:product-main-->';

        $scanner = new SlotBoundaryScanner();
        $deepest = $scanner->extractSlotInner($html, 'product-express-payment', true, false);
        self::assertNotNull($deepest);
        self::assertSame('', trim((string)$deepest));

        $solidified = $scanner->extractSlotInner($html, 'product-express-payment', false, true);
        self::assertNotNull($solidified);
        self::assertStringContainsString('data-testid="product-express-payment"', (string)$solidified);
        self::assertStringContainsString('Pay', (string)$solidified);
    }
}
