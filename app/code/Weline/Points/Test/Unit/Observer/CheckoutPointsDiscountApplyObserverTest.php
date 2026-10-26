<?php

declare(strict_types=1);

namespace Weline\Points\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Points\Observer\CheckoutPointsDiscountApplyObserver;

final class CheckoutPointsDiscountApplyObserverTest extends TestCase
{
    private function amountMinor(array $session): int
    {
        $observer = new CheckoutPointsDiscountApplyObserver();
        $method = new \ReflectionMethod($observer, 'cartAmountMinor');
        $method->setAccessible(true);

        return (int) $method->invoke($observer, $session);
    }

    public function testTocUsesGrandTotalNotShipping(): void
    {
        self::assertSame(43773, $this->amountMinor([
            'cart_type' => 'toc',
            'totals' => ['grand_total_minor' => 43773],
            'quote' => ['amount_minor' => 238],
            'orders' => [],
        ]));
    }

    public function testToBUsesDepositWhenPresent(): void
    {
        self::assertSame(3000, $this->amountMinor([
            'cart_type' => 'tob',
            'deposit' => ['deposit_amount_minor' => 3000],
            'totals' => ['grand_total_minor' => 11500],
            'quote' => ['amount_minor' => 500],
        ]));
    }

    public function testFallsBackToItemsWhenTotalsMissing(): void
    {
        self::assertSame(5000, $this->amountMinor([
            'cart_type' => 'toc',
            'quote' => ['amount_minor' => 238],
            'orders' => [[
                'items' => [
                    ['qty_minor' => 2, 'unit_price_minor' => 2500],
                ],
            ]],
        ]));
    }
}
