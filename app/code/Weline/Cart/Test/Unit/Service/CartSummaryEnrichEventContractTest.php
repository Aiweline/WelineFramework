<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CartSummaryEnrichEventContractTest extends TestCase
{
    public function testSummaryDispatchesEnrichEvent(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/Service/CartService.php');
        self::assertIsString($src);
        self::assertStringContainsString("Weline_Cart::cart_summary::enrich", $src);
        self::assertStringContainsString('enrichSummary', $src);
        self::assertStringContainsString("'tax_amount_minor'", $src);

        $events = include dirname(__DIR__, 3) . '/event.php';
        self::assertIsArray($events);
        self::assertArrayHasKey('Weline_Cart::cart_summary::enrich', $events);
    }
}
